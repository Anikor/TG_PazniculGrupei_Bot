<?php
// miniapp/greeting.php
session_start();

// If client POSTs the Telegram ID, save it in session and exit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $data = json_decode(file_get_contents('php://input'), true);
  if (!empty($data['tg_id']) && ctype_digit((string)$data['tg_id'])) {
    $_SESSION['tg_id'] = (int)$data['tg_id'];
    exit;  // nothing else
  }
}

// If we don’t yet have it in $_SESSION, render the bootstrap HTML/JS
if (!isset($_SESSION['tg_id'])) {
  ?><!DOCTYPE html>
  <html><head><meta charset="utf-8"><title>Loading…</title></head><body>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <script>
      const tg   = window.Telegram.WebApp; tg.expand();
      
      const user = tg.initDataUnsafe && tg.initDataUnsafe.user;
      if (!user) {
        document.body.innerHTML = '<p style="color:red">Cannot detect user ID</p>';
      } else {
        // send it via POST
        fetch(location.href, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ tg_id: user.id })
        }).then(() => location.reload());
      }
    </script>
  </body></html><?php
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
// from here on you have a session-stored ID
$tg_id = $_SESSION['tg_id'];
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

/**
 * Wraps HTML in a <td> with odd/even classes.
 *
 * @param array  $slot    Must have ['week_type'] = 'odd'|'even'|null
 * @param string $content Inner HTML for the cell
 * @return string         The <td>…</td>
 */
function weekCell(array $slot, string $content): string {
    $classes = [];
    if (!empty($slot['week_type'])) {
        $classes = ['week-cell', $slot['week_type']];
    }
    $cls = $classes ? ' class="'.implode(' ',$classes).'"' : '';
    return "<td{$cls}>{$content}</td>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Welcome</title>
  <style>
<style>
  /*──────────────────────────────────────────────────────────────
    RESET FOR TABLES (so they stay tables on narrow screens)
  ──────────────────────────────────────────────────────────────*/
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
    position: static  !important;
  }

  /*──────────────────────────────────────────────────────────────
    COLOR PALETTE
  ──────────────────────────────────────────────────────────────*/
  :root {
    /* core */
    --bg:       #fff;    --fg:     #000;
    --link-bg:  #eee;    --link-fg: #000;
    --btn-bg:   #2a9df4; --btn-fg:  #fff;
    --border:   #ccc;    --sec-bg:  #f5f5f5;
    /* odd/even highlights */
    --even-bg:  #e6f7e6; --even-fg: #155724;
    --odd-bg:   #fff9e6; --odd-fg:  #856404;
  }
  .dark-theme {
    /* core */
    --bg:       #2b2d2f; --fg:     #e2e2e4;
    --link-bg:  #3b3f42; --link-fg: #e2e2e4;
    --btn-bg:   #1a73e8; --btn-fg:  #fff;
    --border:   #444;    --sec-bg:  #3b3f42;
    /* odd/even */
    --even-bg:  #264d26; --even-fg: #d4edda;
    --odd-bg:   #665500; --odd-fg: #fff3cd;
  }

  /*──────────────────────────────────────────────────────────────
    BASE TYPOGRAPHY
  ──────────────────────────────────────────────────────────────*/
  body {
    margin: 0;
    padding: 1rem;
    font-family: sans-serif;
    background: var(--bg);
    color: var(--fg);
  }
  h1 { margin-top: 0; }
  h3 { margin-bottom: 0; }
  p  { margin: .5em 0 1em; }

  /*──────────────────────────────────────────────────────────────
    ALL LINKS (INCLUDING button-links)
    — giving them the same margin, padding, radius & reset
  ──────────────────────────────────────────────────────────────*/
  a {
    display: inline-block;
    margin: 0 .5em .5em 0;    /* ← right & bottom spacing */
    padding: .5em 1em;        /* ← nice fat click targets */
    border-radius: 4px;
    background: var(--link-bg);
    color: var(--link-fg);
    text-decoration: none;
  }

  /*──────────────────────────────────────────────────────────────
    BUTTON VARIANTS
    — simply override the link’s bg & fg
  ──────────────────────────────────────────────────────────────*/
  .btn-primary {
    background: var(--btn-bg);
    color: var(--btn-fg);
  }
  .btn-secondary {
    background: var(--sec-bg);
    color: var(--fg);
  }

  /*──────────────────────────────────────────────────────────────
    TABLE (daily/tomorrow) STYLING
  ──────────────────────────────────────────────────────────────*/
  table {
    border-collapse: collapse;
    margin-top: 1rem;
  }
  th, td {
    border: 1px solid var(--border);
    padding: .5em;
    text-align: center;
    vertical-align: middle;
  }
  thead th {
    background: var(--sec-bg);
  }

  /*──────────────────────────────────────────────────────────────
    WEEK VIEW GRID STYLING
  ──────────────────────────────────────────────────────────────*/
  .schedule-table {
    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;
  }
  .schedule-table th,
  .schedule-table td {
    width: 16.66%;
    height: 90px;
    text-align: center;
    vertical-align: middle;
    padding: 8px;
    box-sizing: border-box;
  }
  .week-type {
    display: inline-block;
    padding: .2em .5em;
    border-radius: 4px;
    font-weight: bold;
  }
  .week-type.even { background: var(--even-bg); color: var(--even-fg); }
  .week-type.odd  { background: var(--odd-bg);  color: var(--odd-fg); }

  .week-cell {
    border-radius: 4px;
  }
  .week-cell.even { background: var(--even-bg); color: var(--even-fg); }
  .week-cell.odd  { background: var(--odd-bg);  color: var(--odd-fg); }

  .switch {
    position: relative;
    display: inline-block;
    width: 50px;
    height: 24px;
    margin-right: .5rem;
    vertical-align: middle;
  }
  .switch input {
    opacity: 0;
    width: 0;
    height: 0;
  }
  .slider {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background: #ccc;
    transition: .4s;
    border-radius: 24px;
  }
  .slider:before {
    content: "";
    position: absolute;
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background: white;
    transition: .4s;
    border-radius: 50%;
  }
  input:checked + .slider {
    background: #66bb6a;
  }
  input:checked + .slider:before {
    transform: translateX(26px);
  }

