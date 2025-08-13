<?php
// miniapp/export.php
ob_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

// — automatically detect current semester —
// Semester 2: February (2)–August (8)
// Semester 1: September (9)–January (1)
$month = intval(date('n'));  // 1–12
$currentSemester = ($month >= 9 || $month === 1) ? 1 : 2;

// — 1) Authenticate —
$tg_id = intval($_GET['tg_id'] ?? 0);
$user  = getUserByTgId($tg_id) ?: exit('Invalid user');
if (! in_array($user['role'], ['admin','monitor','moderator'], true)) {
    http_response_code(403);
    exit('Access denied');
}

$group_id = intval($_GET['group_id'] ?? $user['group_id']);
$action   = $_GET['action']   ?? '';

// — 2) Helper: render a styled HTML menu —
function render_page(string $title, array $links, int $tg_id, int $group_id) {
    ?><!DOCTYPE html>
    <html lang="en"><head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <title><?= htmlspecialchars($title) ?></title>
      <link rel="stylesheet" href="style.css">
      <script>
        // Bootstrap CSS behaviors: show theme switch; respect saved theme
        try {
          const h = document.documentElement;
          h.classList.add('js-ready');
          if (localStorage.getItem('theme') === 'dark') h.classList.add('dark-theme');
        } catch {}
      </script>
    </head>
    <body>
      <div id="theme-switch">
        <label class="switch">
          <input type="checkbox" id="theme-toggle">
          <span class="slider"></span>
        </label>
        <span id="theme-label">Light</span>
      </div>

      <br><br>

      <button class="btn btn-ghost btn-nav"
        onclick="location.href='greeting.php?tg_id=<?= $tg_id ?>'">
        ← Back to Schedule
      </button>

      <h2><?= htmlspecialchars($title) ?></h2>

      <div class="actions">
        <?php foreach ($links as $link): ?>
          <a class="btn btn-primary" href="<?= htmlspecialchars($link['url']) ?>">
            <?= htmlspecialchars($link['label']) ?>
          </a>
        <?php endforeach ?>
      </div>

      <script>
        const root   = document.documentElement;
        const toggle = document.getElementById('theme-toggle');
        const label  = document.getElementById('theme-label');
        (() => {
          const theme = localStorage.getItem('theme')||'light';
          toggle.checked = theme==='dark';
          root.classList.toggle('dark-theme', theme==='dark');
          label.textContent = theme==='dark'?'Dark':'Light';
        })();
        toggle.addEventListener('change', () => {
          const d = toggle.checked;
          root.classList.toggle('dark-theme', d);
          label.textContent = d?'Dark':'Light';
          localStorage.setItem('theme', d?'dark':'light');
        });
      </script>
    </body>
    </html><?php
    exit;
}

// — 3) Export Options menu —
if ($action === '') {
    $opts = [
      ['label'=>'This Week',  'url'=>"?tg_id={$tg_id}&action=week&group_id={$group_id}"],
      ['label'=>'This Month', 'url'=>"?tg_id={$tg_id}&action=month&group_id={$group_id}"],
      ['label'=>'All Time',   'url'=>"?tg_id={$tg_id}&action=all&group_id={$group_id}"],
      ['label'=>'By Subject', 'url'=>"?tg_id={$tg_id}&action=subject&group_id={$group_id}"],
      ['label'=>'By Student', 'url'=>"?tg_id={$tg_id}&action=student&group_id={$group_id}"],
    ];
    render_page('Export Options', $opts, $tg_id, $group_id);
}

// — 4) “By Subject” selection —
if ($action==='subject' && !isset($_GET['subject'])) {
    $sSt = $pdo->prepare("SELECT DISTINCT subject FROM schedule WHERE group_id = ?");
    $sSt->execute([$group_id]);
    $links = [];
    while ($s = $sSt->fetchColumn()) {
        $links[] = [
          'label'=>$s,
          'url'=>"?tg_id={$tg_id}&action=subject&subject=".urlencode($s)
                 ."&group_id={$group_id}"
        ];
    }
    render_page('Select Subject', $links, $tg_id, $group_id);
}

