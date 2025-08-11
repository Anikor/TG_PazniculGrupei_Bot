<?php
// miniapp/greeting.php
session_start();

/* ─────────────────────────────────────────────────────────────
   0) Bootstrap: get Telegram user ID via WebApp → store in session
   ────────────────────────────────────────────────────────────*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $data = json_decode(file_get_contents('php://input'), true);
  if (!empty($data['tg_id']) && ctype_digit((string)$data['tg_id'])) {
    $_SESSION['tg_id'] = (int)$data['tg_id'];
  }
  exit;
}

if (!isset($_SESSION['tg_id'])) {
  ?><!DOCTYPE html>
  <html lang="en">
  <head><meta charset="utf-8"><title>Loading…</title></head>
  <body>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <script>
      const tg = window.Telegram.WebApp; tg.expand();
      const user = tg.initDataUnsafe && tg.initDataUnsafe.user;
      if (!user) {
        document.body.innerHTML = '<p style="color:red">Cannot detect user ID</p>';
      } else {
        fetch(location.href, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ tg_id: user.id })
        }).then(() => {
          history.replaceState(null, '', location.pathname + location.search);
          location.reload();
        });
      }
    </script>
  </body>
  </html><?php
  exit;
}

/* ─────────────────────────────────────────────────────────────
   1) App bootstrap
   ────────────────────────────────────────────────────────────*/
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/oe_weeks.php';

