<?php
// miniapp/log_stats.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

// Who's viewing?
$tg_id = (int)($_GET['tg_id'] ?? 0);
$user  = getUserByTgId($tg_id) ?: exit('Invalid user');

// Access: monitors/moderators/admins; students can view their own marks,
// but this page aggregates group-level activity, so keep it to staff roles.
$role = $user['role'] ?? 'student';
if (!in_array($role, ['admin','monitor','moderator'], true)) {
  http_response_code(403);
  exit('Access denied');
}

// Optional filter: group_id (0 / missing => "All groups")
$selectedGroupId = (int)($_GET['group_id'] ?? 0);

// Theme
$theme = (($_COOKIE['theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
$themeLabel = ($theme === 'dark') ? 'Dark' : 'Light';

// Periods
$tz = new DateTimeZone('Europe/Chisinau');
$today = new DateTimeImmutable('today', $tz);
$weekStart  = new DateTimeImmutable('monday this week', $tz);
$monthStart = $today->modify('first day of this month');

$periods = [
  'This Week'  => [$weekStart->format('Y-m-d'), $today->format('Y-m-d')],
  'This Month' => [$monthStart->format('Y-m-d'), $today->format('Y-m-d')],
  'All Time'   => [null, null],
];

// Load all groups (for filter + loop)
$groups = [];
$stmt = $pdo->query("SELECT id, name FROM `groups` ORDER BY name");
while ($g = $stmt->fetch(PDO::FETCH_ASSOC)) $groups[] = $g;
if ($selectedGroupId > 0) {
  $groups = array_values(array_filter($groups, fn($g)=> (int)$g['id'] === $selectedGroupId));
}

// Helpers
function groupClause(&$params, $groupId) {
  if ($groupId > 0) { $params[':gid'] = $groupId; return " AND s.group_id = :gid "; }
  return "";
}
function dateClause(&$params, $start, $end, $col = 'a.date') {
  $clause = '';
  if ($start) { $params[':start'] = $start; $clause .= " AND $col >= :start "; }
  if ($end)   { $params[':end']   = $end;   $clause .= " AND $col <= :end "; }
  return $clause;
}
function fetchMarkers(PDO $pdo, array $ids): array {
  if (!$ids) return [];
  $in = implode(',', array_fill(0, count($ids), '?'));
  $stm = $pdo->prepare("SELECT id,name FROM users WHERE id IN ($in)");
  $stm->execute($ids);
  $out = [];
  while ($r = $stm->fetch(PDO::FETCH_ASSOC)) $out[(int)$r['id']] = $r['name'];
  return $out;
}
?>
<!DOCTYPE html>
<html lang="en" class="<?= $theme==='dark' ? 'dark-theme' : '' ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Log Statistics</title>
  <link rel="stylesheet" href="style.css?v=1">
  <script src="script.js" defer></script>
</head>
<body>
<br>
<div id="theme-switch">
  <label class="switch">
    <input type="checkbox" id="theme-toggle" <?= $theme === 'dark' ? 'checked' : '' ?>>
    <span class="slider"></span>
  </label>
  <span id="theme-label"><?= htmlspecialchars($themeLabel, ENT_QUOTES) ?></span>
</div>
<br>

<div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
  <button class="btn-nav" onclick="location.href='greeting.php?tg_id=<?= (int)$tg_id ?>&when=today'">← Back to Greeting</button>
  <form method="get" style="display:flex;gap:.5rem;align-items:center">
    <input type="hidden" name="tg_id" value="<?= (int)$tg_id ?>">
    <label>Group:
      <select name="group_id">
        <option value="0"<?= $selectedGroupId===0?' selected':'' ?>>All</option>
        <?php
        $allGroups = $pdo->query("SELECT id,name FROM `groups` ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allGroups as $g):
        ?>
        <option value="<?= (int)$g['id'] ?>"<?= $selectedGroupId===(int)$g['id']?' selected':'' ?>>
          <?= htmlspecialchars($g['name'], ENT_QUOTES) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn-nav" type="submit">Apply</button>
  </form>
</div>

<h2>📈 Log Statistics</h2>
<p style="margin: 6px 0 18px">Leaderboards for <em>marks</em> (who created entries) and <em>edits</em> (who changed entries), per group and period.</p>

<?php if (empty($groups)): ?>
  <p>No groups found.</p>
<?php endif; ?>

<?php
foreach ($groups as $grp):
  $gid = (int)$grp['id'];
  $gname = $grp['name'];

  echo '<h3 style="margin-top:1.5rem">Group: ' . htmlspecialchars($gname, ENT_QUOTES) . '</h3>';

  foreach ($periods as $label => [$start, $end]):

    // -------- Summary cards (total marks, unique markers, edits, present%, motivated%) --------
    $p = [];
    $gc = groupClause($p, $gid);
    $dc = dateClause($p, $start, $end, 'a.date');
    $sqlTotals = "
      SELECT
        COUNT(*) AS total,
        SUM(a.present=1) AS present_cnt,
        SUM(a.motivated=1) AS mot_cnt
      FROM attendance a
      JOIN schedule s ON s.id = a.schedule_id
      WHERE 1=1 $gc $dc
    ";
    $tot = $pdo->prepare($sqlTotals); $tot->execute($p);
    $T = $tot->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'present_cnt'=>0,'mot_cnt'=>0];
    $totalMarks = (int)($T['total'] ?? 0);
    $presentPct = $totalMarks ? round(100*(int)$T['present_cnt']/$totalMarks) : 0;
    $motivatedPct = $totalMarks ? round(100*(int)$T['mot_cnt']/$totalMarks) : 0;

    // Unique markers
    $p2 = [];
    $gc2 = groupClause($p2, $gid);
    $dc2 = dateClause($p2, $start, $end, 'a.date');
    $sqlUniqueMarkers = "
      SELECT COUNT(DISTINCT a.marked_by) AS uniq_markers
      FROM attendance a JOIN schedule s ON s.id=a.schedule_id
      WHERE a.marked_by IS NOT NULL $gc2 $dc2
    ";
    $stmUM = $pdo->prepare($sqlUniqueMarkers); $stmUM->execute($p2);
    $uniqMarkers = (int)($stmUM->fetchColumn() ?: 0);

    // Total edits in period (from log)
    $p3 = [];
    $gc3 = groupClause($p3, $gid);
    // attendance_log uses changed_at
    $dc3 = dateClause($p3, $start, $end, 'l.changed_at');
    $sqlEdits = "
      SELECT COUNT(*) AS edits
      FROM attendance_log l
      JOIN attendance a ON a.id=l.attendance_id
      JOIN schedule  s ON s.id=a.schedule_id
      WHERE 1=1 $gc3 $dc3
    ";
    $stmE = $pdo->prepare($sqlEdits); $stmE->execute($p3);
    $totalEdits = (int)($stmE->fetchColumn() ?: 0);

    echo '<div class="stat-cards">';
    echo '  <div class="stat"><h3>'.htmlspecialchars($label,ENT_QUOTES).'</h3><p>Total marks: <strong>'.$totalMarks.'</strong></p></div>';
    echo '  <div class="stat"><h3>Markers</h3><p>Unique: <strong>'.$uniqMarkers.'</strong></p></div>';
    echo '  <div class="stat"><h3>Edits</h3><p>Actions: <strong>'.$totalEdits.'</strong></p></div>';
    echo '  <div class="stat"><h3>Quality</h3><p>Present: <strong>'.$presentPct.'%</strong> &nbsp; • &nbsp; Motivated: <strong>'.$motivatedPct.'%</strong></p></div>';
    echo '</div>';

    // -------- Leaderboard: Markers (who created entries) --------
    $pM = [];
    $gcM = groupClause($pM, $gid);
    $dcM = dateClause($pM, $start, $end, 'a.date');
    $sqlMarkers = "
      SELECT a.marked_by AS uid, COUNT(*) AS cnt
      FROM attendance a
      JOIN schedule s ON s.id=a.schedule_id
      WHERE a.marked_by IS NOT NULL $gcM $dcM
      GROUP BY a.marked_by
      ORDER BY cnt DESC
      LIMIT 20
    ";
    $stmM = $pdo->prepare($sqlMarkers); $stmM->execute($pM);
    $rowsM = $stmM->fetchAll(PDO::FETCH_ASSOC);
    $idsM  = array_column($rowsM, 'uid');
    $namesM= fetchMarkers($pdo, array_values(array_unique(array_map('intval',$idsM))));

    // -------- Leaderboard: Editors (from full change log) --------
    $pL = [];
    $gcL = groupClause($pL, $gid);
    $dcL = dateClause($pL, $start, $end, 'l.changed_at');
    $sqlEditors = "
      SELECT l.changed_by AS uid, COUNT(*) AS cnt
      FROM attendance_log l
      JOIN attendance a ON a.id=l.attendance_id
      JOIN schedule  s ON s.id=a.schedule_id
      WHERE l.changed_by IS NOT NULL $gcL $dcL
      GROUP BY l.changed_by
      ORDER BY cnt DESC
      LIMIT 20
    ";
    $stmL = $pdo->prepare($sqlEditors); $stmL->execute($pL);
    $rowsL = $stmL->fetchAll(PDO::FETCH_ASSOC);
    $idsL  = array_column($rowsL, 'uid');
    $namesL= fetchMarkers($pdo, array_values(array_unique(array_map('intval',$idsL))));

    // -------- Bonus: Motivations added by marker (count where motivated=1 AND motivation not null) --------
    $pMo = [];
    $gcMo = groupClause($pMo, $gid);
    $dcMo = dateClause($pMo, $start, $end, 'a.date');
    $sqlMotiv = "
      SELECT a.marked_by AS uid, COUNT(*) AS cnt
      FROM attendance a
      JOIN schedule s ON s.id=a.schedule_id
      WHERE a.marked_by IS NOT NULL AND a.motivated=1 AND a.motivation IS NOT NULL $gcMo $dcMo
      GROUP BY a.marked_by
      ORDER BY cnt DESC
      LIMIT 20
    ";
    $stmMo = $pdo->prepare($sqlMotiv); $stmMo->execute($pMo);
    $rowsMo = $stmMo->fetchAll(PDO::FETCH_ASSOC);
    $idsMo  = array_column($rowsMo, 'uid');
    $namesMo= fetchMarkers($pdo, array_values(array_unique(array_map('intval',$idsMo))));

    // ---- Render 3 compact tables as “cards” ----
    echo '<div class="cards">';

    // Markers
    echo '<div class="card">';
    echo '<h3>Top markers — '.htmlspecialchars($label,ENT_QUOTES).'</h3>';
    if (!$rowsM) { echo '<p>No data.</p>'; }
    else {
      echo '<table><thead><tr><th>#</th><th>User</th><th>Marks</th></tr></thead><tbody>';
      $rank=1;
      foreach ($rowsM as $r) {
        $nm = $namesM[(int)$r['uid']] ?? ('ID'.$r['uid']);
        echo '<tr><td>'.$rank++.'</td><td>'.htmlspecialchars($nm,ENT_QUOTES).'</td><td>'.(int)$r['cnt'].'</td></tr>';
      }
      echo '</tbody></table>';
    }
    echo '</div>';

    // Editors
    echo '<div class="card">';
    echo '<h3>Top editors — '.htmlspecialchars($label,ENT_QUOTES).'</h3>';
    if (!$rowsL) { echo '<p>No data.</p>'; }
    else {
      echo '<table><thead><tr><th>#</th><th>User</th><th>Edits</th></tr></thead><tbody>';
      $rank=1;
      foreach ($rowsL as $r) {
        $nm = $namesL[(int)$r['uid']] ?? ('ID'.$r['uid']);
        echo '<tr><td>'.$rank++.'</td><td>'.htmlspecialchars($nm,ENT_QUOTES).'</td><td>'.(int)$r['cnt'].'</td></tr>';
      }
      echo '</tbody></table>';
    }
    echo '</div>';

    // Motivations
    echo '<div class="card">';
    echo '<h3>Motivations added — '.htmlspecialchars($label,ENT_QUOTES).'</h3>';
    if (!$rowsMo) { echo '<p>No data.</p>'; }
    else {
      echo '<table><thead><tr><th>#</th><th>User</th><th>Motivations</th></tr></thead><tbody>';
      $rank=1;
      foreach ($rowsMo as $r) {
        $nm = $namesMo[(int)$r['uid']] ?? ('ID'.$r['uid']);
        echo '<tr><td>'.$rank++.'</td><td>'.htmlspecialchars($nm,ENT_QUOTES).'</td><td>'.(int)$r['cnt'].'</td></tr>';
      }
      echo '</tbody></table>';
    }
    echo '</div>';

    echo '</div>'; // .cards

  endforeach; // each period
endforeach;   // each group
?>

</body>
</html>
