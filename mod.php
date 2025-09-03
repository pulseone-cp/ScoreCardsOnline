<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'utils.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';
$roomHash = $_GET['room'] ?? '';
$modHash = $_GET['mod'] ?? '';
if ($roomHash === '') {
    http_response_code(400);
    echo 'Missing room parameter';
    exit;
}
if ($modHash === '') {
    http_response_code(403);
    echo 'Forbidden: missing moderator token';
    exit;
}
$db = db_get_connection();
$stmt = $db->prepare('SELECT id FROM rooms WHERE hash=? AND mod_hash=?');
$stmt->bind_param('ss', $roomHash, $modHash);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    http_response_code(403);
    echo 'Forbidden: invalid moderator token';
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Moderator - Score Cards</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <style>
    .card-badge { display: inline-block; min-width: 44px; padding: 6px 10px; border-radius: 8px; color: #fff; font-weight: 700; text-align: center; }
    .dist-row { align-items: center; margin-bottom: 8px; }
    .bar { height: 22px; border-radius: 5px; }
    .muted { opacity: 0.7; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
  </style>
</head>
<body>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h3 class="mb-0">Moderator View</h3>
      <div class="text-muted small">Room: <span class="mono" id="roomHash"></span> · <span id="roomName"></span></div>
    </div>
    <div class="d-flex gap-2">
      <a href="index.php" class="btn btn-outline-dark btn-sm" aria-label="Back to start page">Back to Start</a>
      <a id="participantLink" href="#" target="_blank" class="btn btn-outline-secondary btn-sm">Open participant link</a>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-12 col-lg-8">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="d-flex justify-content-between mb-2">
            <div>
              <div class="small text-muted">Participants</div>
              <div class="h4 mb-0" id="participants">0</div>
            </div>
            <div>
              <div class="small text-muted">Current Lap</div>
              <div class="h4 mb-0" id="lap">0</div>
            </div>
            <div>
              <div class="small text-muted">Votes</div>
              <div class="h4 mb-0" id="votes">0</div>
            </div>
            <div>
              <div class="small text-muted">Average</div>
              <div class="h4 mb-0" id="avg">–</div>
            </div>
          </div>
          <div class="mb-3">
            <button id="startLap" class="btn btn-primary">Start voting</button>
            <button id="nextLap" class="btn btn-success ms-2">Next lap</button>
            <button id="toggleVoting" class="btn btn-outline-warning ms-2" disabled>Stop voting</button>
          </div>
          <div id="distribution"></div>
        </div>
      </div>
    </div>
    <div class="col-12 col-lg-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <h6>Participant QR</h6>
          <img id="qr" alt="Join QR" width="240" height="240" class="border rounded mb-2">
          <div class="small text-muted">Link:</div>
          <div class="small"><a id="pLink" href="#" target="_blank"></a></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const room = <?php echo json_encode($roomHash); ?>;
const mod = <?php echo json_encode($modHash); ?>;
let canVoteState = false;
function buildParticipantUrl(){
  const url = new URL(window.location.href);
  url.pathname = url.pathname.replace(/mod\.php$/,'join.php');
  url.search = '?room=' + encodeURIComponent(room);
  return url.toString();
}

function refresh(){
  $.get('api.php', {action: 'get_status', room: room}, function(resp){
    if (!resp.ok) return;
    $('#roomHash').text(resp.room.hash);
    $('#roomName').text(resp.room.name);
    $('#participants').text(resp.participants);
    $('#lap').text(resp.lap);
    $('#votes').text(resp.votes_count);
    $('#avg').text(resp.average !== null ? resp.average : '–');

    // distribution bars
    const dist = resp.distribution;
    let maxCount = 0, total = 0;
    Object.values(dist).forEach(v=>{maxCount = Math.max(maxCount, v); total += v;});
    const container = $('#distribution');
    container.empty();
    resp.cards.forEach(card => {
      const count = dist[card.value] || 0;
      const pct = maxCount > 0 ? Math.round((count / maxCount) * 100) : 0;
      const row = $('<div class="row dist-row"></div>');
      row.append($('<div class="col-2 col-md-1"></div>').append($('<div class="card-badge"></div>').css('background', card.color).text(card.value)));
      const barOuter = $('<div class="col-8 col-md-9"><div class="bg-light w-100" style="border-radius:6px; overflow:hidden; border:1px solid #eee;"></div></div>');
      const bar = $('<div class="bar">&nbsp;</div>').css({width: pct+'%', background: card.color});
      barOuter.find('div').append(bar);
      row.append(barOuter);
      row.append($('<div class="col-2 text-end">'+count+'</div>'));
      container.append(row);
    });

    // Button states
    $('#startLap').prop('disabled', resp.lap > 0);
    canVoteState = resp.hasOwnProperty('can_vote') ? !!resp.can_vote : (resp.lap > 0);
    $('#toggleVoting').prop('disabled', resp.lap === 0);
    $('#toggleVoting').text(canVoteState ? 'Stop voting' : 'Resume voting').toggleClass('btn-outline-warning', canVoteState).toggleClass('btn-outline-success', !canVoteState);
  });
}

$(function(){
  const pUrl = buildParticipantUrl();
  $('#participantLink').attr('href', pUrl);
  $('#pLink').attr('href', pUrl).text(pUrl);
  $('#qr').attr('src', <?php echo json_encode(qr_url('')); ?> + encodeURIComponent(pUrl));

  $('#startLap').on('click', function(){
    $.post('api.php?action=start_lap', {room}, function(resp){ refresh(); });
  });
  $('#nextLap').on('click', function(){
    $.post('api.php?action=next_lap', {room}, function(resp){ refresh(); });
  });
  $('#toggleVoting').on('click', function(){
    const open = canVoteState ? 0 : 1; // if currently open -> close; else open
    $.post('api.php?action=set_voting', {room, mod, open}, function(resp){
      refresh();
    });
  });

  refresh();
  setInterval(refresh, 2000);
});
</script>
</body>
</html>