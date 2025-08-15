<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/oe_weeks.php';
require_once __DIR__ . '/time_restrict.php';

/* ───────── Helpers ───────── */
function proxyTgIdForGroup(PDO $pdo, int $group_id): ?int {
  try {
    $q = $pdo->prepare("SELECT tg_id FROM users WHERE group_id=? ORDER BY (role='student') DESC, id ASC LIMIT 1");
    $q->execute([$group_id]);
    $tg = $q->fetchColumn();
    return $tg ? (int)$tg : null;
  } catch (Throwable $e) {
    return null;
  }
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

/* ───────── JSON SAVE ───────── */
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

  // Who is marking (credit the ACTOR)
  $actor_tg = (int)($_GET['actor_tg'] ?? 0);
  $actor    = $actor_tg ? getUserByTgId($actor_tg) : null;
  $actorId  = $actor['id']   ?? $user['id'];
  $actorName= $actor['name'] ?? ($user['name'] ?? 'Unknown');

  $offset = (int)($_GET['offset'] ?? 0);
  $date   = date('Y-m-d', strtotime(($offset >= 0 ? '+' : '').$offset.' days'));

  // Determine effective group (override for Primary view-mode)
  $groupOverride      = (int)($_GET['group_id'] ?? 0);
  $effectiveGroupId   = $groupOverride > 0 ? $groupOverride : (int)$user['group_id'];

  // Build schedule for the target date using a proper proxy tg from the effective group
  $tz = new DateTimeZone('Europe/Chisinau');
  $dt = new DateTime($date, $tz);
  [, , , $weekType] = computeSemesterAndWeek($dt);

  if ($groupOverride > 0) {
    $proxyTg = proxyTgIdForGroup($pdo, $effectiveGroupId) ?? $tg_id;
    $schedule = getScheduleForDate($proxyTg, $date, $weekType);
  } else {
    $schedule = getScheduleForDate($tg_id, $date, $weekType);
  }

  // Centralized block (pass $schedule for dynamic moderator cutoff)
  [$canEdit, $lockReason] = can_user_edit_for_date($actor['role'] ?? $user['role'] ?? '', new DateTimeImmutable($date, $tz), $tz, $schedule);
  if (!$canEdit) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>$lockReason ?: 'Editing window closed.']);
    exit;
  }

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
        ':mb'     => (int)$actorId, // credit the ACTOR (you)
      ]);
    }
    $pdo->commit();
    echo json_encode([
      'success' => true,
      'marked_by_name' => $actorName,
      'date' => $date,
    ]);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'DB error']);
  }
  exit;
}

/* ───────── PAGE (GET) ───────── */
$tg_id    = (int)($_GET['tg_id']    ?? 0);
$offset   = (int)($_GET['offset']   ?? 0);
$actor_tg = (int)($_GET['actor_tg'] ?? 0);

$date   = date('Y-m-d', strtotime(($offset >= 0 ? '+' : '').$offset.' days'));
$dayLabel = match (true) {
  $offset ===  0 => 'Today',
  $offset === -1 => 'Yesterday',
  default        => ($offset < 0 ? abs($offset).' days ago' : '+'.$offset.' days')
};
$prev1 = $offset - 1;
$prev2 = $offset - 2;
$next1 = $offset + 1;

$user  = getUserByTgId($tg_id) ?: exit('Invalid user');
$actor = $actor_tg ? (getUserByTgId($actor_tg) ?: $user) : $user;

// Effective group (override means Primary AI-241 view-mode)
$requested_group_id  = (int)($_GET['group_id'] ?? 0);
$effective_group_id  = $requested_group_id > 0 ? $requested_group_id : (int)$user['group_id'];

$tz = new DateTimeZone('Europe/Chisinau');
$dt = new DateTime($date, $tz);
[, , , $weekType] = computeSemesterAndWeek($dt);

/* Use proxy tg for selected group so schedule matches AI-241 when Primary is on */
if ($requested_group_id > 0) {
  $proxy_tg = proxyTgIdForGroup($pdo, $effective_group_id) ?? $tg_id;
  $schedule = getScheduleForDate($proxy_tg, $date, $weekType);
  $students = getGroupStudents($effective_group_id);
} else {
  $schedule = getScheduleForDate($tg_id, $date, $weekType);
  $students = getGroupStudents($user['group_id']);
}

/* Group name based on effective group */
$stmG = $pdo->prepare("SELECT name FROM `groups` WHERE id=?");
$stmG->execute([$effective_group_id]);
$grp       = $stmG->fetch(PDO::FETCH_ASSOC);
$groupName = $grp['name'] ?? ('Group ' . $effective_group_id);

/* Preload existing marks for this day's schedule entries */
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
    if (!empty($r['marked_by']))  $teacherIds[] = (int)$r['marked_by'];
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