// — 5) “By Student” selection —
if ($action==='student' && !isset($_GET['student_id'])) {
    $uSt = $pdo->prepare("SELECT id,name FROM users WHERE group_id = ?");
    $uSt->execute([$group_id]);
    $links = [];
    while ($stu = $uSt->fetch(PDO::FETCH_ASSOC)) {
        $links[] = [
          'label'=>$stu['name'],
          'url'=>"?tg_id={$tg_id}&action=student&student_id={$stu['id']}&group_id={$group_id}"
        ];
    }
    render_page('Select Student', $links, $tg_id, $group_id);
}

// — 6) CSV export logic —

// fetch group name for file
$stmt = $pdo->prepare("SELECT name FROM `groups` WHERE id = ?");
$stmt->execute([$group_id]);
$group_name = $stmt->fetchColumn() ?: "Group #{$group_id}";

// build filters & params
$where  = '';
$params = [
    ':group_id' => $group_id,
    ':semester' => $currentSemester,
];

switch ($action) {
    case 'week':
        $from = date('Y-m-d', strtotime('monday this week'));
        $to   = date('Y-m-d');
        break;
    case 'month':
        $from = date('Y-m-01');
        $to   = date('Y-m-d');
        break;
    case 'all':
        $from = '1970-01-01';
        $to   = date('Y-m-d');
        break;
    case 'subject':
        $where                 = "AND s.subject = :subject";
        $params[':subject']    = $_GET['subject'];
        $from = '1970-01-01';
        $to   = date('Y-m-d');
        break;
    case 'student':
        $where                      = "AND a.user_id = :student_id";
        $params[':student_id']      = intval($_GET['student_id']);
        $from = '1970-01-01';
        $to   = date('Y-m-d');
        break;
    default:
        exit('Unknown action');
}

$params[':from'] = $from;
$params[':to']   = $to;

$sql = "
  SELECT a.date, s.subject, u.name AS student, a.present
    FROM attendance a
    JOIN schedule s ON s.id = a.schedule_id
    JOIN users   u ON u.id = a.user_id
   WHERE s.group_id    = :group_id
     AND s.semester    = :semester
     AND a.date BETWEEN :from AND :to
     $where
   ORDER BY s.subject, a.date, u.name
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// clear buffer before CSV output
while (ob_get_level()) { ob_end_clean(); }

// prepare filename
$safeGroup = preg_replace('/[^\w]+/u','_', mb_strtolower($group_name));
$fileDate  = date('d.m.Y');
$filename  = "{$safeGroup}_attendance_{$action}_{$fileDate}.csv";

header('Content-Type: text/csv; charset=UTF-8');
header("Content-Disposition: attachment; filename=\"$filename\"");

$out = fopen('php://output', 'w');
// BOM for Excel
fwrite($out, "\xEF\xBB\xBF");

// metadata
fputcsv($out, ['Group', $group_name], ';');
fputcsv($out, [], ';');

// pivot and output per-subject tables
$data = [];
foreach ($rows as $r) {
    $subj = $r['subject'];
    $dt   = date('d.m.Y', strtotime($r['date']));
    $stu  = $r['student'];
    $pres = $r['present'];
    $data[$subj]['dates'][$dt]          = true;
    $data[$subj]['students'][$stu][$dt] = $pres;
}

foreach ($data as $subj => $tbl) {
    $dates    = array_keys($tbl['dates']);
    sort($dates, SORT_STRING);
    $students = array_keys($tbl['students']);
    sort($students, SORT_STRING);

    fputcsv($out, ['Subject', $subj], ';');

    // date row
    $dateCells = array_map(fn($d) => "'".$d, $dates);
    fputcsv($out, array_merge(['Date'], $dateCells), ';');

    // present header row
    fputcsv($out, array_merge(['Student'], array_fill(0, count($dates), 'Present')), ';');

    // student rows
    foreach ($students as $stuName) {
        $row = [$stuName];
        foreach ($dates as $d) {
            $row[] = !empty($tbl['students'][$stuName][$d]) ? '' : 'a';
        }
        fputcsv($out, $row, ';');
    }

    // blank line between subjects
    fputcsv($out, [], ';');
}

fclose($out);
exit;
