<?php
session_start();

/* Bootstrap: Telegram WebApp → session tg_id */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $data = json_decode(file_get_contents('php://input'), true);
  if (!empty($data['tg_id']) && ctype_digit((string)$data['tg_id'])) {
    $_SESSION['tg_id'] = (int)$data['tg_id'];
  }
  exit;
}
if (!isset($_SESSION['tg_id'])) {
  ?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Loading…</title></head><body>
  <script src="https://telegram.org/js/telegram-web-app.js"></script>
  <script>
    const tg = window.Telegram.WebApp; try{tg.expand();}catch(e){}
    const user = tg.initDataUnsafe && tg.initDataUnsafe.user;
    if (!user) { document.body.innerHTML = '<p style="color:red">Cannot detect user ID</p>'; }
    else {
      fetch(location.href, { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({tg_id:user.id}) })
      .then(()=>{ history.replaceState(null,'',location.pathname+location.search); location.reload(); });
    }
  </script></body></html><?php
  exit;
}

/* App bootstrap */
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/oe_weeks.php';

/* Helpers */
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
if (!$user) { http_response_code(400); exit('Error: invalid or unregistered Telegram ID'); }

/* Odd/even + subgroup */
$weekType = getCurrentWeekType();
$subgroup = $user['subgroup'] ?? null;

/* Admin override group via GET (optional) */
if ($user['role'] === 'admin' && isset($_GET['group_id'])) {
  $g = intval($_GET['group_id']); if ($g > 0) $user['group_id'] = $g;
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
    foreach ($all as $r) { $grid[$r['time_slot']][$r['day_of_week']][] = $r; }
    break;

  case 'today':
  default:
    $date     = date('Y-m-d');
    $label    = 'Today (' . date('d M Y') . ')';
    $schedule = getScheduleForDate($tg_id, $date, $weekType, $subgroup);
    break;
}

/* Initial table layout (for first paint) */
$tableLayout = $_COOKIE['tableLayout'] ?? 'small'; // 'small' | 'big'

