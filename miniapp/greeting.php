<?php
// miniapp/greeting.php

// ————————————————————————————————————————————————
// 1) Bootstrap for Telegram Web App (no ?tg_id yet)
// ————————————————————————————————————————————————
if (!isset($_GET['tg_id'])) {
    ?><!DOCTYPE html>
    <html lang="en"><head><meta charset="UTF-8"><title>Loading…</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script></head>
    <body><script>
      const tg = window.Telegram.WebApp; tg.expand();
      const user = tg.initDataUnsafe && tg.initDataUnsafe.user;
      if (!user) {
        document.body.innerHTML = '<p style="color:red">Error: cannot detect Telegram user ID.</p>';
      } else {
        const u = new URL(location.href);
        u.searchParams.set('tg_id', user.id);
        location.replace(u.toString());
      }
    </script></body></html><?php
    exit;
}

// ————————————————————————————————————————————————
// 2) Main greeting page (we now have ?tg_id)
// ————————————————————————————————————————————————
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/oe_weeks.php';

// Identify user
$tg_id = intval($_GET['tg_id']);
$user  = getUserByTgId($tg_id);
if (!$user) {
    http_response_code(400);
    exit('Error: invalid or unregistered Telegram ID');
}

// Compute odd/even week and subgroup
$weekType = getCurrentWeekType();      // 'odd' or 'even'
$subgroup = $user['subgroup'] ?? null; // 1, 2, or NULL

// Admin override group?
if ($user['role'] === 'admin' && isset($_GET['group_id'])) {
    $g = intval($_GET['group_id']);
    if ($g > 0) {
        $user['group_id'] = $g;
    }
}

// Choose view
$when      = $_GET['when'] ?? 'today';
$schedule  = [];
$label     = '';
$dayLabels = $timeSlots = $grid = [];