</style>

</head>
<body class="<?= (($_COOKIE['theme'] ?? 'light') === 'dark') ? 'dark-theme' : '' ?>">

  <!-- Theme toggle -->
  <label class="switch">
    <input type="checkbox" id="theme-toggle">
    <span class="slider"></span>
  </label>
  <span id="theme-label">Light</span>

  <br><br><br><h1>Hello, <?= htmlspecialchars($user['name'], ENT_QUOTES) ?>!</h1>
  <?php if ($user['role'] !== 'student'): ?>
  <p>Role: <strong><?= ucfirst(htmlspecialchars($user['role'])) ?></strong></p><?php endif; ?>

  <nav>
    <a class="btn" href="?tg_id=<?= $tg_id ?>&when=yesterday">← Yesterday</a>
    <a class="btn" href="?tg_id=<?= $tg_id ?>&when=today">Today</a>
    <a class="btn" href="?tg_id=<?= $tg_id ?>&when=tomorrow">Tomorrow →</a>
    <a class="btn" href="?tg_id=<?= $tg_id ?>&when=week">This Week</a>
  </nav>

  <h2><?= $label ?>’s Schedule</h2>
    <p>This is an <span class="week-type <?= $weekType ?>"><?= ucfirst($weekType) ?></span> week.</p>

  <?php if ($when === 'week'): ?>

    <?php if (empty($grid)): ?>
      <p>No classes scheduled this week.</p>
    <?php else: ?>
      <table >
        <thead>
          <tr>
            <th>Time / Day</th>
            <?php foreach ($dayLabels as $d): ?>
              <th><?= htmlspecialchars($d, ENT_QUOTES) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <?php
  // 1) Filter out any time‐slot where *all* days are empty
  $filteredSlots = array_filter($timeSlots, function($slot) use($dayLabels, $grid) {
    foreach ($dayLabels as $d) {
      if (! empty($grid[$slot][$d]) ) {
        // at least one session (full, odd, or even)
        return true;
      }
    }
    // no sessions this slot → remove it
    return false;
  });
