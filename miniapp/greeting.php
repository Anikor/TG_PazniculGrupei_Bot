<?php
// miniapp/greeting.php

// ————————————————————————————————————————————————
// 1 Bootstrap for Telegram Web App (no ?tg_id=… yet)
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
// 2 Main greeting page (we now have ?tg_id=…)
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

// 2) allow admin to override which group we’re viewing
if ($user['role'] === 'admin' && isset($_GET['group_id'])) {
    $override = intval($_GET['group_id']);
    if ($override > 0) {
        // pretend our user belongs to the override group
        $user['group_id'] = $override;
    }
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

     :root {
    --bg: #ffffff;
    --fg: #000000;
    --link-bg: #eee;
    --link-fg: #000;
    --btn-bg: #2a9df4;
    --btn-fg: #fff;
    --border: #ccc;
    --section-bg: #f5f5f5;
  }

  /* 2. Dark theme overrides */
  .dark-theme {
    --bg: #2b2d2f;
    --fg: #e2e2e4;
    --link-bg: #3b3f42;
    --link-fg: #e2e2e4;
    --btn-bg: #1a73e8;
    --btn-fg: #fff;
    --border: #444;
    --section-bg: #3b3f42;
  }

  /* 3. Apply variables */
  body {
    background-color: var(--bg);
    color: var(--fg);
  }
  a {
    color: var(--link-fg);
    background: var(--link-bg);
    padding: .4em .8em;
    border-radius: 4px;
    text-decoration: none;
    margin: 0 .4em .4em 0;
    display: inline-block;
  }
  .actions a, .btn-submit {
    background: var(--btn-bg);
    color: var(--btn-fg);
  }
  table, th, td {
    border-color: var(--border);
  }
  thead th {
    background: var(--section-bg);
  }

   .switch {
      position:relative;
      display:inline-block;
      width:50px;
      height:24px;
    }
    .switch input {
      opacity:0;
      width:0; height:0;
    }
    .slider {
      position:absolute;
      cursor:pointer;
      top:0; left:0; right:0; bottom:0;
      background-color:#ef5350;
      transition:.4s;
      border-radius:24px;
    }
    .slider:before {
      position:absolute;
      content:"";
      height:18px; width:18px;
      left:3px; bottom:3px;
      background:white;
      transition:.4s;
      border-radius:50%;
    }
    input:checked + .slider {
      background-color:#66bb6a;
    }
    input:checked + .slider:before {
      transform:translateX(26px);
    }

  </style>
</head>
<body>
<div class="theme-toggle">
  <label class="switch">
    <input type="checkbox" id="theme-toggle">
    <span class="slider"></span>
  </label>
  <span id="theme-label">Light</span>
</div>

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
      <a href="index.html?tg_id=<?= $tg_id ?>&when=<?= urlencode($when) ?>">
        📝 Log Attendance
      </a>
    <?php endif; ?>

    <a href="view_attendance.php?tg_id=<?= $tg_id ?>">
      📊 View My Attendance
    </a>

    <!-- only monitors may see this now -->
 <?php if ( in_array($user['role'], ['monitor','admin'], true) ): ?>
  <a
    href="view_group_attendance.php?tg_id=<?= urlencode($tg_id) ?>&group_id=<?= urlencode($user['group_id']) ?>"
    class="btn btn-primary"
  >
    👥 View Group Attendance
  </a>
  
<?php endif; ?>
<?php if (in_array($user['role'], ['admin','monitor'], true)): ?>
      <a href="export.php?tg_id=<?= $tg_id ?>&group_id=<?= $user['group_id'] ?>" class="btn btn-primary">
        📥 Export Attendance
      </a>
<?php if ($user['role'] === 'admin'): ?>
  <div class="btn-group" role="group" aria-label="Admin switch">
    <!-- Primary: log in as Karina in AI‑241 -->
    <a href="greeting.php?tg_id=348442139&group_id=2" class="btn btn-primary">
      Primary
    </a>
    <!-- Secondary: log in as the AI‑241 monitor/admin -->
    <a href="greeting.php?tg_id=878801928" class="btn btn-secondary">
      Secondary
    </a>
<?php endif; ?>


  </div>
<?php endif; ?>

  </div>

  <script>
  // 1 Grab toggle & label
  const toggle = document.getElementById('theme-toggle');
  const label  = document.getElementById('theme-label');

  // 2 Initialize from localStorage (default = light)
  const saved = localStorage.getItem('theme') || 'light';
  if (saved === 'dark') {
    document.body.classList.add('dark-theme');
    toggle.checked = true;
    label.textContent = 'Dark';
  }

  // 3 On toggle → switch class & save
  toggle.addEventListener('change', () => {
    if (toggle.checked) {
      document.body.classList.add('dark-theme');
      localStorage.setItem('theme','dark');
      label.textContent = 'Dark';
    } else {
      document.body.classList.remove('dark-theme');
      localStorage.setItem('theme','light');
      label.textContent = 'Light';
    }
  });
</script>
</body>
</html>
