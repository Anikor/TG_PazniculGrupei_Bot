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
  } catch (Throwable $e) { return null; }
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$tg_id = intval($_GET['tg_id'] ?? 0);
$user  = getUserByTgId($tg_id) ?: exit('Invalid user');
if (!in_array($user['role'], ['admin','monitor','moderator'], true)) {
  http_response_code(403);
  exit('Access denied');
}

$actor_tg = (int)($_GET['actor_tg'] ?? 0);
$actor    = $actor_tg ? getUserByTgId($actor_tg) : null;
$editorId = $actor['id'] ?? $user['id'];

/* Effective group (support Primary AI-241 view-mode) */
$requested_group_id = (int)($_GET['group_id'] ?? 0);
$effective_group_id = $requested_group_id > 0 ? $requested_group_id : (int)$user['group_id'];

/* Group name */
$stmt = $pdo->prepare("SELECT name FROM `groups` WHERE id=?");
$stmt->execute([$effective_group_id]);
$grp = $stmt->fetch(PDO::FETCH_ASSOC);
$groupName = $grp['name'] ?? 'Group ' . $effective_group_id;

$offset   = intval($_GET['offset'] ?? 0);
$date     = date('Y-m-d', strtotime(($offset >= 0 ? '+' : '').$offset.' days'));
$dayLabel = match (true) {
  $offset ===  0 => 'Today',
  $offset === -1 => 'Yesterday',
  default        => ($offset < 0 ? abs($offset).' days ago' : '+'.$offset.' days')
};

$tz = new DateTimeZone('Europe/Chisinau');
$dt = new DateTime($date, $tz);
[, , , $weekType] = computeSemesterAndWeek($dt);

/* Use proxy tg for schedule when overriding group */
if ($requested_group_id > 0) {
  $proxy_tg = proxyTgIdForGroup($pdo, $effective_group_id) ?? $tg_id;
  $schedule = getScheduleForDate($proxy_tg, $date, $weekType);
} else {
  $schedule = getScheduleForDate($tg_id, $date, $weekType);
}

/* Centralized restriction (pass $schedule for moderator’s dynamic cutoff) */
[$canEdit, $lockReason] = can_user_edit_for_date($actor['role'] ?? $user['role'] ?? '', new DateTimeImmutable($date, $tz), $tz, $schedule);
$editingLocked          = !$canEdit;

/* Save changes */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {

  header('Content-Type: application/json; charset=UTF-8');

  if ($editingLocked) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>$lockReason ?: 'Editing window closed.']);
    exit;
  }

  $raw  = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!$data || !isset($data['attendance'])) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Invalid payload']);
    exit;
  }

  $sel = $pdo->prepare("
    SELECT id,present,motivated,motivation
      FROM attendance
     WHERE date=:dt AND schedule_id=:sid AND user_id=:uid
    LIMIT 1
  ");
  $upd = $pdo->prepare("
    UPDATE attendance
       SET present=:pres, motivated=:mot, motivation=:reason,
           updated_at=NOW(), updated_by=:editor
     WHERE id=:att_id
  ");
  $log = $pdo->prepare("
    INSERT INTO attendance_log
      (attendance_id, changed_by,
       old_present, new_present,
       old_motivated, new_motivated,
       old_motivation, new_motivation)
    VALUES
      (:att_id, :editor,
       :old_pres, :new_pres,
       :old_mot, :new_mot,
       :old_reason, :new_reason)
  ");
  $ins = $pdo->prepare("
    INSERT INTO attendance
      (user_id, schedule_id, date, present, motivated, motivation, marked_by)
    VALUES
      (:uid, :sid, :dt, :pres, :mot, :reason, :editor)
  ");

  try {
    $pdo->beginTransaction();

    foreach ($data['attendance'] as $r) {
      $sid = (int)$r['schedule_id'];
      $uid = (int)$r['user_id'];

      $sel->execute([':dt'=>$date, ':sid'=>$sid, ':uid'=>$uid]);
      $old = $sel->fetch(PDO::FETCH_ASSOC);

      $new_pres   = !empty($r['present'])   ? 1 : 0;
      $new_mot    = !empty($r['motivated']) ? 1 : 0;
      $new_reason = trim((string)($r['motivation'] ?? '')) ?: null;

      if (!$old) {
        // First mark from edit page -> creates row with marked_by (no updated_by yet)
        $ins->execute([
          ':uid'=>$uid, ':sid'=>$sid, ':dt'=>$date,
          ':pres'=>$new_pres, ':mot'=>$new_mot, ':reason'=>$new_reason,
          ':editor'=>$editorId,
        ]);
        continue;
      }

      if ($old['present'] != $new_pres ||
          $old['motivated'] != $new_mot ||
          $old['motivation'] !== $new_reason) {

        $log->execute([
          ':att_id'    => (int)$old['id'],
          ':editor'    => $editorId,
          ':old_pres'  => (int)$old['present'],
          ':new_pres'  => $new_pres,
          ':old_mot'   => (int)$old['motivated'],
          ':new_mot'   => $new_mot,
          ':old_reason'=> $old['motivation'],
          ':new_reason'=> $new_reason,
        ]);

        $upd->execute([
          ':pres'=>$new_pres, ':mot'=>$new_mot, ':reason'=>$new_reason,
          ':editor'=>$editorId, ':att_id'=>(int)$old['id'],
        ]);
      }
    }

    $pdo->commit();
    echo json_encode(['success'=>true]);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'DB error']);
  }
  exit;
}

/* Students from the effective group */
$students = getGroupStudents($effective_group_id);

/* Preload for edit UI */
$existing = [];
$markers  = [];
$updaters = [];
if (!empty($schedule)) {
  $sids = array_column($schedule, 'id');
  $in   = implode(',', array_fill(0, count($sids), '?'));
  $q = "SELECT schedule_id,user_id,present,motivated,motivation,
               marked_by,updated_at,updated_by
          FROM attendance
         WHERE date=? AND schedule_id IN($in)";
  $stmt = $pdo->prepare($q);
  $stmt->execute(array_merge([$date], $sids));

  $marker_ids = $editor_ids = [];
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $existing[$r['schedule_id']][$r['user_id']] = $r;
    if (!empty($r['marked_by']))  $marker_ids[] = (int)$r['marked_by'];
    if (!empty($r['updated_by'])) $editor_ids[] = (int)$r['updated_by'];
  }

  $all_ids = array_values(array_unique(array_merge($marker_ids, $editor_ids)));
  if ($all_ids) {
    $in2 = implode(',', array_fill(0, count($all_ids), '?'));
    $stm2 = $pdo->prepare("SELECT id,name FROM users WHERE id IN($in2)");
    $stm2->execute($all_ids);
    while ($u = $stm2->fetch(PDO::FETCH_ASSOC)) {
      $markers[$u['id']]  = $u['name'];
      $updaters[$u['id']] = $u['name'];
    }
  }
}

