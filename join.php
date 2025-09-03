<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'utils.php';
$roomHash = $_GET['room'] ?? '';
if ($roomHash === '') {
    http_response_code(400);
    echo 'Missing room parameter';
    exit;
}
$defaultName = random_funny_name();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Join - Score Cards</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <style>
    .card-btn {
      display: flex; align-items: center; justify-content: center;
      height: 100px; border-radius: 12px; color: #fff; font-weight: 800; font-size: 2rem; cursor: pointer;
      box-shadow: inset 0 -3px rgba(0,0,0,0.2);
      user-select: none;
    }
    .card-btn.disabled { filter: grayscale(30%); opacity: 0.6; cursor: not-allowed; }
    .selected-outline { outline: 4px solid #111; outline-offset: 3px; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }

    :root { --x: 0px; --y: 0px; --dx: 0px; --dy: 0px; }

    /* New Lap sparkle overlay */
    .lap-effect-overlay {
      position: fixed;
      inset: 0;
      pointer-events: none;
      z-index: 1050; /* above content */
      overflow: hidden;
      animation: overlay-fade 1.2s ease-out forwards;
    }
    @keyframes overlay-fade {
      0% { background: rgba(255,255,255,0.0); }
      10% { background: rgba(255,255,255,0.15); }
      100% { background: rgba(255,255,255,0.0); }
    }
    .sparkle {
      position: absolute;
      left: 0; top: 0;
      width: 8px; height: 8px;
      background: radial-gradient(circle, #fff 0%, #ffd54f 45%, rgba(255,255,255,0) 70%);
      border-radius: 50%;
      box-shadow: 0 0 10px rgba(255,215,64,0.9), 0 0 20px rgba(255,255,255,0.7);
      opacity: 0;
      transform: translate(-50%, -50%) scale(0.6) rotate(0deg);
      animation: sparkle-pop 900ms ease-out forwards;
    }
    @keyframes sparkle-pop {
      0%   { opacity: 0; transform: translate(var(--x, 0px), var(--y, 0px)) scale(0.2) rotate(0deg); }
      15%  { opacity: 1; }
      70%  { opacity: 1; }
      100% { opacity: 0; transform: translate(calc(var(--x, 0px) + var(--dx, 0px)), calc(var(--y, 0px) + var(--dy, 0px))) scale(1) rotate(180deg); }
    }
    @media (prefers-reduced-motion: reduce) {
      .lap-effect-overlay { animation: none; }
      .sparkle { animation-duration: 400ms; }
    }
  </style>
</head>
<body>
<div class="container py-4">
  <div class="mb-3">
    <h3 class="mb-0">Participant</h3>
    <div class="text-muted small">Room: <span class="mono"><?php echo htmlspecialchars($roomHash); ?></span></div>
  </div>

  <div id="joinCard" class="card shadow-sm mb-3">
    <div class="card-body">
      <h5 class="card-title">Join the room</h5>
      <div class="row g-2">
        <div class="col-12 col-md-8">
          <input id="name" class="form-control" placeholder="Your display name" value="<?php echo htmlspecialchars($defaultName); ?>">
        </div>
        <div class="col-12 col-md-4 d-grid d-md-flex">
          <button id="joinBtn" class="btn btn-primary w-100">Join</button>
        </div>
      </div>
      <div class="form-text">You can keep the suggested funny name or enter your own.</div>
    </div>
  </div>

  <div id="voteCard" class="card shadow-sm d-none">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div>
          <div class="small text-muted">You are</div>
          <div class="fw-bold" id="who"></div>
        </div>
        <div>
          <div class="small text-muted">Current lap</div>
          <div class="fw-bold" id="lap">0</div>
        </div>
        <a id="leaveLink" href="#" class="small text-danger ms-2" title="Leave this room">Leave room</a>
      </div>
      <div id="waiting" class="alert alert-info py-2 d-none">Waiting for the moderator to start the round…</div>
      <div id="paused" class="alert alert-warning py-2 d-none">Voting is paused by the moderator.</div>
      <div id="cards" class="row g-2"></div>
      <div id="voted" class="alert alert-success py-2 mt-3 d-none">Vote submitted. You can change it until the round ends.</div>
    </div>
  </div>
</div>

<script>
const room = <?php echo json_encode($roomHash); ?>;
let participantId = 0;
let myName = '';

function storageKey(k){ return 'sc_' + k + '_' + room; }

function loadIdentity(){
  const pid = localStorage.getItem(storageKey('participant'));
  const nm = localStorage.getItem(storageKey('name'));
  if (pid) participantId = parseInt(pid, 10) || 0;
  if (nm) myName = nm;
}

function saveIdentity(){
  localStorage.setItem(storageKey('participant'), String(participantId));
  localStorage.setItem(storageKey('name'), myName);
}

function loadPrevLap(){
  const pl = sessionStorage.getItem(storageKey('prev_lap'));
  if (pl !== null) previousLap = parseInt(pl, 10);
}

function renderCards(cards, my_vote, canVote){
  const c = $('#cards'); c.empty();
  cards.forEach(card => {
    const col = $('<div class="col-3 col-md-2"></div>');
    const btn = $('<div class="card-btn"></div>').css('background', card.color).text(card.value);
    if (!canVote) btn.addClass('disabled');
    if (my_vote === card.value) btn.addClass('selected-outline');
    btn.on('click', function(){
      if (!canVote) return;
      vote(card.value);
    });
    col.append(btn);
    c.append(col);
  });
}

// New lap effect: sparkle overlay and optional vibration
let previousLap = null;
function triggerNewLapEffect(){
  try {
    const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const overlay = document.createElement('div');
    overlay.className = 'lap-effect-overlay';
    const count = reduceMotion ? 12 : 28;
    const w = window.innerWidth || document.documentElement.clientWidth;
    const h = window.innerHeight || document.documentElement.clientHeight;
    for (let i = 0; i < count; i++) {
      const s = document.createElement('div');
      s.className = 'sparkle';
      const x = Math.random() * w;
      const y = Math.random() * h * 0.6; // top 60% of the screen
      const angle = Math.random() * Math.PI * 2;
      const dist = (reduceMotion ? 20 : 40) + Math.random() * (reduceMotion ? 30 : 80);
      const dx = Math.cos(angle) * dist;
      const dy = Math.sin(angle) * dist;
      s.style.setProperty('--x', x + 'px');
      s.style.setProperty('--y', y + 'px');
      s.style.setProperty('--dx', dx + 'px');
      s.style.setProperty('--dy', dy + 'px');
      s.style.animationDelay = (Math.random() * 120) + 'ms';
      overlay.appendChild(s);
    }
    document.body.appendChild(overlay);
    if (navigator.vibrate) { try { navigator.vibrate(reduceMotion ? 50 : [70, 40, 70]); } catch(e){} }
    setTimeout(function(){ if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay); }, reduceMotion ? 500 : 1400);
  } catch(e) { /* no-op */ }
}

function refresh(){
  const params = {action:'get_status', room: room};
  if (participantId) params.participant_id = participantId;
  $.get('api.php', params, function(resp){
    if (!resp.ok) return;
    const lapNum = parseInt(resp.lap, 10) || 0;
    if (previousLap === null) {
      previousLap = lapNum;
      sessionStorage.setItem(storageKey('prev_lap'), String(previousLap));
    } else if (lapNum > previousLap && lapNum > 0) {
      triggerNewLapEffect();
      previousLap = lapNum;
      sessionStorage.setItem(storageKey('prev_lap'), String(previousLap));
    } else if (lapNum !== previousLap) {
      previousLap = lapNum;
      sessionStorage.setItem(storageKey('prev_lap'), String(previousLap));
    }
    $('#lap').text(lapNum);
    $('#who').text(myName);
    const active = lapNum > 0;
    const canVote = resp.hasOwnProperty('can_vote') ? !!resp.can_vote : active;
    $('#waiting').toggleClass('d-none', active);
    $('#paused').toggleClass('d-none', !(active && !canVote));
    renderCards(resp.cards, resp.my_vote, canVote);
    $('#voted').toggleClass('d-none', resp.my_vote === null);
  });
}

function vote(value){
  if (!participantId) return;
  $.post('api.php?action=vote', {room, participant_id: participantId, value}, function(resp){
    refresh();
  });
}

function clearIdentity(){
  try { localStorage.removeItem(storageKey('participant')); } catch(e){}
  try { localStorage.removeItem(storageKey('name')); } catch(e){}
  try { sessionStorage.removeItem(storageKey('prev_lap')); } catch(e){}
  participantId = 0;
  myName = '';
}

function doLeave(){
  const finish = function(){
    clearIdentity();
    window.location.href = 'index.php';
  };
  if (participantId) {
    $.post('api.php?action=leave', {room, participant_id: participantId})
      .always(finish);
  } else {
    finish();
  }
}

function doJoin(){
  myName = $('#name').val().trim();
  if (myName === '') { alert('Please enter a name'); return; }
  $.post('api.php?action=join_room', {room, name: myName}, function(resp){
    if (!resp.ok) { alert(resp.error || 'Join failed'); return; }
    participantId = resp.participant_id;
    myName = resp.name;
    saveIdentity();
    $('#joinCard').addClass('d-none');
    $('#voteCard').removeClass('d-none');
    refresh();
  });
}

$(function(){
  loadIdentity();
  loadPrevLap();
  if (participantId) {
    // already joined
    $('#joinCard').addClass('d-none');
    $('#voteCard').removeClass('d-none');
  }
  if (myName) $('#name').val(myName);

  $('#joinBtn').on('click', doJoin);
  $('#leaveLink').on('click', function(e){ e.preventDefault(); doLeave(); });

  refresh();
  setInterval(refresh, 2000);
  setInterval(function(){ if (participantId) $.post('api.php?action=ping', {participant_id: participantId}); }, 30000);
});
</script>
</body>
</html>