?>
<tbody>
  <?php foreach ($filteredSlots as $slot): ?>

    <!-- ODD‐WEEK ROW (and full‐week slots) -->
    <tr>
      <!-- time cell spans both sub‐rows -->
      <td rowspan="2"><?= htmlspecialchars($slot, ENT_QUOTES) ?></td>

      <?php foreach ($dayLabels as $d):
        $cells = $grid[$slot][$d] ?? [];
        $full  = array_filter($cells, fn($c)=> $c['week_type'] === null);
        $odd   = array_filter($cells, fn($c)=> $c['week_type'] === 'odd');
        $even  = array_filter($cells, fn($c)=> $c['week_type'] === 'even');
      ?>

        <?php if (count($full)): 
          // full‐week session → spans both rows
          $c   = reset($full);
          $cls = 'week-cell'; // full‐week uses default styling
        ?>
          <td rowspan="2" class="<?= $cls ?>">
            <?= htmlspecialchars($c['type'],ENT_QUOTES) ?>. <?= htmlspecialchars($c['subject'],ENT_QUOTES) ?><br>
            <?php if ($c['location']): ?>
              <small><?= htmlspecialchars($c['location'],ENT_QUOTES) ?></small>
            <?php endif; ?>
          </td>

        <?php elseif (count($odd) && count($even)): ?>
          <!-- split cell: odd on top -->
          <td class="week-cell odd">
            <?php foreach ($odd as $c): ?>
              <?= htmlspecialchars($c['type'],ENT_QUOTES) ?>. <?= htmlspecialchars($c['subject'],ENT_QUOTES) ?><br>
              <?php if ($c['location']): ?>
                <small><?= htmlspecialchars($c['location'],ENT_QUOTES) ?></small><br>
              <?php endif; ?>
            <?php endforeach; ?>
          </td>

        <?php elseif (count($odd)): ?>
          <!-- odd only -->
          <td class="week-cell odd">
            <?php foreach ($odd as $c): ?>
              <?= htmlspecialchars($c['type'],ENT_QUOTES) ?>. <?= htmlspecialchars($c['subject'],ENT_QUOTES) ?><br>
              <?php if ($c['location']): ?>
                <small><?= htmlspecialchars($c['location'],ENT_QUOTES) ?></small><br>
              <?php endif; ?>
            <?php endforeach; ?>
          </td>

        <?php else: ?>
          <!-- no full/odd here -->
          <td>&nbsp;</td>
        <?php endif; ?>

      <?php endforeach; ?>
    </tr>

    <!-- EVEN‐WEEK ROW -->
    <tr>
      <?php foreach ($dayLabels as $d):
        $cells = $grid[$slot][$d] ?? [];
        $full  = array_filter($cells, fn($c)=> $c['week_type'] === null);
        $odd   = array_filter($cells, fn($c)=> $c['week_type'] === 'odd');
        $even  = array_filter($cells, fn($c)=> $c['week_type'] === 'even');
      ?>

        <?php if (count($full)): 
          // already rendered above (rowspan)
          continue;

        elseif (count($odd) && count($even)): ?>
          <!-- split cell: even on bottom -->
          <td class="week-cell even">
            <?php foreach ($even as $c): ?>
              <?= htmlspecialchars($c['type'],ENT_QUOTES) ?>. <?= htmlspecialchars($c['subject'],ENT_QUOTES) ?><br>
              <?php if ($c['location']): ?>
                <small><?= htmlspecialchars($c['location'],ENT_QUOTES) ?></small><br>
              <?php endif; ?>
            <?php endforeach; ?>
          </td>

        <?php elseif (count($even)): ?>
          <!-- even only -->
          <td class="week-cell even">
            <?php foreach ($even as $c): ?>
              <?= htmlspecialchars($c['type'],ENT_QUOTES) ?>. <?= htmlspecialchars($c['subject'],ENT_QUOTES) ?><br>
              <?php if ($c['location']): ?>
                <small><?= htmlspecialchars($c['location'],ENT_QUOTES) ?></small><br>
              <?php endif; ?>
            <?php endforeach; ?>
          </td>

        <?php else: ?>
          <!-- no even/full here -->
          <td>&nbsp;</td>
        <?php endif; ?>

      <?php endforeach; ?>
    </tr>

  <?php endforeach; ?>
