<<<<<<< Updated upstream
<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/oe_weeks.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

/* JSON SAVE */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {

  header('Content-Type: application/json; charset=UTF-8');

  $raw  = file_get_contents('php://input');
  $data = json_decode($raw, true);

  if (!$data || !isset($data['attendance']) || !isset($_GET['tg_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
  }

  $tg_id = (int)($_GET['tg_id'] ?? 0);
  $user  = getUserByTgId($tg_id);
  if (!$user) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown user']);
    exit;
  }

  $offset = (int)($_GET['offset'] ?? 0);
  $date   = date('Y-m-d', strtotime("$offset days"));

  $stmt = $pdo->prepare("
    INSERT INTO attendance
      (user_id, schedule_id, date, present, motivated, motivation, marked_by)
    VALUES
      (:uid, :sid, :dt, :pres, :mot, :reason, :mb)
  ");

  try {
    $pdo->beginTransaction();
    foreach ($data['attendance'] as $r) {
      $stmt->execute([
        ':uid'    => (int)$r['user_id'],
        ':sid'    => (int)$r['schedule_id'],
        ':dt'     => $date,
        ':pres'   => !empty($r['present'])   ? 1 : 0,
        ':mot'    => !empty($r['motivated']) ? 1 : 0,
        ':reason' => trim((string)($r['motivation'] ?? '')) ?: null,
        ':mb'     => (int)$user['id'],
      ]);
    }
    $pdo->commit();
    echo json_encode([
      'success' => true,
      'marked_by_name' => $user['name'] ?? 'Unknown',
      'date' => $date,
    ]);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'DB error']);
  }
  exit;
}

/* PAGE RENDER */
$tg_id  = (int)($_GET['tg_id']  ?? 0);
$offset = (int)($_GET['offset'] ?? 0);
$date   = date('Y-m-d', strtotime("$offset days"));

$dayLabel = match (true) {
  $offset ===  0 => 'Today',
  $offset === -1 => 'Yesterday',
  default        => abs($offset) . ' days ago'
};

$prev1 = $offset - 1;
$prev2 = $offset - 2;
$next1 = $offset + 1;

$user = getUserByTgId($tg_id) ?: exit('Invalid user');

$dt = new DateTime($date, new DateTimeZone('Europe/Chisinau'));
[, , , $weekType] = computeSemesterAndWeek($dt);

$schedule = getScheduleForDate($tg_id, $date, $weekType);
$students = getGroupStudents($user['group_id']);

$sids     = array_column($schedule, 'id');
$existing = [];
$markers  = [];

if ($sids) {
  $in = implode(',', array_fill(0, count($sids), '?'));
  $q = "SELECT schedule_id,user_id,present,motivated,motivation,marked_by,updated_at,updated_by
          FROM attendance
         WHERE date=?
           AND schedule_id IN ($in)";
  $stmt = $pdo->prepare($q);
  $stmt->execute(array_merge([$date], $sids));

  $teacherIds = [];
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $existing[$r['schedule_id']][$r['user_id']] = $r;
    if (!empty($r['marked_by'])) $teacherIds[] = (int)$r['marked_by'];
    if (!empty($r['updated_by'])) $teacherIds[] = (int)$r['updated_by'];
  }
  if ($teacherIds) {
    $teacherIds = array_values(array_unique($teacherIds));
    $in2  = implode(',', array_fill(0, count($teacherIds), '?'));
    $stm2 = $pdo->prepare("SELECT id,name FROM users WHERE id IN($in2)");
    $stm2->execute($teacherIds);
    while ($m = $stm2->fetch(PDO::FETCH_ASSOC)) {
      $markers[$m['id']] = $m['name'];
    }
  }
}

$stmG = $pdo->prepare("SELECT name FROM `groups` WHERE id=?");
$stmG->execute([$user['group_id']]);
$grp       = $stmG->fetch(PDO::FETCH_ASSOC);
$groupName = $grp['name'] ?? ('Group ' . $user['group_id']);