switch ($when) {
    case 'yesterday':
        $date     = date('Y-m-d', strtotime('-1 day'));
        $label    = 'Yesterday (' . date('d M Y', strtotime('-1 day')) . ')';
        $schedule = getScheduleForDate($tg_id, $date, $weekType, $subgroup);
        break;

    case 'tomorrow':
        $date     = date('Y-m-d', strtotime('+1 day'));
        $label    = 'Tomorrow (' . date('d M Y', strtotime('+1 day')) . ')';
        $schedule = getScheduleForDate($tg_id, $date, $weekType, $subgroup);
        break;

    case 'week':
        $label = 'This Week';
        // Fetch all entries for this group
        $stmt = $pdo->prepare("
            SELECT day_of_week, time_slot, type, subject, location, week_type
            FROM schedule
            WHERE group_id = ?
              AND day_of_week IN ('Monday','Tuesday','Wednesday','Thursday','Friday')
            ORDER BY
              FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday'),
              STR_TO_DATE(SUBSTRING_INDEX(time_slot,'-',1),'%H:%i')
        ");
        $stmt->execute([$user['group_id']]);
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $dayLabels = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
        $timeSlots = array_unique(array_column($all,'time_slot'));
        usort($timeSlots, fn($a,$b)=>
            strtotime(substr($a,0,5)) - strtotime(substr($b,0,5))
        );

        // build a grid: [ time_slot ][ day_of_week ] => list of rows
        foreach ($all as $r) {
            $grid[$r['time_slot']][$r['day_of_week']][] = $r;
        }
        break;

    case 'today':
    default:
        $date     = date('Y-m-d');
        $label    = 'Today (' . date('d M Y') . ')';
        $schedule = getScheduleForDate($tg_id, $date, $weekType, $subgroup);
        break;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Welcome</title>
  <style>

table {
  display: table !important;
  width: 100% !important;
  table-layout: auto !important;
}
thead {
  display: table-header-group !important;
}
tbody {
  display: table-row-group !important;
}
tr {
  display: table-row !important;
}
th, td {
  display: table-cell !important;
  position: static    !important;
}
    :root {
      --bg:#fff; --fg:#000;
      --link-bg:#eee; --link-fg:#000;
      --btn-bg:#2a9df4; --btn-fg:#fff;
      --border:#ccc; --sec-bg:#f5f5f5;
    }
    .dark-theme {
      --bg:#2b2d2f; --fg:#e2e2e4;
      --link-bg:#3b3f42; --link-fg:#e2e2e4;
      --btn-bg:#1a73e8; --btn-fg:#fff;
      --border:#444; --sec-bg:#3b3f42;
    }
    body {
      margin:0; padding:1rem; font-family:sans-serif;
      background:var(--bg); color:var(--fg);
    }
    a {
      display:inline-block; margin:0 .5em .5em 0;
      padding:.5em 1em; border-radius:4px;
      background:var(--link-bg); color:var(--link-fg);
      text-decoration:none;
    }
    .btn-primary {
      background:var(--btn-bg); color:var(--btn-fg);
    }
    table {
      width:100%; border-collapse:collapse; margin-top:1rem;
    }
    th, td {
      border:1px solid var(--border);
      padding:.5em; text-align:center; vertical-align:top;
    }
    thead th {
      background:var(--sec-bg);  position: static !important;
    top: auto !important; z-index: auto !important;
    }
    td.split-cell {
      display:flex; flex-direction:column; padding:0;
    }
    td.split-cell > .top {
      flex:1; padding:.4em; border-bottom:1px solid var(--border);
    }
    td.split-cell > .bottom {
      flex:1; padding:.4em;
    }
    hr.split-divider {
      border:0; border-top:1px solid var(--border);
      margin:.3em 0;
    }
    .switch { position:relative; display:inline-block; width:50px; height:24px; }
    .switch input { opacity:0; width:0; height:0; }
    .slider {
      position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0;
      background:#ef5350; transition:.4s; border-radius:24px;
    }
    .slider:before {
      position:absolute; content:""; height:18px; width:18px;
      left:3px; bottom:3px; background:#fff; transition:.4s; border-radius:50%;
    }
    input:checked + .slider { background:#66bb6a; }
    input:checked + .slider:before { transform:translateX(26px); }
  </style>
</head>
<body class="<?= (($_COOKIE['theme'] ?? 'light') === 'dark') ? 'dark-theme':'' ?>">

  <label class="switch">
    <input type="checkbox" id="theme-toggle">
    <span class="slider"></span>
  </label>
  <span id="theme-label">Light</span>

  <h1>Hello, <?= htmlspecialchars($user['name'],ENT_QUOTES) ?>!</h1>
  <p>Role: <strong><?= htmlspecialchars(ucfirst($user['role']),ENT_QUOTES) ?></strong></p>

  <nav>
    <a href="?tg_id=<?= $tg_id ?>&when=yesterday">← Yesterday</a>
    <a href="?tg_id=<?= $tg_id ?>&when=today">Today</a>
    <a href="?tg_id=<?= $tg_id ?>&when=tomorrow">Tomorrow →</a>
    <a href="?tg_id=<?= $tg_id ?>&when=week">This Week</a>
  </nav>

  <h2><?= $label ?>’s Schedule</h2>

  <?php if ($when === 'week'): ?>
    <p>This is an <em><?= ucfirst(htmlspecialchars($weekType,ENT_QUOTES)) ?></em> week.</p>
    <?php if (empty($grid)): ?>
      <p>No classes scheduled this week.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Time / Day</th>
            <?php foreach ($dayLabels as $d): ?>
              <th><?= htmlspecialchars($d,ENT_QUOTES) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($timeSlots as $slot): ?>
            <tr>
              <td><?= htmlspecialchars($slot,ENT_QUOTES) ?></td>
              <?php foreach ($dayLabels as $d): ?>
                <?php
                  $cells = $grid[$slot][$d] ?? [];
                  $hasOdd = $hasEven = false;
                  foreach ($cells as $c) {
                    if ($c['week_type'] === 'odd')  $hasOdd  = true;
                    if ($c['week_type'] === 'even') $hasEven = true;
                  }
                  // split any time we have both an odd and an even entry
                  $split = $hasOdd && $hasEven;
                ?>
                <?php if ($split): ?>
                  <td class="split-cell">
                    <div class="top">
                      <?php foreach ($cells as $c): ?>
                        <?php if ($c['week_type'] === 'odd'): ?>
                          <?= htmlspecialchars($c['type'],ENT_QUOTES) ?>. <?= htmlspecialchars($c['subject'],ENT_QUOTES) ?><br>
                          <?php if ($c['location']): ?>
                            <small><?= htmlspecialchars($c['location'],ENT_QUOTES) ?></small><br>
                          <?php endif; ?>
                        <?php endif; ?>
                      <?php endforeach; ?>
                      <hr class="split-divider">
                    </div>
                    <div class="bottom">
                      <?php foreach ($cells as $c): ?>
                        <?php if ($c['week_type'] === 'even'): ?>
                          <?= htmlspecialchars($c['type'],ENT_QUOTES) ?>. <?= htmlspecialchars($c['subject'],ENT_QUOTES) ?><br>
                          <?php if ($c['location']): ?>
                            <small><?= htmlspecialchars($c['location'],ENT_QUOTES) ?></small><br>
                          <?php endif; ?>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </div>
                  </td>
                <?php else: ?>
                  <td>
                    <?php foreach ($cells as $c): ?>
                      <?= htmlspecialchars($c['type'],ENT_QUOTES) ?>. <?= htmlspecialchars($c['subject'],ENT_QUOTES) ?><br>
                      <?php if ($c['location']): ?>
                        <small><?= htmlspecialchars($c['location'],ENT_QUOTES) ?></small><br>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </td>
                <?php endif; ?>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

  <?php elseif (empty($schedule)): ?>
    <p>No classes scheduled.</p>

  <?php else: ?>
    <table>
      <thead>
        <tr><th>Time</th><th>Type</th><th>Subject</th><th>Location</th></tr>
      </thead>
      <tbody>
        <?php foreach ($schedule as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['time_slot'],ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['type'],ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['subject'],ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['location'],ENT_QUOTES) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div style="margin-top:1rem;">
    <?php if (in_array($user['role'], ['admin','monitor','moderator'], true)): ?>
      <a class="btn-primary" href="index.html?tg_id=<?= $tg_id ?>&when=<?= $when ?>">📝 Log Attendance</a>
    <?php endif; ?>
    <a class="btn-primary" href="view_attendance.php?tg_id=<?= $tg_id ?>">📊 View My Attendance</a>
    <?php if (in_array($user['role'], ['monitor','admin'], true)): ?>
      <a class="btn-primary" href="view_group_attendance.php?tg_id=<?= $tg_id ?>&group_id=<?= $user['group_id'] ?>">👥 View Group Attendance</a>
      <a class="btn-primary" href="export.php?tg_id=<?= $tg_id ?>&group_id=<?= $user['group_id'] ?>">📥 Export Attendance</a>
      <?php if ($user['role'] === 'admin'): ?>
        <a class="btn-primary" href="greeting.php?tg_id=348442139&group_id=2">Primary</a>
        <a class="btn-primary" href="greeting.php?tg_id=878801928">Secondary</a>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <script>
    const toggle = document.getElementById('theme-toggle'),
          label  = document.getElementById('theme-label'),
          saved  = localStorage.getItem('theme') || 'light';
    if (saved === 'dark') {
      document.body.classList.add('dark-theme');
      toggle.checked = true;
      label.textContent = 'Dark';
    }
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