</tbody>

      </table>
    <?php endif; ?>

    <?php elseif (empty($schedule)): ?>

    <p>No classes scheduled <?= $when === 'today' ? 'today' : 'for ' . htmlspecialchars($when, ENT_QUOTES) ?>.</p>

  <?php else: ?>
    <!-- === DAILY VIEW: four columns instead of “Details” === -->
    <table>
      <thead>
        <tr>
          <th>Time</th>
          <th>Type</th>
          <th>Subject</th>
          <th>Location</th>
        </tr>
      </thead>
      <tbody >
        <?php foreach ($schedule as $r): ?>
          <tr>
            <!-- Time slot -->
            <td><?= htmlspecialchars($r['time_slot'], ENT_QUOTES) ?></td>

            <!-- Type -->
            <td<?= !empty($r['week_type'])
                  ? ' class="week-cell '. $r['week_type'] .'"'
                  : '' ?>>
              <?= htmlspecialchars($r['type'], ENT_QUOTES) ?>
            </td>

            <!-- Subject -->
            <td<?= !empty($r['week_type'])
                  ? ' class="week-cell '. $r['week_type'] .'"'
                  : '' ?>>
              <?= htmlspecialchars($r['subject'], ENT_QUOTES) ?>
            </td>

            <!-- Location -->
            <td<?= !empty($r['week_type'])
                  ? ' class="week-cell '. $r['week_type'] .'"'
                  : '' ?>>
              <?= htmlspecialchars($r['location'], ENT_QUOTES) ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>


    <div style="margin-top:1rem;">
    <?php if (in_array($user['role'], ['admin','monitor','moderator'], true)): ?>
      <a class="btn btn-primary" href="index.php?tg_id=<?= $tg_id ?>&when=<?= $when ?>">📝 Log Attendance</a>
    <?php endif; ?>
    <a class="btn btn-primary" href="view_attendance.php?tg_id=<?= $tg_id ?>">📊 View My Attendance</a>
    <?php if (in_array($user['role'], ['monitor','admin'], true)): ?>
      <a class="btn btn-primary" href="view_group_attendance.php?tg_id=<?= $tg_id ?>&group_id=<?= $user['group_id'] ?>">👥 View Group Attendance</a>
      <a class="btn btn-primary" href="export.php?tg_id=<?= $tg_id ?>&group_id=<?= $user['group_id'] ?>">📥 Export Attendance</a>
      <?php if ($user['role'] === 'admin'): ?>
        <a class="btn btn-primary" href="greeting.php?tg_id=348442139&group_id=2">Primary</a>
        <a class="btn btn-primary" href="greeting.php?tg_id=878801928">Secondary</a>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <script>
    // Theme toggle persistence
    const themeToggle = document.getElementById('theme-toggle');
    const themeLabel  = document.getElementById('theme-label');
    const body        = document.body;
    let current      = localStorage.getItem('theme') || 'light';
    if (current === 'dark') {
      body.classList.add('dark-theme');
      themeToggle.checked = true;
      themeLabel.textContent = 'Dark';
    }
    themeToggle.addEventListener('change', e => {
      if (e.target.checked) {
        body.classList.add('dark-theme');
        localStorage.setItem('theme','dark');
        themeLabel.textContent = 'Dark';
      } else {
        body.classList.remove('dark-theme');
        localStorage.setItem('theme','light');
        themeLabel.textContent = 'Light';
      }
    });
  </script>
</body>
</html>
