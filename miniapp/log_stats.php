<?php
// miniapp/log_stats.php — Clean dashboard layout (single-period view)

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

// Who’s viewing?
$tg_id = (int)($_GET['tg_id'] ?? 0);
$user  = getUserByTgId($tg_id) ?: exit('Invalid user');
if (!in_array($user['role'] ?? 'student', ['admin','monitor','moderator'], true)) {
  http_response_code(403);
  exit('Access denied');
}

// Group filter (0 = All)
$selectedGroupId = (int)($_GET['group_id'] ?? ($user['group_id'] ?? 0));

// Period selector: w=Week, m=Month, a=All
$period = $_GET['period'] ?? 'w';
$period = in_array($period, ['w','m','a'], true) ? $period : 'w';

$tz = new DateTimeZone('Europe/Chisinau');
$today = new DateTimeImmutable('today', $tz);
$weekStart  = new DateTimeImmutable('monday this week', $tz);
$monthStart = $today->modify('first day of this month');

$rangeLabel = ['w'=>'This Week', 'm'=>'This Month', 'a'=>'All Time'][$period];
$start = $period === 'w' ? $weekStart->format('Y-m-d') : ($period === 'm' ? $monthStart->format('Y-m-d') : null);
$end   = $period === 'a' ? null : $today->format('Y-m-d');

// Groups for dropdown
$groups = $pdo->query("SELECT id,name FROM `groups` ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Helpers
function groupClause(&$p, $gid) { if ($gid > 0) { $p[':gid']=$gid; return " AND s.group_id=:gid "; } return ""; }
function dateClause(&$p, $start, $end, $col='a.date') {
  $cl=''; if ($start){$p[':start']=$start; $cl.=" AND $col>=:start ";} if($end){$p[':end']=$end; $cl.=" AND $col<=:end ";} return $cl;
}
function fetchUserNames(PDO $pdo, array $ids): array {
  if (!$ids) return [];
  $in = implode(',', array_fill(0, count($ids), '?'));
  $st = $pdo->prepare("SELECT id,name FROM users WHERE id IN ($in)");
  $st->execute($ids);
  $out=[]; while($r=$st->fetch(PDO::FETCH_ASSOC)) $out[(int)$r['id']]=$r['name'];
  return $out;
}

// Build KPIs (for selected group OR all groups)
$params=[]; $gc=groupClause($params,$selectedGroupId); $dc=dateClause($params,$start,$end,'a.date');
$sqlTotals="
  SELECT COUNT(*) total,
         SUM(a.present=1) present_cnt,
         SUM(a.motivated=1) mot_cnt,
         COUNT(DISTINCT a.marked_by) uniq_markers
  FROM attendance a
  JOIN schedule s ON s.id=a.schedule_id
  WHERE 1=1 $gc $dc
";
$stm=$pdo->prepare($sqlTotals); $stm->execute($params);
$tot=$stm->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'present_cnt'=>0,'mot_cnt'=>0,'uniq_markers'=>0];
$totalMarks=(int)($tot['total']??0);
$presentPct= $totalMarks? round(100*(int)$tot['present_cnt']/$totalMarks):0;
$motivPct  = $totalMarks? round(100*(int)$tot['mot_cnt']/$totalMarks):0;
$uniqMarkers=(int)($tot['uniq_markers']??0);

// Leaderboard: Top markers
$paramsM=[]; $gcM=groupClause($paramsM,$selectedGroupId); $dcM=dateClause($paramsM,$start,$end,'a.date');
$sqlM="
  SELECT a.marked_by uid, COUNT(*) cnt
  FROM attendance a
  JOIN schedule s ON s.id=a.schedule_id
  WHERE a.marked_by IS NOT NULL $gcM $dcM
  GROUP BY a.marked_by
  ORDER BY cnt DESC
  LIMIT 20
";
$rowsM=$pdo->prepare($sqlM); $rowsM->execute($paramsM); $rowsM=$rowsM->fetchAll(PDO::FETCH_ASSOC);
$namesM=fetchUserNames($pdo, array_values(array_unique(array_map('intval', array_column($rowsM,'uid')))));
$maxM=max([1, ...array_map(fn($r)=>(int)$r['cnt'],$rowsM)]);