$theme      = (($_COOKIE['theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
$themeLabel = ($theme === 'dark') ? 'Dark' : 'Light';
?>
<!DOCTYPE html>
<html lang="en" class="<?= $theme==='dark' ? 'dark-theme' : '' ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Edit Attendance — <?= htmlspecialchars($dayLabel) ?> (<?= date('d.m.Y',strtotime($date)) ?>)</title>
  <link rel="stylesheet" href="style.css?v=1">
  <script src="script.js?v=nav-global-4" defer></script>
</head>
<body
  data-page="edit"
  data-day-label="<?= htmlspecialchars($dayLabel, ENT_QUOTES) ?>"
  data-date-dmy="<?= htmlspecialchars(date('d.m.Y', strtotime($date)), ENT_QUOTES) ?>"
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

<div class="page-header">
  <button class="btn-nav" onclick="history.back()">← Back</button>
</div>
<br>

<h2>Group: <?= htmlspecialchars($groupName, ENT_QUOTES) ?></h2><br>
<h2>Edit attendance for <?= htmlspecialchars($dayLabel, ENT_QUOTES) ?> (<?= date('d.m.Y',strtotime($date)) ?>)</h2>

<?php if ($editingLocked): ?>
  <div class="edit-info" style="margin:8px 0;color:#c00">
    <?= htmlspecialchars($lockReason) ?>
  </div>
<?php endif; ?>

<?php if (empty($schedule)): ?>
  <p style="color:red;">No lessons for this day.</p>
<?php else: ?>
  <?php $disabled = $editingLocked ? ' disabled' : ''; ?>
  <table>
    <thead>
      <tr>
        <th></th>
        <th>Student</th>
        <?php foreach ($schedule as $s): ?>
          <th><?= htmlspecialchars($s['time_slot']) ?><br><?= htmlspecialchars($s['subject']) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($students as $i=>$stu): ?>
      <tr>
        <td><?= $i+1 ?></td>
        <td><?= htmlspecialchars($stu['name']) ?></td>
        <?php foreach ($schedule as $s):
          $cell = $existing[$s['id']][$stu['id']] ?? null;
          $pres = $cell ? (int)$cell['present']   : 0;
          $mot  = $cell ? (int)$cell['motivated'] : 0;
          $txt  = $cell ? (string)($cell['motivation'] ?? '') : '';
          $markedByName = $cell && !empty($cell['marked_by']) ? ($markers[$cell['marked_by']] ?? ('ID'.$cell['marked_by'])) : null;
          $editedByName = $cell && !empty($cell['updated_by']) ? ($updaters[$cell['updated_by']] ?? ('ID'.$cell['updated_by'])) : null;
        ?>
        <td>
          <label class="switch">
            <input type="checkbox"
                   class="att-toggle"
                   id="att_<?= $s['id'] . '_' . $stu['id'] ?>"
                   <?= $pres ? 'checked' : '' ?><?= $disabled ?>>
            <span class="slider"></span>
          </label>

          <div class="mot-container" id="mot_cont_<?= $s['id'] . '_' . $stu['id'] ?>">
            <label>
              <input type="checkbox"
                     class="mot-toggle"
                     id="mot_<?= $s['id'] . '_' . $stu['id'] ?>"
                     <?= $mot ? 'checked' : '' ?><?= $disabled ?>>
              Motivated
            </label><br>
            <input type="text"
                   id="mot_text_<?= $s['id'] . '_' . $stu['id'] ?>"
                   class="motiv-text"
                   placeholder="Reason…"
                   value="<?= htmlspecialchars($txt, ENT_QUOTES) ?>"
                   <?= $disabled ?>>
          </div>

          <?php if ($cell): ?>
            <div class="edit-info" style="margin-top:.35rem">
              <?php if ($markedByName): ?>
                <div>Marked by <?= htmlspecialchars($markedByName, ENT_QUOTES) ?></div>
              <?php endif; ?>
              <?php if ($editedByName && !empty($cell['updated_at'])): ?>
                <div class="mot-edited">
                  <small>Last edited by <?= htmlspecialchars($editedByName, ENT_QUOTES) ?> at <?= htmlspecialchars($cell['updated_at'], ENT_QUOTES) ?></small>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if (!$editingLocked): ?>
    <button class="btn-submit">Save changes</button>
  <?php endif; ?>
<?php endif; ?>
</body>
</html>