/* Centralized restriction for UI (pass schedule for moderator cutoff) */
[$canEdit, $lockReason] = can_user_edit_for_date($actor['role'] ?? $user['role'] ?? '', new DateTimeImmutable($date, $tz), $tz, $schedule);
$editingLocked          = !$canEdit;

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
  <script src="script.js?v=nav-global-3" defer></script>
</head>
<body
  data-day-label="<?= htmlspecialchars($dayLabel, ENT_QUOTES) ?>"
  data-date-dmy="<?= htmlspecialchars(date('d.m.Y', strtotime($date)), ENT_QUOTES) ?>"
  data-current-user-name="<?= htmlspecialchars(($actor['name'] ?? $user['name'] ?? 'Unknown'), ENT_QUOTES) ?>"
  data-tg-id="<?= (int)$tg_id ?>"
  data-edit-locked="<?= $editingLocked ? '1' : '0' ?>"
  data-lock-reason="<?= htmlspecialchars($lockReason ?? '', ENT_QUOTES) ?>"
>
<br>

<div id="theme-switch">
  <label class="switch">
    <input type="checkbox" id="theme-toggle" <?= $theme === 'dark' ? 'checked' : '' ?>>
    <span class="slider"></span>
  </label>
  <span id="theme-label"><?= $themeLabel ?></span>
</div>
<br><br>

<div style="margin-top:6px;">
  <button class="btn-nav" onclick="nav(<?= $prev2 ?>, <?= (int)$tg_id ?>, <?= (int)$actor_tg ?>)">« <?= abs($prev2) ?>d</button>
  <button class="btn-nav" onclick="nav(<?= $prev1 ?>, <?= (int)$tg_id ?>, <?= (int)$actor_tg ?>)">← <?= abs($prev1) ?>d</button>
  <?php if ($offset!==0): ?>
    <button class="btn-nav" onclick="nav(0, <?= (int)$tg_id ?>, <?= (int)$actor_tg ?>)">Today</button>
  <?php endif; ?>
  <?php if ($offset < 0): ?>
    <button class="btn-nav" onclick="nav(<?= $next1 ?>, <?= (int)$tg_id ?>, <?= (int)$actor_tg ?>)">→ 1d</button>
  <?php endif; ?>
  <button class="btn-nav" onclick="location.href='greeting.php?tg_id=<?= (int)$tg_id ?>&when=today'">
    Back to Schedule
  </button>
</div>

<h2>Group: <?= htmlspecialchars($groupName, ENT_QUOTES) ?></h2>

<?php if ($editingLocked): ?>
  <div class="edit-info" style="margin:10px 0;color:#c00">
    <?= htmlspecialchars($lockReason) ?>
  </div>
<?php endif; ?>

<?php if (empty($schedule)): ?>
  <p style="color:red;font-weight:bold;">
    No lessons for <?= $dayLabel ?> (<?= date('d.m.Y', strtotime($date)) ?>).
  </p>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th></th>
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
                <?php $disabled = $editingLocked ? ' disabled' : ''; ?>
                <label class="switch">
                  <input type="checkbox" class="att-toggle" id="att_<?= $s['id'] ?>_<?= $stu['id'] ?>"<?= $disabled ?>>
                  <span class="slider"></span>
                </label>
                <div class="mot-container" id="mot_cont_<?= $s['id'] ?>_<?= $stu['id'] ?>">
                  <label>
                    <input type="checkbox" class="mot-toggle" id="mot_<?= $s['id'] ?>_<?= $stu['id'] ?>"<?= $disabled ?>>
                    Motivated
                  </label>
                  <input type="text" id="mot_text_<?= $s['id'] ?>_<?= $stu['id'] ?>" class="motiv-text" placeholder="Reason…"<?= $disabled ?>>
                </div>
              <?php endif; ?>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div id="save-confirm">✅ Attendance saved for <?= date('d.m.Y', strtotime($date)) ?>!</div>

  <?php if (empty($existing) && !$editingLocked): ?>
    <button class="btn-submit">Submit Attendance</button>
  <?php elseif (!empty($existing) && !$editingLocked): ?>
    <!-- keep group_id when jumping to edit -->
    <button class="btn-edit" onclick="location.href='edit_attendance.php?tg_id=<?= (int)$tg_id ?>&offset=<?= (int)$offset ?>&actor_tg=<?= (int)$actor_tg ?>&group_id=<?= (int)$effective_group_id ?>'">
      Edit Attendance
    </button>
  <?php endif; ?>

<?php endif; ?>
<script>
  // nav(offset, tg, actor) helper keeps existing query (incl. group_id)
  function nav(off, tg, actor) {
    const url = new URL(location.href);
    const curG = url.searchParams.get('group_id'); // preserved automatically
    url.searchParams.set('tg_id', String(tg || ''));
    url.searchParams.set('offset', String(off));
    if (actor) url.searchParams.set('actor_tg', String(actor));
    url.searchParams.delete('when');
    location.href = url.toString();
  }
</script>
</body>
</html>