/* Utility: make initials like "Computer Networks" → "CN" */
function initials(string $s): string {
  $stop  = ['of','and','the','de','la','si','și','în','din','ale','a','cu'];
  $parts = preg_split('/[\s\-]+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY);
  $out   = '';
  foreach ($parts as $w) {
    $lw = mb_strtolower($w, 'UTF-8');
    if (in_array($lw, $stop, true)) continue;
    $out .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
  }
  return $out ?: mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8');
}

/* Identify user */
$tg_id = $_SESSION['tg_id'];
$user  = getUserByTgId($tg_id);
if (!$user) {
  http_response_code(400);
  exit('Error: invalid or unregistered Telegram ID');
}

/* Odd/even + subgroup */
$weekType = getCurrentWeekType();      // 'odd' or 'even'
$subgroup = $user['subgroup'] ?? null; // 1, 2, or NULL

/* Admin override group via GET (optional) */
if ($user['role'] === 'admin' && isset($_GET['group_id'])) {
  $g = intval($_GET['group_id']);
  if ($g > 0) $user['group_id'] = $g;
}

/* View selection */
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
    usort($timeSlots, fn($a,$b)=> strtotime(substr($a,0,5)) - strtotime(substr($b,0,5)));

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

/* Helper: wrap cell with odd/even classes (kept if you need it later) */
function weekCell(array $slot, string $content): string {
  $classes = [];
  if (!empty($slot['week_type'])) $classes = ['week-cell', $slot['week_type']];
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
  /* Keep tables as tables on narrow screens */
  table{display:table!important;width:100%!important;table-layout:auto!important}
  thead{display:table-header-group!important}
  tbody{display:table-row-group!important}
  tr{display:table-row!important}
  th,td{display:table-cell!important;position:static!important}

  /* Colors */
  :root{--bg:#fff;--fg:#000;--link-bg:#eee;--link-fg:#000;--btn-bg:#2a9df4;--btn-fg:#fff;--border:#ccc;--sec-bg:#f5f5f5;--even-bg:#e6f7e6;--even-fg:#155724;--odd-bg:#fff9e6;--odd-fg:#856404}
  .dark-theme{--bg:#2b2d2f;--fg:#e2e2e4;--link-bg:#3b3f42;--link-fg:#e2e2e4;--btn-bg:#1a73e8;--btn-fg:#fff;--border:#444;--sec-bg:#3b3f42;--even-bg:#264d26;--even-fg:#d4edda;--odd-bg:#665500;--odd-fg:#fff3cd}

  /* Base */
  body{margin:0;padding:1rem;font-family:sans-serif;background:var(--bg);color:var(--fg)}
  h1{margin-top:0} h3{margin-bottom:0} p{margin:.5em 0 1em}

  /* Links & buttons */
  a{display:inline-block;margin:0 .5em .5em 0;padding:.5em 1em;border-radius:4px;background:var(--link-bg);color:var(--link-fg);text-decoration:none}
  .btn-primary{background:var(--btn-bg);color:var(--btn-fg)}
  .btn-secondary{background:var(--sec-bg);color:var(--fg)}

  /* Tables */
  table{border-collapse:collapse;width:100%!important;margin-top:1rem}
  th,td{border:1px solid var(--border);padding:.5em;text-align:center;vertical-align:middle}
  thead th{background:var(--sec-bg)}

  /* Week grid */
  .schedule-table{width:100%;table-layout:fixed;border-collapse:collapse}
  .schedule-table th,.schedule-table td{width:.2em;height:.2em;text-align:center;vertical-align:middle;padding:8px;box-sizing:border-box}
  .week-type{display:inline-block;padding:.2em .3em;border-radius:4px;font-weight:bold}
  .week-type.even{background:var(--even-bg);color:var(--even-fg)}
  .week-type.odd{background:var(--odd-bg);color:var(--odd-fg)}
  .week-cell{border-radius:4px}
  .week-cell.even{background:var(--even-bg);color:var(--even-fg)}
  .week-cell.odd{background:var(--odd-bg);color:var(--odd-fg)}

  /* Theme switch */
  .switch{position:relative;display:inline-block;width:50px;height:24px;margin-right:.5rem;vertical-align:middle}
  .switch input{opacity:0;width:0;height:0}
  .slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:#ccc;transition:.4s;border-radius:24px}
  .slider:before{content:"";position:absolute;height:18px;width:18px;left:3px;bottom:3px;background:#fff;transition:.4s;border-radius:50%}
  input:checked + .slider{background:#66bb6a}
  input:checked + .slider:before{transform:translateX(26px)}

  /* Remove dotted underline on our initials */
abbr.subject-short {text-decoration: none;border-bottom: 0;cursor: default; /* optional */}
/* Just in case some browsers reapply it on hover/focus */
abbr.subject-short:hover,abbr.subject-short:focus {text-decoration: none;border-bottom: 0;}

  /* SUBJECT FULL/SHORT SWITCH */
  .subject-short{display:none}
  @media (max-width:520px){
    .subject-full{display:none}
    .subject-short{display:inline}
  }
</style>
</head>

<body class="<?= (($_COOKIE['theme'] ?? 'light') === 'dark') ? 'dark-theme' : '' ?>">

  <!-- Theme toggle -->
  <label class="switch">
    <input type="checkbox" id="theme-toggle"><span class="slider"></span>
  </label>
  <span id="theme-label">Light</span>

  <br><br><br><h1>Hello, <?= htmlspecialchars($user['name'], ENT_QUOTES) ?>!</h1>
  <?php if ($user['role'] !== 'student'): ?>
    <p>Role: <strong><?= ucfirst(htmlspecialchars($user['role'])) ?></strong></p>
  <?php endif; ?>

  <nav>
    <!-- No tg_id in links here; session handles it -->
    <a class="btn" href="?when=yesterday">← Yesterday</a>
    <a class="btn" href="?when=today">Today</a>
    <a class="btn" href="?when=tomorrow">Tomorrow →</a>
    <a class="btn" href="?when=week">This Week</a>
  </nav>

  <h2><?= $label ?>’s Schedule</h2>
  <p>This is an <span class="week-type <?= $weekType ?>"><?= ucfirst($weekType) ?></span> week.</p>

  <?php if ($when === 'week'): ?>

    <?php if (empty($grid)): ?>
      <p>No classes scheduled this week.</p>
    <?php else: ?>
      <table class="schedule-table">
        <thead>
          <tr>
            <th>Time</th>
            <?php foreach ($dayLabels as $d): ?>
              <th><?= htmlspecialchars($d, ENT_QUOTES) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>

        <?php
        // Filter out time-slots where all days are empty
        $filteredSlots = array_filter($timeSlots, function($slot) use ($dayLabels, $grid) {
          foreach ($dayLabels as $d) if (!empty($grid[$slot][$d])) return true;
          return false;
        });
        ?>
        <tbody>
        <?php foreach ($filteredSlots as $slot): ?>
          <!-- ODD row (top) -->
          <tr>
            <td rowspan="2"><?= htmlspecialchars($slot, ENT_QUOTES) ?></td>
            <?php foreach ($dayLabels as $d):
              $cells = $grid[$slot][$d] ?? [];
              $full  = array_filter($cells, fn($c)=> $c['week_type'] === null);
              $odd   = array_filter($cells, fn($c)=> $c['week_type'] === 'odd');
              $even  = array_filter($cells, fn($c)=> $c['week_type'] === 'even');
            ?>

              <?php if (count($full)): $c = reset($full); ?>
                <td rowspan="2" class="week-cell">
                  <?= htmlspecialchars($c['type'],ENT_QUOTES) ?>.
                  <!-- SUBJECT FULL + SHORT -->
                  <?php $subject = $c['subject']; ?>
                  <span class="subject" aria-label="<?= htmlspecialchars($subject) ?>">
                    <span class="subject-full"><?= htmlspecialchars($subject) ?></span>
                    <abbr class="subject-short" title="<?= htmlspecialchars($subject) ?>">
                      <?= htmlspecialchars(initials($subject)) ?>
                    </abbr>
                  </span><br>
                  <?php if ($c['location']): ?><small><?= htmlspecialchars($c['location'],ENT_QUOTES) ?></small><?php endif; ?>
                </td>

              <?php elseif (count($odd) && count($even)): ?>
                <td class="week-cell odd">
                  <?php foreach ($odd as $c): ?>
                    <?= htmlspecialchars($c['type'],ENT_QUOTES) ?>.
                    <?php $subject = $c['subject']; ?>
                    <span class="subject" aria-label="<?= htmlspecialchars($subject) ?>">
                      <span class="subject-full"><?= htmlspecialchars($subject) ?></span>
                      <abbr class="subject-short" title="<?= htmlspecialchars($subject) ?>">
                        <?= htmlspecialchars(initials($subject)) ?>
                      </abbr>
                    </span><br>
                    <?php if ($c['location']): ?><small><?= htmlspecialchars($c['location'],ENT_QUOTES) ?></small><br><?php endif; ?>
                  <?php endforeach; ?>
                </td>

              <?php elseif (count($odd)): ?>
                <td class="week-cell odd">
                  <?php foreach ($odd as $c): ?>
                    <?= htmlspecialchars($c['type'],ENT_QUOTES) ?>.
                    <?php $subject = $c['subject']; ?>
                    <span class="subject" aria-label="<?= htmlspecialchars($subject) ?>">
                      <span class="subject-full"><?= htmlspecialchars($subject) ?></span>
                      <abbr class="subject-short" title="<?= htmlspecialchars($subject) ?>">
                        <?= htmlspecialchars(initials($subject)) ?>
                      </abbr>
                    </span><br>
                    <?php if ($c['location']): ?><small><?= htmlspecialchars($c['location'],ENT_QUOTES) ?></small><br><?php endif; ?>
                  <?php endforeach; ?>
                </td>

              <?php else: ?>
                <td>&nbsp;</td>
              <?php endif; ?>

            <?php endforeach; ?>
          </tr>

          <!-- EVEN row (bottom) -->
<tr>
  <?php foreach ($dayLabels as $d):
    $cells = $grid[$slot][$d] ?? [];
    $full  = array_filter($cells, fn($c)=> $c['week_type'] === null);
    $odd   = array_filter($cells, fn($c)=> $c['week_type'] === 'odd');
    $even  = array_filter($cells, fn($c)=> $c['week_type'] === 'even');
  ?>

    <?php if (count($full)): ?>
      <?php continue; // row-spanned above ?>
    <?php elseif (count($odd) && count($even)): ?>
      <td class="week-cell even">
        <?php foreach ($even as $c): ?>
          <?= htmlspecialchars($c['type'],ENT_QUOTES) ?>.
          <?php $subject = $c['subject']; ?>
          <span class="subject" aria-label="<?= htmlspecialchars($subject) ?>">
            <span class="subject-full"><?= htmlspecialchars($subject) ?></span>
            <abbr class="subject-short" title="<?= htmlspecialchars($subject) ?>">
              <?= htmlspecialchars(initials($subject)) ?>
            </abbr>
          </span><br>
          <?php if ($c['location']): ?><small><?= htmlspecialchars($c['location'],ENT_QUOTES) ?></small><br><?php endif; ?>
        <?php endforeach; ?>
      </td>

    <?php elseif (count($even)): ?>
      <td class="week-cell even">
        <?php foreach ($even as $c): ?>
          <?= htmlspecialchars($c['type'],ENT_QUOTES) ?>.
          <?php $subject = $c['subject']; ?>
          <span class="subject" aria-label="<?= htmlspecialchars($subject) ?>">
            <span class="subject-full"><?= htmlspecialchars($subject) ?></span>
            <abbr class="subject-short" title="<?= htmlspecialchars($subject) ?>">
              <?= htmlspecialchars(initials($subject)) ?>
            </abbr>
          </span><br>
          <?php if ($c['location']): ?><small><?= htmlspecialchars($c['location'],ENT_QUOTES) ?></small><br><?php endif; ?>
        <?php endforeach; ?>
      </td>

    <?php else: ?>
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

    <!-- DAILY / SINGLE-DAY TABLE -->
    <table>
      <thead>
        <tr><th>Time</th><th>Type</th><th>Subject</th><th>Location</th></tr>
      </thead>
      <tbody>
        <?php foreach ($schedule as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['time_slot'], ENT_QUOTES) ?></td>
            <td<?= !empty($r['week_type']) ? ' class="week-cell '.$r['week_type'].'"' : '' ?>>
              <?= htmlspecialchars($r['type'], ENT_QUOTES) ?>
            </td>
            <td<?= !empty($r['week_type']) ? ' class="week-cell '.$r['week_type'].'"' : '' ?>>
              <?php $subject = $r['subject']; ?>
              <span class="subject" aria-label="<?= htmlspecialchars($subject) ?>">
                <span class="subject-full"><?= htmlspecialchars($subject) ?></span>
                <abbr class="subject-short" title="<?= htmlspecialchars($subject) ?>">
                  <?= htmlspecialchars(initials($subject)) ?>
                </abbr>
              </span>
            </td>
            <td<?= !empty($r['week_type']) ? ' class="week-cell '.$r['week_type'].'"' : '' ?>>
              <?= htmlspecialchars($r['location'], ENT_QUOTES) ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

  <?php endif; ?>

  <div style="margin-top:1rem;">
    <?php if (in_array($user['role'], ['admin','monitor','moderator'], true)): ?>
      <a class="btn btn-primary" href="index.php?tg_id=<?= $tg_id ?>&when=<?= urlencode($when) ?>">📝 Log Attendance</a>
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
    // Theme toggle (localStorage)
    const themeToggle = document.getElementById('theme-toggle');
    const themeLabel  = document.getElementById('theme-label');
    const body        = document.body;
    let current       = localStorage.getItem('theme') || 'light';
    if (current === 'dark') { body.classList.add('dark-theme'); themeToggle.checked = true; themeLabel.textContent = 'Dark'; }
    themeToggle.addEventListener('change', e => {
      if (e.target.checked) { body.classList.add('dark-theme'); localStorage.setItem('theme','dark'); themeLabel.textContent = 'Dark'; }
      else { body.classList.remove('dark-theme'); localStorage.setItem('theme','light'); themeLabel.textContent = 'Light'; }
    });
  </script>
</body>
</html>