// Leaderboard: Top editors
$paramsE=[]; $gcE=groupClause($paramsE,$selectedGroupId); $dcE=dateClause($paramsE,$start,$end,'l.changed_at');
$sqlE="
  SELECT l.changed_by uid, COUNT(*) cnt
  FROM attendance_log l
  JOIN attendance a ON a.id=l.attendance_id
  JOIN schedule  s ON s.id=a.schedule_id
  WHERE l.changed_by IS NOT NULL $gcE $dcE
  GROUP BY l.changed_by
  ORDER BY cnt DESC
  LIMIT 20
";
$rowsE=$pdo->prepare($sqlE); $rowsE->execute($paramsE); $rowsE=$rowsE->fetchAll(PDO::FETCH_ASSOC);
$namesE=fetchUserNames($pdo, array_values(array_unique(array_map('intval', array_column($rowsE,'uid')))));
$maxE=max([1, ...array_map(fn($r)=>(int)$r['cnt'],$rowsE)]);

// Breakdown: by subject
$paramsS=[]; $gcS=groupClause($paramsS,$selectedGroupId); $dcS=dateClause($paramsS,$start,$end,'a.date');
$sqlS="
  SELECT s.subject subj,
         COUNT(*)                 total,
         SUM(a.present=1)         present_cnt,
         SUM(a.motivated=1)       mot_cnt
  FROM attendance a
  JOIN schedule s ON s.id=a.schedule_id
  WHERE 1=1 $gcS $dcS
  GROUP BY s.subject
  ORDER BY total DESC, s.subject
  LIMIT 12
";
$rowsS=$pdo->prepare($sqlS); $rowsS->execute($paramsS); $rowsS=$rowsS->fetchAll(PDO::FETCH_ASSOC);

