<?php
// miniapp/greeting.php

// ————————————————————————————————————————————————
// 1️⃣ Bootstrap for Telegram Web App (no ?tg_id=… yet)
// ————————————————————————————————————————————————
if (!isset($_GET['tg_id'])): ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Loading…</title>
  <script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>
<script>
  // Wait for Telegram WebApp to initialize
  const tg = window.Telegram.WebApp;
  tg.expand();  // optional: make full-screen

  // Grab user ID
  const user = tg.initDataUnsafe && tg.initDataUnsafe.user;
  if (!user) {
    document.body.innerHTML = '<p style="color:red">Error: cannot detect Telegram user ID.</p>';
  } else {
    // Reload with ?tg_id=<your_id>
    const url = new URL(location.href);
    url.searchParams.set('tg_id', user.id);
    location.replace(url.toString());
  }
</script>
</body>
</html>
<?php
  exit;
endif;

// ————————————————————————————————————————————————
// 2️⃣ Main greeting page (we now have ?tg_id=…)
// ————————————————————————————————————————————————
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

// Identify user
$tg_id = intval($_GET['tg_id']);
$user  = getUserByTgId($tg_id);
if (!$user) {
    http_response_code(400);
    exit('Error: invalid or unregistered Telegram ID');
}

// Choose which date range to load
$when = $_GET['when'] ?? 'today';
switch ($when) {
    case 'yesterday':
        $date     = date('Y-m-d', strtotime('-1 day'));
        $label    = 'Yesterday (' . date('d M Y', strtotime('-1 day')) . ')';
        $schedule = getScheduleForDate($tg_id, $date);
        break;
    case 'tomorrow':
        $date     = date('Y-m-d', strtotime('+1 day'));
        $label    = 'Tomorrow (' . date('d M Y', strtotime('+1 day')) . ')';
        $schedule = getScheduleForDate($tg_id, $date);
        break;
    case 'week':
        $label    = 'This Week';
        $schedule = getWeekSchedule($tg_id);
        break;
    case 'today':
    default:
        $date     = date('Y-m-d');
        $label    = 'Today (' . date('d M Y') . ')';
        $schedule = getScheduleForDate($tg_id, $date);
        break;
}

// Helpers for weekly grid
$dayLabels = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
$timeSlots = array_unique(array_column($schedule, 'time_slot'));
usort($timeSlots, fn($a,$b)=>
    strtotime(explode('-',$a)[0]) - strtotime(explode('-',$b)[0])
);
$grid = [];
if ($when === 'week') {
  foreach ($schedule as $row) {
    $grid[$row['time_slot']][$row['day_of_week']] = [
      'type'     => $row['type'],
      'subject'  => $row['subject'],
      'location' => $row['location']
    ];
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Welcome</title>
  <style>
    body { font-family:sans-serif; padding:1rem; }
    h1 { margin-bottom:.2em; }
    .role { color:#555; margin-bottom:1em; }
    .nav-buttons a {
      margin:0 .5em .5em 0;
      padding:.5em 1em;
      background:#eee; border-radius:4px;
      text-decoration:none; color:#333;
    }
    table { width:100%; border-collapse:collapse; margin-top:1em; }
    th, td {
      border:1px solid #ccc; padding:.5em;
      text-align:center; vertical-align:top;
    }
    thead th { background:#f5f5f5; }
    .actions a {
      display:inline-block; margin-right:1em; margin-top:1em;
      padding:.6em 1.2em; background:#2a9df4;
      color:white; text-decoration:none; border-radius:4px;
    }
  </style>
</head>
<body>

  <h1>Hello, <?= htmlspecialchars($user['name'], ENT_QUOTES) ?>!</h1>
  <div class="role">
    Role: <strong><?= htmlspecialchars(ucfirst($user['role']), ENT_QUOTES) ?></strong>
  </div>

  <div class="nav-buttons">
    <a href="?tg_id=<?= $tg_id ?>&when=yesterday">← Yesterday</a>
    <a href="?tg_id=<?= $tg_id ?>&when=today">Today</a>
    <a href="?tg_id=<?= $tg_id ?>&when=tomorrow">Tomorrow →</a>
    <a href="?tg_id=<?= $tg_id ?>&when=week">This Week</a>
  </div>

  <h2><?= $label ?>’s Schedule</h2>

  <?php if (empty($schedule)): ?>
    <p>No classes scheduled.</p>

  <?php elseif ($when === 'week'): ?>
    <table>
      <thead>
        <tr>
          <th>Time / Day</th>
          <?php foreach ($dayLabels as $day): ?>
            <th><?= htmlspecialchars($day, ENT_QUOTES) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($timeSlots as $slot): ?>
          <tr>
            <td><?= htmlspecialchars($slot, ENT_QUOTES) ?></td>
            <?php foreach ($dayLabels as $day): ?>
              <td>
                <?php if (!empty($grid[$slot][$day])): 
                  $c = $grid[$slot][$day]; ?>
                  <?= htmlspecialchars($c['type'],ENT_QUOTES) ?>. 
                  <?= htmlspecialchars($c['subject'],ENT_QUOTES) ?><br>
                  <small><?= htmlspecialchars($c['location'],ENT_QUOTES) ?></small>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

  <?php else: ?>
    <table>
      <thead>
        <tr><th>Time</th><th>Type</th><th>Subject</th><th>Location</th></tr>
      </thead>
      <tbody>
        <?php foreach ($schedule as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['time_slot'],ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($row['type'],ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($row['subject'],ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($row['location'],ENT_QUOTES) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="actions">
    <?php if (in_array($user['role'], ['admin','monitor','moderator'])): ?>
      <a href="index.html?tg_id=<?= $tg_id ?>">📝 Log Attendance</a>
    <?php endif; ?>
    <a href="view_attendance.php?tg_id=<?= $tg_id ?>">📊 View My Attendance</a>
  </div>

</body>
</html>