/* Server-paint theme to prevent flicker */
$theme = (($_COOKIE['theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
$themeLabel = ($theme === 'dark') ? 'Dark' : 'Light';
?>
<!DOCTYPE html>
<html lang="en" class="<?= $theme==='dark' ? 'dark-theme' : '' ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Log Attendance</title>
  <link rel="stylesheet" href="style.css?v=1">
  <!-- script.js should add html.js-ready; CSS can hide #theme-switch until then -->
  <script src="script.js?v=nav-global-2" defer></script>
</head>
<body
  data-day-label="<?= htmlspecialchars($dayLabel, ENT_QUOTES) ?>"
  data-date-dmy="<?= htmlspecialchars(date('d.m.Y', strtotime($date)), ENT_QUOTES) ?>"
  data-current-user-name="<?= htmlspecialchars($user['name'] ?? 'Unknown', ENT_QUOTES) ?>"
  data-tg-id="<?= (int)$tg_id ?>"
>
<br>
<!-- Theme toggle (pre-checked + correct label from server-side theme) -->
<div id="theme-switch">
  <label class="switch">
    <input type="checkbox" id="theme-toggle" <?= $theme === 'dark' ? 'checked' : '' ?>>
    <span class="slider"></span>
  </label>
  <span id="theme-label"><?= $themeLabel ?></span>
</div>
<br><br>

<!-- Navigation -->
<div style="margin-top:6px;">
  <button class="btn-nav" onclick="nav(<?= $prev2 ?>)">« <?= abs($prev2) ?>d</button>
  <button class="btn-nav" onclick="nav(<?= $prev1 ?>)">← <?= abs($prev1) ?>d</button>
  <?php if ($offset!==0): ?>
    <button class="btn-nav" onclick="nav(0)">Today</button>
  <?php endif; ?>
  <?php if ($offset < 0): ?>
    <button class="btn-nav" onclick="nav(<?= $next1 ?>)">→ 1d</button>
  <?php endif; ?>
  <button class="btn-nav" onclick="location.href='greeting.php?tg_id=<?= $tg_id ?>'">
    Back to Schedule
  </button>
</div>

<h2>Group: <?= htmlspecialchars($groupName, ENT_QUOTES) ?></h2>

<?php if (empty($schedule)): ?>
  <p style="color:red;font-weight:bold;">
    No lessons for <?= $dayLabel ?> (<?= date('d.m.Y', strtotime($date)) ?>).
  </p>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Student</th>
        <?php foreach ($schedule as $s): ?>
          <th>
            <?= htmlspecialchars($s['time_slot'], ENT_QUOTES) ?><br>
            <?= htmlspecialchars($s['subject'],   ENT_QUOTES) ?>
          </th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($students as $i=>$stu): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><?= htmlspecialchars($stu['name'], ENT_QUOTES) ?></td>
          <?php foreach ($schedule as $s): ?>
            <td>
              <?php if (isset($existing[$s['id']][$stu['id']])): 
                $a  = $existing[$s['id']][$stu['id']];
                $by = $markers[$a['marked_by']] ?? ("ID".$a['marked_by']);
                $updName = !empty($a['updated_by']) ? ($markers[$a['updated_by']] ?? ("ID".$a['updated_by'])) : null;
              ?>
                <label class="switch">
                  <input type="checkbox" disabled <?= $a['present'] ? 'checked':'' ?>>
                  <span class="slider"></span>
                </label>
                <div class="mot-reason">
                  <?php if (!empty($a['motivated']) && !empty($a['motivation'])): ?>
                    Reason: <?= htmlspecialchars($a['motivation'], ENT_QUOTES) ?><br>
                  <?php endif; ?>
                  <em>By <?= htmlspecialchars($by, ENT_QUOTES) ?></em>
                  <?php if (!empty($a['updated_at'])): ?>
                    <div class="mot-edited">
                      <small>Last edited by <?= htmlspecialchars($updName ?? '', ENT_QUOTES) ?> at <?= htmlspecialchars($a['updated_at'], ENT_QUOTES) ?></small>
                    </div>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <label class="switch">
                  <input type="checkbox" class="att-toggle" id="att_<?= $s['id'] ?>_<?= $stu['id'] ?>">
                  <span class="slider"></span>
                </label>
                <div class="mot-container" id="mot_cont_<?= $s['id'] ?>_<?= $stu['id'] ?>">
                  <label>
                    <input type="checkbox" class="mot-toggle" id="mot_<?= $s['id'] ?>_<?= $stu['id'] ?>">
                    Motivated
                  </label>
                  <input type="text" id="mot_text_<?= $s['id'] ?>_<?= $stu['id'] ?>" class="motiv-text" placeholder="Reason…">
                </div>
              <?php endif; ?>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div id="save-confirm">✅ Attendance saved for <?= date('d.m.Y', strtotime($date)) ?>!</div>

  <?php if (empty($existing)): ?>
    <button class="btn-submit">Submit Attendance</button>
  <?php else: ?>
    <button class="btn-edit" onclick="location.href='edit_attendance.php?tg_id=<?= $tg_id ?>&offset=<?= $offset ?>'">
      Edit Attendance
    </button>
  <?php endif; ?>

<?php endif; ?>
</body>
</html>
=======
<?php require_once __DIR__ . '/../config.php'; require_once __DIR__ . '/../db.php'; require_once __DIR__ . '/oe_weeks.php'; header('Access-Control-Allow-Origin: *'); header('Access-Control-Allow-Headers: Content-Type'); header('Access-Control-Allow-Methods: GET, POST, OPTIONS'); if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit; if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) { header('Content-Type: application/json; charset=UTF-8'); $raw = file_get_contents('php://input'); $data = json_decode($raw, true); if (!$data || !isset($data['attendance']) || !isset($_GET['tg_id'])) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'Invalid request']); exit; } $tg_id = (int)($_GET['tg_id'] ?? 0); $user = getUserByTgId($tg_id); if (!$user) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'Unknown user']); exit; } $offset = (int)($_GET['offset'] ?? 0); $date = date('Y-m-d', strtotime("$offset days")); $stmt = $pdo->prepare(" INSERT INTO attendance (user_id, schedule_id, date, present, motivated, motivation, marked_by) VALUES (:uid, :sid, :dt, :pres, :mot, :reason, :mb) "); try { $pdo->beginTransaction(); foreach ($data['attendance'] as $r) { $stmt->execute([':uid' => (int)$r['user_id'], ':sid' => (int)$r['schedule_id'], ':dt' => $date, ':pres' => !empty($r['present']) ? 1 : 0, ':mot' => !empty($r['motivated']) ? 1 : 0, ':reason' => trim((string)($r['motivation'] ?? '')) ?: null, ':mb' => (int)$user['id'], ]); } $pdo->commit(); echo json_encode(['success' => true, 'marked_by_name' => $user['name'] ?? 'Unknown', 'date' => $date, ]); } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); http_response_code(500); echo json_encode(['success'=>false,'error'=>'DB error']); } exit; } $tg_id = (int)($_GET['tg_id'] ?? 0); $offset = (int)($_GET['offset'] ?? 0); $date = date('Y-m-d', strtotime("$offset days")); $dayLabel = match (true) { $offset === 0 => 'Today', $offset === -1 => 'Yesterday', default => abs($offset) . ' days ago' }; $prev1 = $offset - 1; $prev2 = $offset - 2; $next1 = $offset + 1; $user = getUserByTgId($tg_id) ?: exit('Invalid user'); $dt = new DateTime($date, new DateTimeZone('Europe/Chisinau')); [, , , $weekType] = computeSemesterAndWeek($dt); $schedule = getScheduleForDate($tg_id, $date, $weekType); $students = getGroupStudents($user['group_id']); $sids = array_column($schedule, 'id'); $existing = []; $markers = []; if ($sids) { $in = implode(',', array_fill(0, count($sids), '?')); $q = "SELECT schedule_id,user_id,present,motivated,motivation,marked_by,updated_at,updated_by FROM attendance WHERE date=? AND schedule_id IN ($in)"; $stmt = $pdo->prepare($q); $stmt->execute(array_merge([$date], $sids)); $teacherIds = []; while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) { $existing[$r['schedule_id']][$r['user_id']] = $r; if (!empty($r['marked_by'])) $teacherIds[] = (int)$r['marked_by']; if (!empty($r['updated_by'])) $teacherIds[] = (int)$r['updated_by']; } if ($teacherIds) { $teacherIds = array_values(array_unique($teacherIds)); $in2 = implode(',', array_fill(0, count($teacherIds), '?')); $stm2 = $pdo->prepare("SELECT id,name FROM users WHERE id IN($in2)"); $stm2->execute($teacherIds); while ($m = $stm2->fetch(PDO::FETCH_ASSOC)) { $markers[$m['id']] = $m['name']; } } } $stmG = $pdo->prepare("SELECT name FROM `groups` WHERE id=?"); $stmG->execute([$user['group_id']]); $grp = $stmG->fetch(PDO::FETCH_ASSOC); $groupName = $grp['name'] ?? ('Group ' . $user['group_id']); $theme = (($_COOKIE['theme'] ?? 'light') === 'dark') ? 'dark' : 'light'; $themeLabel = ($theme === 'dark') ? 'Dark' : 'Light'; ?> <!DOCTYPE html><html lang="en" class="<?= $theme==='dark' ? 'dark-theme' : '' ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Log Attendance</title><link rel="stylesheet" href="style.css?v=1"><script src="script.js?v=nav-global-2" defer></script></head><body data-day-label="<?= htmlspecialchars($dayLabel, ENT_QUOTES) ?>" data-date-dmy="<?= htmlspecialchars(date('d.m.Y', strtotime($date)), ENT_QUOTES) ?>" data-current-user-name="<?= htmlspecialchars($user['name'] ?? 'Unknown', ENT_QUOTES) ?>" data-tg-id="<?= (int)$tg_id ?>"> <br><div id="theme-switch"><label class="switch"><input type="checkbox" id="theme-toggle" <?= $theme === 'dark' ? 'checked' : '' ?>><span class="slider"></span></label><span id="theme-label"><?= $themeLabel ?></span></div><br><br><div style="margin-top:6px;"><button class="btn-nav" onclick="nav(<?= $prev2 ?>)">« <?= abs($prev2) ?>d</button><button class="btn-nav" onclick="nav(<?= $prev1 ?>)">← <?= abs($prev1) ?>d</button> <?php if ($offset!==0): ?> <button class="btn-nav" onclick="nav(0)">Today</button> <?php endif; ?> <?php if ($offset < 0): ?> <button class="btn-nav" onclick="nav(<?= $next1 ?>)">→ 1d</button> <?php endif; ?> <button class="btn-nav" onclick="location.href='greeting.php?tg_id=<?= $tg_id ?>'">Back to Schedule</button></div><h2>Group: <?= htmlspecialchars($groupName, ENT_QUOTES) ?></h2> <?php if (empty($schedule)): ?> <p style="color:red;font-weight:bold;">No lessons for <?= $dayLabel ?> (<?= date('d.m.Y', strtotime($date)) ?>).</p> <?php else: ?> <table><thead><tr><th><th>Student</th> <?php foreach ($schedule as $s): ?> <th><?= htmlspecialchars($s['time_slot'], ENT_QUOTES) ?><br><?= htmlspecialchars($s['subject'], ENT_QUOTES) ?></th> <?php endforeach; ?> </tr></thead><tbody> <?php foreach ($students as $i=>$stu): ?> <tr><td><?= $i+1 ?></td><td><?= htmlspecialchars($stu['name'], ENT_QUOTES) ?></td> <?php foreach ($schedule as $s): ?> <td> <?php if (isset($existing[$s['id']][$stu['id']])): $a = $existing[$s['id']][$stu['id']]; $by = $markers[$a['marked_by']] ?? ("ID".$a['marked_by']); $updName = !empty($a['updated_by']) ? ($markers[$a['updated_by']] ?? ("ID".$a['updated_by'])) : null; ?> <label class="switch"><input type="checkbox" disabled <?= $a['present'] ? 'checked':'' ?>><span class="slider"></span></label><div class="mot-reason"> <?php if (!empty($a['motivated']) && !empty($a['motivation'])): ?> Reason: <?= htmlspecialchars($a['motivation'], ENT_QUOTES) ?><br> <?php endif; ?> <em>By <?= htmlspecialchars($by, ENT_QUOTES) ?></em> <?php if (!empty($a['updated_at'])): ?> <div class="mot-edited"><small>Last edited by <?= htmlspecialchars($updName ?? '', ENT_QUOTES) ?> at <?= htmlspecialchars($a['updated_at'], ENT_QUOTES) ?></small></div> <?php endif; ?> </div> <?php else: ?> <label class="switch"><input type="checkbox" class="att-toggle" id="att_<?= $s['id'] ?>_<?= $stu['id'] ?>"><span class="slider"></span></label><div class="mot-container" id="mot_cont_<?= $s['id'] ?>_<?= $stu['id'] ?>"><label><input type="checkbox" class="mot-toggle" id="mot_<?= $s['id'] ?>_<?= $stu['id'] ?>">Motivated</label><input type="text" id="mot_text_<?= $s['id'] ?>_<?= $stu['id'] ?>" class="motiv-text" placeholder="Reason…"></div> <?php endif; ?> </td> <?php endforeach; ?> </tr> <?php endforeach; ?> </tbody></table><div id="save-confirm">✅ Attendance saved for <?= date('d.m.Y', strtotime($date)) ?>!</div> <?php if (empty($existing)): ?> <button class="btn-submit">Submit Attendance</button> <?php else: ?> <button class="btn-edit" onclick="location.href='edit_attendance.php?tg_id=<?= $tg_id ?>&offset=<?= $offset ?>'">Edit Attendance</button> <?php endif; ?> <?php endif; ?> </body></html>
>>>>>>> Stashed changes