// Breakdown: by weekday
$paramsW=[]; $gcW=groupClause($paramsW,$selectedGroupId); $dcW=dateClause($paramsW,$start,$end,'a.date');
$sqlW="
  SELECT s.day_of_week dow,
         COUNT(*) total,
         SUM(a.present=1) present_cnt
  FROM attendance a
  JOIN schedule s ON s.id=a.schedule_id
  WHERE 1=1 $gcW $dcW
  GROUP BY s.day_of_week
  ORDER BY FIELD(s.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')
";
$rowsW=$pdo->prepare($sqlW); $rowsW->execute($paramsW); $rowsW=$rowsW->fetchAll(PDO::FETCH_ASSOC);
$maxW=max([1, ...array_map(fn($r)=>(int)$r['total'],$rowsW)]);

// Theme
$theme = (($_COOKIE['theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
$themeLabel = ($theme==='dark') ? 'Dark' : 'Light';

// Small helpers
function pct($num,$den){ return $den? round(100*$num/$den):0; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en" class="<?= $theme==='dark' ? 'dark-theme' : '' ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Log Statistics</title>
  <link rel="stylesheet" href="style.css?v=2">
  <script src="script.js" defer></script>
</head>
<body>

<!-- Top bar -->
<div class="topbar">
  <div class="left">
    <div>
    <a class="btn-nav" href="greeting.php?tg_id=<?= (int)$tg_id ?>&when=today">← Back to Schedule</a><br><br>
  <div id="theme-switch">
    <label class="switch">
      <input type="checkbox" id="theme-toggle" <?= $theme==='dark'?'checked':'' ?>>
      <span class="slider"></span>
    </label>
    <span id="theme-label"><?= h($themeLabel) ?></span>
  </div></div></div></div>
    <form method="get" class="filters">
      <input type="hidden" name="tg_id" value="<?= (int)$tg_id ?>">
      <label>Group:
        <select name="group_id">
          <option value="0"<?= $selectedGroupId===0?' selected':'' ?>>All</option>
          <?php foreach ($groups as $g): ?>
          <option value="<?= (int)$g['id'] ?>"<?= $selectedGroupId===(int)$g['id']?' selected':'' ?>><?= h($g['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <div class="segmented">
        <?php
          $base = 'log_stats.php?tg_id='.$tg_id.'&group_id='.$selectedGroupId.'&period=';
        ?>
        <a class="<?= $period==='w'?'active':'' ?>" href="<?= $base ?>w">Week</a>
        <a class="<?= $period==='m'?'active':'' ?>" href="<?= $base ?>m">Month</a>
        <a class="<?= $period==='a'?'active':'' ?>" href="<?= $base ?>a">All</a>
      </div>

      <button class="btn-nav" type="submit">Apply</button>
    </form>
  </div>



<h2>📊 Log Statistics — <?= h($rangeLabel) ?></h2>

<!-- KPI grid -->
<section class="kpis">
  <div class="kpi"><div class="kpi-label">Total marks</div><div class="kpi-value"><?= $totalMarks ?></div></div>
  <div class="kpi"><div class="kpi-label">Unique markers</div><div class="kpi-value"><?= $uniqMarkers ?></div></div>
  <div class="kpi"><div class="kpi-label">Present</div><div class="kpi-value"><?= $presentPct ?>%</div></div>
  <div class="kpi"><div class="kpi-label">Motivated</div><div class="kpi-value"><?= $motivPct ?>%</div></div>
</section>

<!-- Two-column leaderboards -->
<section class="grid-2">
  <div class="panel">
    <div class="panel-title">Top markers</div>
    <?php if (!$rowsM): ?>
      <p class="muted">No data.</p>
    <?php else: ?>
      <table class="compact">
        <thead><tr><th>#</th><th>User</th><th>Marks</th><th style="width:45%">Activity</th></tr></thead>
        <tbody>
          <?php $rank=1; foreach ($rowsM as $r): $cnt=(int)$r['cnt']; $nm=$namesM[(int)$r['uid']] ?? ('ID'.$r['uid']); ?>
          <tr>
            <td><?= $rank++ ?></td>
            <td><?= h($nm) ?></td>
            <td><?= $cnt ?></td>
            <td>
              <div class="progress"><span style="width:<?= round(100*$cnt/$maxM) ?>%"></span></div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="panel">
    <div class="panel-title">Top editors</div>
    <?php if (!$rowsE): ?>
      <p class="muted">No data.</p>
    <?php else: ?>
      <table class="compact">
        <thead><tr><th>#</th><th>User</th><th>Edits</th><th style="width:45%">Activity</th></tr></thead>
        <tbody>
          <?php $rank=1; foreach ($rowsE as $r): $cnt=(int)$r['cnt']; $nm=$namesE[(int)$r['uid']] ?? ('ID'.$r['uid']); ?>
          <tr>
            <td><?= $rank++ ?></td>
            <td><?= h($nm) ?></td>
            <td><?= $cnt ?></td>
            <td><div class="progress"><span style="width:<?= round(100*$cnt/$maxE) ?>%"></span></div></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<!-- Two-column breakdowns -->
<section class="grid-2">
  <div class="panel">
    <div class="panel-title">By subject</div>
    <?php if (!$rowsS): ?>
      <p class="muted">No data.</p>
    <?php else: ?>
      <table class="compact">
        <thead><tr><th>Subject</th><th>Total</th><th>Present%</th><th>Motivated%</th></tr></thead>
        <tbody>
          <?php foreach ($rowsS as $r): $t=(int)$r['total']; ?>
          <tr>
            <td><?= h($r['subj']) ?></td>
            <td><?= $t ?></td>
            <td><?= pct((int)$r['present_cnt'],$t) ?>%</td>
            <td><?= pct((int)$r['mot_cnt'],$t) ?>%</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="panel">
    <div class="panel-title">By weekday</div>
    <?php if (!$rowsW): ?>
      <p class="muted">No data.</p>
    <?php else: ?>
      <table class="compact">
        <thead><tr><th>Day</th><th>Total</th><th>Present%</th><th style="width:45%">Load</th></tr></thead>
        <tbody>
          <?php foreach ($rowsW as $r): $t=(int)$r['total']; ?>
          <tr>
            <td><?= h($r['dow']) ?></td>
            <td><?= $t ?></td>
            <td><?= pct((int)$r['present_cnt'],$t) ?>%</td>
            <td><div class="progress"><span style="width:<?= round(100*$t/$maxW) ?>%"></span></div></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

</body>
</html>
