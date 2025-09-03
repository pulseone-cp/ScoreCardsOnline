<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'utils.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Score Cards - Create Room</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>
<body class="bg-light">
<div class="container py-5">
  <h1 class="mb-3">Score Cards</h1>
  <p class="text-muted">A lightweight, self-hosted tool for quick estimation rounds with colored cards. No logins or installs — just create a room and share a link or QR code.</p>
  <p class="mt-2">
    <span class="badge bg-secondary me-2">Hey, I'm open source</span>
    <a href="https://github.com/pulseone-cp/ScoreCardsOnline" target="_blank" rel="noopener noreferrer">View this project on GitHub</a>
  </p>

  <div class="row mb-4">
    <div class="col-12 col-lg-7 mb-3 mb-lg-0">
      <div class="alert alert-info mb-0">
        <div class="fw-semibold mb-1">How it works</div>
        <ul class="mb-0 ps-3">
          <li>A moderator creates a room and gets two links: participant and moderator.</li>
          <li>Participants open the link (or scan the QR) and tap a colored card to vote.</li>
          <li>The moderator starts/pauses rounds and sees live distribution and average.</li>
        </ul>
      </div>
    </div>
    <div class="col-12 col-lg-5">
      <div class="card border-success">
        <div class="card-body py-3">
          <div class="fw-semibold text-success mb-2">Getting started</div>
          <ol class="mb-0 ps-3">
            <li>Choose the card range below and create a room.</li>
            <li>Share the participant link or QR with your team.</li>
            <li>Open the moderator view to start the first lap and manage voting.</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <h5 class="card-title">New Room</h5>
      <form id="createForm" class="row gy-3">
        <div class="col-12 col-md-4">
          <label for="min" class="form-label">Minimum card</label>
          <select id="min" name="min" class="form-select">
            <option value="0" selected>0</option>
            <option value="1">1</option>
          </select>
        </div>
        <div class="col-12 col-md-4">
          <label for="max" class="form-label">Maximum card</label>
          <select id="max" name="max" class="form-select">
            <?php for($i=1;$i<=9;$i++): ?>
              <option value="<?php echo $i; ?>" <?php echo $i===5? 'selected':''; ?>><?php echo $i; ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="col-12 col-md-4 d-flex align-items-end">
          <button type="submit" class="btn btn-primary w-100">Create Room</button>
        </div>
      </form>

      <div id="result" class="mt-4 d-none">
        <h6>Room Created: <span id="roomName"></span></h6>
        <div class="row g-3 align-items-center">
          <div class="col-auto">
            <img id="qr" alt="Join QR" width="200" height="200" class="border rounded">
          </div>
          <div class="col">
            <div class="mb-2">
              <div class="small text-muted">Participant link</div>
              <a id="participantLink" href="#" target="_blank"></a>
            </div>
            <div>
              <div class="small text-muted">Moderator link</div>
              <a id="moderatorLink" href="#" target="_blank">Open moderator view</a>
            </div>
            <div class="mt-3">
              <a id="goModerate" class="btn btn-success" href="#">Open Moderator View</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
$(function(){
  $('#createForm').on('submit', function(e){
    e.preventDefault();
    const min = parseInt($('#min').val(), 10);
    const max = parseInt($('#max').val(), 10);
    if (min > max) { alert('Minimum cannot exceed maximum'); return; }
    $.post('api.php?action=create_room', {min, max}, function(resp){
      if (!resp.ok) { alert(resp.error || 'Failed to create room'); return; }
      $('#result').removeClass('d-none');
      $('#roomName').text(resp.room.name + ' (' + resp.room.hash + ')');
      $('#participantLink').attr('href', resp.urls.participant).text(resp.urls.participant);
      $('#moderatorLink').attr('href', resp.urls.moderator).text('Open moderator view');
      $('#goModerate').attr('href', resp.urls.moderator);
      $('#qr').attr('src', '<?php echo qr_url(''); ?>' + encodeURIComponent(resp.urls.participant));
    });
  });
});
</script>
</body>
</html>