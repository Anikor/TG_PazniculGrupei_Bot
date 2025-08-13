<?php
// miniapp/view_group_attendance.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

// 1) Who’s looking?
$tg_id = intval($_GET['tg_id'] ?? 0);
$me    = getUserByTgId($tg_id) ?: die('Unknown user');

// 2) Permission guard
if (!in_array($me['role'], ['admin','monitor'], true)) {
  die('Access denied');
}

// 3) Which group are we viewing? Default to viewer’s own group
$group_id = intval($_GET['group_id'] ?? $me['group_id']);

// 4) Date ranges
$today      = date('Y-m-d');
$weekStart  = date('Y-m-d', strtotime('monday this week'));
$monthStart = date('Y-m-01');
$periods = [
  'Today'      => [$today,      $today],
  'This Week'  => [$weekStart,  $today],
  'This Month' => [$monthStart, $today],
  'All Time'   => ['1970-01-01',$today],
];

// 5) Stats helper
function fetchStats(PDO $pdo, $uid, $from, $to=null) {
  $params = [':uid'=>$uid, ':from'=>$from];
  $cond   = "a.date >= :from";
  if ($to !== null) {
    $cond .= " AND a.date <= :to";
    $params[':to'] = $to;
  }

  // total rows
  $qTot = $pdo->prepare("SELECT COUNT(*) FROM attendance a WHERE a.user_id=:uid AND $cond");
  $qTot->execute($params);
  $total = (int)$qTot->fetchColumn();

  // absent rows
  $qAbs = $pdo->prepare("SELECT COUNT(*) FROM attendance a WHERE a.user_id=:uid AND $cond AND a.present=0");
  $qAbs->execute($params);
  $absent = (int)$qAbs->fetchColumn();

  return [$total, $absent];
}

// 6) Load students in group (alphabetical)
$stuQ = $pdo->prepare("
  SELECT id, name, tg_id
  FROM users
  WHERE group_id = ?
  ORDER BY name
");
$stuQ->execute([$group_id]);
$students = $stuQ->fetchAll(PDO::FETCH_ASSOC);

// 7) Per-student stats per period
$stats = [];
foreach ($students as $stu) {
  $sid = $stu['id'];
  foreach ($periods as $lbl => [$f, $t]) {
    [$tot, $abs] = fetchStats($pdo, $sid, $f, $t);
    $stats[$sid][$lbl] = [
      'total'  => $tot,
      'absent' => $abs,
      // percent present
      'rate'   => $tot ? round(100 * ($tot - $abs) / $tot, 2) : 0,
    ];
  }
}

// 8) Group summary (sum of student totals, then present %)
$sum = [];
foreach (array_keys($periods) as $lbl) {
  $sum[$lbl] = ['absent' => 0, 'total' => 0];
}
foreach ($students as $stu) {
  $sid = $stu['id'];
  foreach (array_keys($periods) as $lbl) {
    $sum[$lbl]['absent'] += $stats[$sid][$lbl]['absent'];
    $sum[$lbl]['total']  += $stats[$sid][$lbl]['total'];
  }
}
$summary = [];
foreach ($sum as $lbl => $vals) {
  $present = $vals['total'] - $vals['absent'];
  $pct     = $vals['total'] ? round(100 * $present / $vals['total'], 2) : 0;
  $summary[$lbl] = [
    'present' => $present,
    'total'   => $vals['total'],
    'pct'     => $pct,
  ];
}

// 9) Theme class from cookie (CSS handles .dark-theme)
$theme = (($_COOKIE['theme'] ?? 'light') === 'dark') ? 'dark' : 'light';

// 10) Group name (optional, if you have groups table)
$groupName = "Group {$group_id}";
$gQ = $pdo->prepare("SELECT name FROM groups WHERE id=?");
if ($gQ->execute([$group_id]) && ($n = $gQ->fetchColumn())) {
  $groupName = $n;
}
?>
<!DOCTYPE html>
<html lang="en" class="<?= $theme === 'dark' ? 'dark-theme' : '' ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Group Attendance</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

  <!-- Theme switch -->
  <div id="theme-switch">
    <label class="switch">
      <input type="checkbox" id="theme-toggle" <?= $theme === 'dark' ? 'checked' : '' ?>>
      <span class="slider"></span>
    </label>
    <span id="theme-label"><?= $theme === 'dark' ? 'Dark' : 'Light' ?></span>
  </div>

  <!-- Back to greeting/group -->
  <button class="btn-nav"
          onclick="location.href='greeting.php?tg_id=<?= $tg_id ?>&group_id=<?= $group_id ?>'">
    ← Back
  </button>

  <h1>Group Attendance: <?= htmlspecialchars($groupName, ENT_QUOTES) ?></h1>

  <!-- Summary cards -->
  <div class="stat-cards">
    <?php foreach ($summary as $lbl => $d): ?>
      <div class="stat">
        <h3><?= htmlspecialchars($lbl, ENT_QUOTES) ?></h3>
        <p><?= "{$d['present']}/{$d['total']} ({$d['pct']}%)" ?></p>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Attendance table -->
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Student</th>
        <?php foreach (array_keys($periods) as $lbl): ?>
          <th><?= htmlspecialchars($lbl, ENT_QUOTES) ?></th>
        <?php endforeach; ?>
        <th>View</th>
      </tr>
    </thead>
    <tbody>
      <?php $i = 1; foreach ($students as $stu): $sid = $stu['id']; ?>
        <tr>
          <td><?= $i++ ?></td>
          <td class="stu-name"><?= htmlspecialchars($stu['name'], ENT_QUOTES) ?></td>
          <?php foreach (array_keys($periods) as $lbl): $r = $stats[$sid][$lbl]; ?>
            <td><?= "{$r['absent']}/{$r['total']} (" . number_format($r['rate'], 2) . "%)" ?></td>
          <?php endforeach; ?>
          <td>
            <?php if (!empty($stu['tg_id'])): ?>
              <?php
                $qs = http_build_query([
                  'tg_id'      => $stu['tg_id'],
                  'return'     => 'group',
                  'monitor_id' => $tg_id,
                  'group_id'   => $group_id,
                ]);
              ?>
              <button class="btn-view" onclick="location.href='view_attendance.php?<?= $qs ?>'">View</button>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <script>
    // Theme toggle via localStorage + .dark-theme on <html>
    const toggle = document.getElementById('theme-toggle');
    const label  = document.getElementById('theme-label');

    (function(saved){
      const isDark = (saved === 'dark');
      document.documentElement.classList.toggle('dark-theme', isDark);
      label.textContent = isDark ? 'Dark' : 'Light';
      if (toggle) toggle.checked = isDark;
    })(localStorage.getItem('theme') || 'light');

    toggle.addEventListener('change', () => {
      const isDark = toggle.checked;
      document.documentElement.classList.toggle('dark-theme', isDark);
      label.textContent = isDark ? 'Dark' : 'Light';
      localStorage.setItem('theme', isDark ? 'dark' : 'light');
    });
  </script>

</body>
</html>
