<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'utils.php';

$db = db_get_connection();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function get_room_by_hash(mysqli $db, string $hash) {
    $stmt = $db->prepare('SELECT * FROM rooms WHERE hash=?');
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res->fetch_assoc();
}

function current_lap(mysqli $db, int $room_id) {
    $stmt = $db->prepare('SELECT l.* FROM laps l WHERE l.room_id=? AND l.ended_at IS NULL ORDER BY l.number DESC LIMIT 1');
    $stmt->bind_param('i', $room_id);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res->fetch_assoc();
}

function last_lap_number(mysqli $db, int $room_id): int {
    $stmt = $db->prepare('SELECT COALESCE(MAX(number),0) as n FROM laps WHERE room_id=?');
    $stmt->bind_param('i', $room_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    return (int)$row['n'];
}

function base_url(): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('api.php', '', $_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    return $protocol . '://' . $host . $dir . '/';
}

switch ($action) {
    case 'create_room': {
        $min = isset($_POST['min']) ? (int)$_POST['min'] : 0;
        $max = isset($_POST['max']) ? (int)$_POST['max'] : 5;
        if (!in_array($min, [0,1], true)) $min = 0;
        if ($max < 1 || $max > 9) $max = 5;
        if ($min > $max) $min = 0;

        $hash = random_room_hash();
        $name = random_room_name();
        $mod_hash = random_mod_hash();

        $stmt = $db->prepare('INSERT INTO rooms (hash, mod_hash, name, card_min, card_max) VALUES (?,?,?,?,?)');
        $stmt->bind_param('sssii', $hash, $mod_hash, $name, $min, $max);
        $stmt->execute();
        $room_id = $stmt->insert_id;

        $mod_url = base_url() . 'mod.php?room=' . urlencode($hash) . '&mod=' . urlencode($mod_hash);
        $join_url = base_url() . 'join.php?room=' . urlencode($hash);

        json_response([
            'ok' => true,
            'room' => [
                'hash' => $hash,
                'name' => $name,
                'card_min' => $min,
                'card_max' => $max,
            ],
            'urls' => [
                'moderator' => $mod_url,
                'participant' => $join_url,
            ]
        ]);
        break;
    }
    case 'join_room': {
        $hash = $_POST['room'] ?? '';
        $name = trim($_POST['name'] ?? '');
        if ($hash === '') json_response(['ok' => false, 'error' => 'missing room'], 400);
        $room = get_room_by_hash($db, $hash);
        if (!$room) json_response(['ok' => false, 'error' => 'room not found'], 404);
        if ($name === '') $name = random_funny_name();

        $stmt = $db->prepare('INSERT INTO participants (room_id, name, is_moderator) VALUES (?,?,0)');
        $stmt->bind_param('is', $room['id'], $name);
        $stmt->execute();
        $pid = $stmt->insert_id;

        json_response(['ok' => true, 'participant_id' => $pid, 'name' => $name]);
        break;
    }
    case 'start_lap': {
        $hash = $_POST['room'] ?? '';
        $room = get_room_by_hash($db, $hash);
        if (!$room) json_response(['ok' => false, 'error' => 'room not found'], 404);

        $curr = current_lap($db, (int)$room['id']);
        if ($curr) {
            // already active; no-op
            json_response(['ok' => true, 'lap' => $curr['number']]);
        }
        $n = last_lap_number($db, (int)$room['id']) + 1;
        $stmt = $db->prepare('INSERT INTO laps (room_id, number) VALUES (?,?)');
        $stmt->bind_param('ii', $room['id'], $n);
        $stmt->execute();
        $lap_id = $stmt->insert_id;
        $stmt2 = $db->prepare('UPDATE rooms SET current_lap_id=? WHERE id=?');
        $stmt2->bind_param('ii', $lap_id, $room['id']);
        $stmt2->execute();
        json_response(['ok' => true, 'lap' => $n]);
        break;
    }
    case 'next_lap': {
        $hash = $_POST['room'] ?? '';
        $room = get_room_by_hash($db, $hash);
        if (!$room) json_response(['ok' => false, 'error' => 'room not found'], 404);

        $curr = current_lap($db, (int)$room['id']);
        if ($curr) {
            $stmt = $db->prepare('UPDATE laps SET ended_at=NOW() WHERE id=?');
            $stmt->bind_param('i', $curr['id']);
            $stmt->execute();
        }
        $n = last_lap_number($db, (int)$room['id']) + 1;
        $stmt2 = $db->prepare('INSERT INTO laps (room_id, number) VALUES (?,?)');
        $stmt2->bind_param('ii', $room['id'], $n);
        $stmt2->execute();
        $lap_id = $stmt2->insert_id;
        $stmt3 = $db->prepare('UPDATE rooms SET current_lap_id=? WHERE id=?');
        $stmt3->bind_param('ii', $lap_id, $room['id']);
        $stmt3->execute();
        json_response(['ok' => true, 'lap' => $n]);
        break;
    }
    case 'get_status': {
        $hash = $_GET['room'] ?? $_POST['room'] ?? '';
        $participant_id = isset($_GET['participant_id']) ? (int)$_GET['participant_id'] : (isset($_POST['participant_id']) ? (int)$_POST['participant_id'] : 0);
        $room = get_room_by_hash($db, $hash);
        if (!$room) json_response(['ok' => false, 'error' => 'room not found'], 404);

        // participant count
        $stmt = $db->prepare('SELECT COUNT(*) c FROM participants WHERE room_id=?');
        $stmt->bind_param('i', $room['id']);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $participants_count = (int)$res['c'];

        $curr = current_lap($db, (int)$room['id']);
        $lap_number = $curr ? (int)$curr['number'] : 0;
        $votes_count = 0;
        $distribution = [];
        $avg = null;
        $min = (int)$room['card_min'];
        $max = (int)$room['card_max'];
        for ($v=$min; $v<=$max; $v++) $distribution[(string)$v] = 0;
        $my_vote = null;

        if ($curr) {
            // distribution
            $stmt2 = $db->prepare('SELECT value, COUNT(*) c FROM votes WHERE lap_id=? GROUP BY value');
            $stmt2->bind_param('i', $curr['id']);
            $stmt2->execute();
            $rs2 = $stmt2->get_result();
            while ($row = $rs2->fetch_assoc()) {
                $distribution[(string)$row['value']] = (int)$row['c'];
                $votes_count += (int)$row['c'];
            }
            // avg
            $stmt3 = $db->prepare('SELECT AVG(value) a FROM votes WHERE lap_id=?');
            $stmt3->bind_param('i', $curr['id']);
            $stmt3->execute();
            $avgRow = $stmt3->get_result()->fetch_assoc();
            $avg = $avgRow && $avgRow['a'] !== null ? round((float)$avgRow['a'], 2) : null;

            if ($participant_id) {
                $stmt4 = $db->prepare('SELECT value FROM votes WHERE lap_id=? AND participant_id=?');
                $stmt4->bind_param('ii', $curr['id'], $participant_id);
                $stmt4->execute();
                $mv = $stmt4->get_result()->fetch_assoc();
                if ($mv) $my_vote = (int)$mv['value'];
            }
        }

        // build card metadata with colors
        $colors = fixed_card_colors();
        $cards = [];
        for ($v=$min; $v<=$max; $v++) {
            $cards[] = [
                'value' => $v,
                'color' => $colors[$v]
            ];
        }

        // derive can_vote: active lap and (voting_open missing => true; otherwise voting_open==1)
        $can_vote = false;
        if ($curr) {
            $can_vote = !isset($curr['voting_open']) || (int)$curr['voting_open'] === 1;
        }

        json_response([
            'ok' => true,
            'room' => [
                'hash' => $room['hash'],
                'name' => $room['name'],
                'card_min' => $min,
                'card_max' => $max,
            ],
            'participants' => $participants_count,
            'lap' => $lap_number,
            'votes_count' => $votes_count,
            'distribution' => $distribution,
            'average' => $avg,
            'my_vote' => $my_vote,
            'cards' => $cards,
            'can_vote' => $can_vote
        ]);
        break;
    }
    case 'vote': {
        $hash = $_POST['room'] ?? '';
        $participant_id = isset($_POST['participant_id']) ? (int)$_POST['participant_id'] : 0;
        $value = isset($_POST['value']) ? (int)$_POST['value'] : null;
        if (!$hash || !$participant_id || $value === null) json_response(['ok' => false, 'error' => 'missing params'], 400);
        $room = get_room_by_hash($db, $hash);
        if (!$room) json_response(['ok' => false, 'error' => 'room not found'], 404);
        $min = (int)$room['card_min'];
        $max = (int)$room['card_max'];
        if ($value < $min || $value > $max) json_response(['ok' => false, 'error' => 'value out of range'], 400);

        // check participant belongs to room
        $stmt0 = $db->prepare('SELECT id FROM participants WHERE id=? AND room_id=?');
        $stmt0->bind_param('ii', $participant_id, $room['id']);
        $stmt0->execute();
        if (!$stmt0->get_result()->fetch_assoc()) json_response(['ok' => false, 'error' => 'participant not in room'], 400);

        $curr = current_lap($db, (int)$room['id']);
        if (!$curr) json_response(['ok' => false, 'error' => 'no active lap'], 400);
        // check if voting is open (if column present). If not present, assume open.
        if (isset($curr['voting_open']) && (int)$curr['voting_open'] === 0) {
            json_response(['ok' => false, 'error' => 'voting closed'], 400);
        }

        // Upsert vote
        $stmt = $db->prepare('INSERT INTO votes (lap_id, participant_id, value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value), created_at=NOW()');
        $stmt->bind_param('iii', $curr['id'], $participant_id, $value);
        $stmt->execute();

        json_response(['ok' => true]);
        break;
    }
    case 'set_voting': {
        $hash = $_POST['room'] ?? '';
        $mod = $_POST['mod'] ?? '';
        $open = isset($_POST['open']) ? (int)$_POST['open'] : null;
        if ($hash === '' || $mod === '' || $open === null) json_response(['ok' => false, 'error' => 'missing params'], 400);
        $room = get_room_by_hash($db, $hash);
        if (!$room) json_response(['ok' => false, 'error' => 'room not found'], 404);
        if (!isset($room['mod_hash']) || $room['mod_hash'] !== $mod) json_response(['ok' => false, 'error' => 'forbidden'], 403);
        $curr = current_lap($db, (int)$room['id']);
        if (!$curr) json_response(['ok' => false, 'error' => 'no active lap'], 400);
        $openVal = $open ? 1 : 0;
        // If column is missing, silently accept but no-op
        $stmt = $db->prepare('UPDATE laps SET voting_open=? WHERE id=?');
        if ($stmt) {
            $stmt->bind_param('ii', $openVal, $curr['id']);
            $stmt->execute();
        }
        json_response(['ok' => true, 'can_vote' => $openVal === 1]);
        break;
    }
    case 'list_cards': {
        $hash = $_GET['room'] ?? '';
        $room = get_room_by_hash($db, $hash);
        if (!$room) json_response(['ok' => false, 'error' => 'room not found'], 404);
        $min = (int)$room['card_min'];
        $max = (int)$room['card_max'];
        $colors = fixed_card_colors();
        $cards = [];
        for ($v=$min; $v<=$max; $v++) $cards[] = ['value' => $v, 'color' => $colors[$v]];
        json_response(['ok' => true, 'cards' => $cards]);
        break;
    }
    case 'ping': {
        $participant_id = isset($_POST['participant_id']) ? (int)$_POST['participant_id'] : 0;
        if ($participant_id) {
            $stmt = $db->prepare('UPDATE participants SET last_seen=NOW() WHERE id=?');
            $stmt->bind_param('i', $participant_id);
            $stmt->execute();
        }
        json_response(['ok' => true]);
        break;
    }
    default:
        json_response(['ok' => false, 'error' => 'unknown action'], 400);
}