/* CSS URLs (with cache-busting) */
$baseUri     = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$bigPath     = __DIR__ . '/tableb.css';
$smallPath   = __DIR__ . '/tablec.css';
$cssBigUrl   = $baseUri . '/tableb.css?v='   . (file_exists($bigPath)   ? filemtime($bigPath)   : time());
$cssSmallUrl = $baseUri . '/tablec.css?v='   . (file_exists($smallPath) ? filemtime($smallPath) : time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Welcome</title>

<!-- Base styles ALWAYS on (daily + shared) -->
<link rel="stylesheet" href="<?= htmlspecialchars($cssSmallUrl, ENT_QUOTES) ?>" id="css-small" media="all">
<!-- Week-grid overrides ONLY present on week view -->
<?php if ($when === 'week'): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($cssBigUrl, ENT_QUOTES) ?>" id="css-big"
      media="<?= ($tableLayout==='big') ? 'all' : 'not all' ?>">
<?php endif; ?>

</head>
<body class="<?= (($_COOKIE['theme'] ?? 'light') === 'dark') ? 'dark-theme' : '' ?>">

  <!-- Theme toggle -->
  <label class="switch">
    <input type="checkbox" id="theme-toggle"><span class="slider"></span>
  </label>
  <span id="theme-label">Light</span>

  <!-- Big/Compact toggle — ONLY on week view -->
  <?php if ($when === 'week'): ?>
  <label class="switch" style="margin-left:.75rem">
    <input type="checkbox" id="table-toggle"><span class="slider"></span>
  </label>
  <span id="table-label"><?= ($tableLayout==='big') ? 'Big' : 'Compact' ?></span>
  <?php endif; ?>

  <br><br><br><h1>Hello, <?= htmlspecialchars($user['name'], ENT_QUOTES) ?>!</h1>
  <?php if ($user['role'] !== 'student'): ?>
    <p>Role: <strong><?= ucfirst(htmlspecialchars($user['role'])) ?></strong></p>
  <?php endif; ?>

  <nav>
    <a class="btn" href="?when=yesterday">← Yesterday</a>
    <a class="btn" href="?when=today">Today</a>
    <a class="btn" href="?when=tomorrow">Tomorrow →</a>
    <a class="btn" href="?when=week">This Week</a>
  </nav>

  <h2><?= htmlspecialchars($label, ENT_QUOTES) ?>’s Schedule</h2>
  <p>This is an <span class="week-type <?= $weekType ?>"><?= ucfirst($weekType) ?></span> week.</p>

<?php if ($when === 'week'): ?>

  <?php if (empty($grid)): ?>
    <p>No classes scheduled this week.</p>
  <?php else: ?>
    <table class="schedule-table">
      <colgroup>
        <col class="col-time">
        <?php for ($i=0; $i<count($dayLabels); $i++): ?><col class="col-day"><?php endfor; ?>
      </colgroup>
      <thead>
        <tr>
          <th>Time</th>
          <?php foreach ($dayLabels as $d): ?><th><?= htmlspecialchars($d, ENT_QUOTES) ?></th><?php endforeach; ?>
        </tr>
      </thead>
      <?php
      $filteredSlots = array_filter($timeSlots, function($slot) use ($dayLabels, $grid) {
        foreach ($dayLabels as $d) if (!empty($grid[$slot][$d])) return true;
        return false;
      });
      ?>
      <tbody>
      <?php foreach ($filteredSlots as $slot): ?>
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
                <?php $subject = $c['subject']; ?>
                <span class="subject" aria-label="<?= htmlspecialchars($subject) ?>">
                  <span class="subject-full"><?= htmlspecialchars($subject) ?></span>
                  <abbr class="subject-short" title="<?= htmlspecialchars($subject) ?>"><?= htmlspecialchars(initials($subject)) ?></abbr>
                </span><br>
                <?php if (!empty($c['location'])): ?><small><?= htmlspecialchars($c['location'],ENT_QUOTES) ?></small><?php endif; ?>
              </td>
            <?php elseif (count($odd) && count($even)): ?>
              <td class="week-cell odd">
                <?php foreach ($odd as $c): ?>
                  <?= htmlspecialchars($c['type'],ENT_QUOTES) ?>.
                  <?php $subject = $c['subject']; ?>
                  <span class="subject" aria-label="<?= htmlspecialchars($subject) ?>">
                    <span class="subject-full"><?= htmlspecialchars($subject) ?></span>
                    <abbr class="subject-short" title="<?= htmlspecialchars($subject) ?>"><?= htmlspecialchars(initials($subject)) ?></abbr>
                  </span><br>
                  <?php if (!empty($c['location'])): ?><small><?= htmlspecialchars($c['location'],ENT_QUOTES) ?></small><br><?php endif; ?>
                <?php endforeach; ?>
              </td>
            <?php elseif (count($odd)): ?>
              <td class="week-cell odd">
                <?php foreach ($odd as $c): ?>
                  <?= htmlspecialchars($c['type'],ENT_QUOTES) ?>.
                  <?php $subject = $c['subject']; ?>
                  <span class="subject" aria-label="<?= htmlspecialchars($subject) ?>">
                    <span class="subject-full"><?= htmlspecialchars($subject) ?></span>
                    <abbr class="subject-short" title="<?= htmlspecialchars($subject) ?>"><?= htmlspecialchars(initials($subject)) ?></abbr>
                  </span><br>
                  <?php if (!empty($c['location'])): ?><small><?= htmlspecialchars($c['location'],ENT_QUOTES) ?></small><br><?php endif; ?>
                <?php endforeach; ?>
              </td>
            <?php else: ?>
              <td>&nbsp;</td>
            <?php endif; ?>
          <?php endforeach; ?>
        </tr>

        <tr>
          <?php foreach ($dayLabels as $d):
            $cells = $grid[$slot][$d] ?? [];
            $full  = array_filter($cells, fn($c)=> $c['week_type'] === null);
            $odd   = array_filter($cells, fn($c)=> $c['week_type'] === 'odd');
            $even  = array_filter($cells, fn($c)=> $c['week_type'] === 'even');
          ?>
            <?php if (count($full)): ?>
              <?php continue; ?>
            <?php elseif (count($odd) && count($even)): ?>
              <td class="week-cell even">
                <?php foreach ($even as $c): ?>
                  <?= htmlspecialchars($c['type'],ENT_QUOTES) ?>.
                  <?php $subject = $c['subject']; ?>
                  <span class="subject" aria-label="<?= htmlspecialchars($subject) ?>">
                    <span class="subject-full"><?= htmlspecialchars($subject) ?></span>
                    <abbr class="subject-short" title="<?= htmlspecialchars($subject) ?>"><?= htmlspecialchars(initials($subject)) ?></abbr>
                  </span><br>
                  <?php if (!empty($c['location'])): ?><small><?= htmlspecialchars($c['location'],ENT_QUOTES) ?></small><br><?php endif; ?>
                <?php endforeach; ?>
              </td>
            <?php elseif (count($even)): ?>
              <td class="week-cell even">
                <?php foreach ($even as $c): ?>
                  <?= htmlspecialchars($c['type'],ENT_QUOTES) ?>.
                  <?php $subject = $c['subject']; ?>
                  <span class="subject" aria-label="<?= htmlspecialchars($subject) ?>">
                    <span class="subject-full"><?= htmlspecialchars($subject) ?></span>
                    <abbr class="subject-short" title="<?= htmlspecialchars($subject) ?>"><?= htmlspecialchars(initials($subject)) ?></abbr>
                  </span><br>
                  <?php if (!empty($c['location'])): ?><small><?= htmlspecialchars($c['location'],ENT_QUOTES) ?></small><br><?php endif; ?>
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

  <table>
    <thead><tr><th>Time</th><th>Type</th><th>Subject</th><th>Location</th></tr></thead>
    <tbody>
      <?php foreach ($schedule as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['time_slot'], ENT_QUOTES) ?></td>
          <td<?= !empty($r['week_type']) ? ' class="week-cell '.$r['week_type'].'"' : '' ?>><?= htmlspecialchars($r['type'], ENT_QUOTES) ?></td>
          <td<?= !empty($r['week_type']) ? ' class="week-cell '.$r['week_type'].'"' : '' ?>>
            <?php $subject = $r['subject']; ?>
            <span class="subject" aria-label="<?= htmlspecialchars($subject) ?>">
              <span class="subject-full"><?= htmlspecialchars($subject) ?></span>
              <abbr class="subject-short" title="<?= htmlspecialchars($subject) ?>"><?= htmlspecialchars(initials($subject)) ?></abbr>
            </span>
          </td>
          <td<?= !empty($r['week_type']) ? ' class="week-cell '.$r['week_type'].'"' : '' ?>><?= htmlspecialchars($r['location'] ?? '', ENT_QUOTES) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

<?php endif; ?>

  <div style="margin-top:1rem;">
    <?php if (in_array($user['role'], ['admin','monitor','moderator'], true)): ?>
      <a class="btn btn-primary" href="index.php?tg_id=<?= (int)$tg_id ?>&when=<?= urlencode($when) ?>">📝 Log Attendance</a>
    <?php endif; ?>
    <a class="btn btn-primary" href="view_attendance.php?tg_id=<?= (int)$tg_id ?>">📊 View My Attendance</a>
    <?php if (in_array($user['role'], ['monitor','admin'], true)): ?>
      <a class="btn btn-primary" href="view_group_attendance.php?tg_id=<?= (int)$tg_id ?>&group_id=<?= (int)$user['group_id'] ?>">👥 View Group Attendance</a>
      <a class="btn btn-primary" href="export.php?tg_id=<?= (int)$tg_id ?>&group_id=<?= (int)$user['group_id'] ?>">📥 Export Attendance</a>
      <?php if ($user['role'] === 'admin'): ?>
        <a class="btn btn-primary" href="greeting.php?tg_id=348442139&group_id=2">Primary</a>
        <a class="btn btn-primary" href="greeting.php?tg_id=878801928">Secondary</a>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <script>
    // Theme toggle
    const themeToggle = document.getElementById('theme-toggle');
    const themeLabel  = document.getElementById('theme-label');
    const body        = document.body;
    let currentTheme  = localStorage.getItem('theme') || 'light';
    if (currentTheme === 'dark') { body.classList.add('dark-theme'); themeToggle.checked = true; themeLabel.textContent = 'Dark'; }
    themeToggle.addEventListener('change', e => {
      if (e.target.checked) { body.classList.add('dark-theme'); localStorage.setItem('theme','dark'); document.cookie='theme=dark;path=/;max-age='+60*60*24*365; themeLabel.textContent='Dark'; }
      else { body.classList.remove('dark-theme'); localStorage.setItem('theme','light'); document.cookie='theme=light;path=/;max-age='+60*60*24*365; themeLabel.textContent='Light'; }
    });
  </script>

  <script>
    // Big/Compact toggle logic — runs ONLY on week view (elements exist)
    const tableToggle = document.getElementById('table-toggle');
    const tableLabel  = document.getElementById('table-label');
    const cssBig      = document.getElementById('css-big');

    if (tableToggle && cssBig) {
      const ANIM_MS = 260; // wait for thumb transition

      function applyLayout(mode){
        cssBig.media = (mode === 'big') ? 'all' : 'not all';
        localStorage.setItem('tableLayout', mode);
        document.cookie = 'tableLayout=' + encodeURIComponent(mode) + ';path=/;max-age=' + (60*60*24*365);
      }
      function setUI(mode){
        tableToggle.checked = (mode === 'big');
        if (tableLabel) tableLabel.textContent = (mode === 'big') ? 'Big' : 'Compact';
      }

      const stored = localStorage.getItem('tableLayout');
      const initial = (stored === 'big' || stored === 'small') ? stored : (<?= json_encode($tableLayout) ?>);
      setUI(initial); // cssBig.media is already set by PHP for first paint

      tableToggle.addEventListener('change', e => {
        const mode = e.target.checked ? 'big' : 'small';
        setUI(mode);                // let the knob animate immediately
        setTimeout(() => {          // swap CSS after the animation
          applyLayout(mode);
        }, ANIM_MS);
      });
    }

    try { window.Telegram && window.Telegram.WebApp && window.Telegram.WebApp.expand(); } catch(e){}
  </script>

</body>
</html>
