<?php
declare(strict_types=1);

/**
 * schedule_builder.php — admin-only drag & drop schedule constructor.
 *
 * Replaces the per-term routine of editing `schedule` by hand in phpMyAdmin.
 * GET renders the builder; POST (application/json) is its API:
 *
 *   {action:"save", rows:[…]}            full desired schedule, all groups
 *   {action:"new_semester", confirm:"…"} wipe attendance_log/attendance/schedule
 *
 * Save is a diff, not a reseed: rows that keep their id are UPDATEd in place,
 * so attendance already logged against them stays linked. A row that has
 * attendance can never be deleted by a save — only by the explicit term reset.
 */

require_once __DIR__.'/config.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/oe_weeks.php';
require_once __DIR__.'/tg_auth.php';

$me = tg_require_auth();
tg_require_role($me, ['admin']);

const SB_DAYS  = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
const SB_TYPES = ['curs','sem','lab'];
const SB_WEEKS = ['odd','even'];
const SB_RESET_PHRASE = 'NEW SEMESTER';

[$currentSemester, , $currentWeek, $currentWeekType] =
    computeSemesterAndWeek(new DateTime('today', new DateTimeZone(APP_TZ)));

function sb_json(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Everything the client needs to (re)draw: rows + attendance count per row. */
function sb_load_state(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT id, group_id, day_of_week, time_slot, subject, location, type, week_type, subgroup
           FROM schedule
          ORDER BY group_id,
                   FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
                   STR_TO_DATE(SUBSTRING_INDEX(time_slot,'-',1), '%H:%i'), week_type, subgroup, id"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id']       = (int)$r['id'];
        $r['group_id'] = (int)$r['group_id'];
        $r['subgroup'] = $r['subgroup'] === null ? null : (int)$r['subgroup'];
    }
    unset($r);

    $att = [];
    foreach ($pdo->query("SELECT schedule_id, COUNT(*) c FROM attendance GROUP BY schedule_id") as $a) {
        $att[(int)$a['schedule_id']] = (int)$a['c'];
    }
    return ['rows' => $rows, 'attCount' => (object)$att];
}

/** "" / whitespace → null, otherwise the trimmed string. */
function sb_nullable($v): ?string
{
    if ($v === null) return null;
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

/**
 * Validate one client row. Returns [cleanRow, null] or [null, "error"].
 * Mirrors the column definitions in init_db.sql — enums and lengths are
 * checked here so a bad payload is a clean 400, not a MySQL truncation.
 */
function sb_validate_row($r, array $groupIds, int $n): array
{
    $where = 'Row '.($n + 1).': ';
    if (!is_array($r)) return [null, $where.'not an object.'];

    $id = $r['id'] ?? null;
    if ($id !== null && (!is_int($id) || $id <= 0)) return [null, $where.'bad id.'];

    $gid = $r['group_id'] ?? null;
    if (!is_int($gid) || !in_array($gid, $groupIds, true)) return [null, $where.'unknown group.'];

    $day = (string)($r['day_of_week'] ?? '');
    if (!in_array($day, SB_DAYS, true)) return [null, $where.'bad day.'];

    $slot = (string)($r['time_slot'] ?? '');
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-3]):([0-5]\d)$/', $slot, $m)
        || ((int)$m[1] * 60 + (int)$m[2]) >= ((int)$m[3] * 60 + (int)$m[4])) {
        return [null, $where.'time slot must be HH:MM-HH:MM with start before end.'];
    }

    $subject = trim((string)($r['subject'] ?? ''));
    if ($subject === '' || mb_strlen($subject) > 50) return [null, $where.'subject must be 1–50 characters.'];

    $location = sb_nullable($r['location'] ?? null);
    if ($location !== null && mb_strlen($location) > 20) return [null, $where.'room must be at most 20 characters.'];

    $type = sb_nullable($r['type'] ?? null);
    if ($type !== null && !in_array($type, SB_TYPES, true)) return [null, $where.'bad lesson type.'];

    $week = sb_nullable($r['week_type'] ?? null);
    if ($week !== null && !in_array($week, SB_WEEKS, true)) return [null, $where.'bad week type.'];

    $sg = $r['subgroup'] ?? null;
    if ($sg !== null && $sg !== 1 && $sg !== 2) return [null, $where.'subgroup must be 1, 2 or empty.'];

    return [[
        'id' => $id, 'group_id' => $gid, 'day_of_week' => $day, 'time_slot' => $slot,
        'subject' => $subject, 'location' => $location, 'type' => $type,
        'week_type' => $week, 'subgroup' => $sg,
    ], null];
}

// ---------------------------------------------------------------------------
// API
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        sb_json(415, ['success' => false, 'error' => 'JSON only']);
    }
    tg_require_same_origin();

    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) sb_json(400, ['success' => false, 'error' => 'Invalid payload']);
    $action = (string)($data['action'] ?? '');

    if ($action === 'save') {
        if (!isset($data['rows']) || !is_array($data['rows']) || count($data['rows']) > 1000) {
            sb_json(400, ['success' => false, 'error' => 'Invalid payload']);
        }
        $groupIds = array_map('intval', $pdo->query("SELECT id FROM `groups`")->fetchAll(PDO::FETCH_COLUMN));

        $clean = []; $seen = [];
        foreach (array_values($data['rows']) as $n => $r) {
            [$row, $err] = sb_validate_row($r, $groupIds, $n);
            if ($err !== null) sb_json(400, ['success' => false, 'error' => $err]);
            if ($row['id'] !== null) {
                if (isset($seen[$row['id']])) sb_json(400, ['success' => false, 'error' => 'Duplicate id '.$row['id'].'.']);
                $seen[$row['id']] = true;
            }
            $clean[] = $row;
        }

        try {
            $pdo->beginTransaction();

            $dbRows = [];
            foreach ($pdo->query("SELECT id, group_id, day_of_week, time_slot, subject FROM schedule FOR UPDATE") as $d) {
                $dbRows[(int)$d['id']] = $d;
            }

            foreach ($clean as $row) {
                if ($row['id'] === null) continue;
                if (!isset($dbRows[$row['id']])) {
                    $pdo->rollBack();
                    sb_json(409, ['success' => false, 'error' => 'The schedule changed on the server (lesson #'.$row['id'].' no longer exists). Reload the page.']);
                }
                // Attendance belongs to the group's students; re-homing a lesson
                // would silently attach it to the wrong group. Client copies instead.
                if ((int)$dbRows[$row['id']]['group_id'] !== $row['group_id']) {
                    $pdo->rollBack();
                    sb_json(400, ['success' => false, 'error' => 'A saved lesson cannot move to another group.']);
                }
            }

            $toDelete = array_values(array_diff(array_keys($dbRows), array_keys($seen)));
            if ($toDelete) {
                $in = implode(',', array_fill(0, count($toDelete), '?'));
                $q = $pdo->prepare("SELECT schedule_id, COUNT(*) c FROM attendance WHERE schedule_id IN ($in) GROUP BY schedule_id");
                $q->execute($toDelete);
                $blocked = [];
                foreach ($q as $b) {
                    $d = $dbRows[(int)$b['schedule_id']];
                    $blocked[] = $d['subject'].' ('.$d['day_of_week'].' '.$d['time_slot'].', '.(int)$b['c'].' records)';
                }
                if ($blocked) {
                    $pdo->rollBack();
                    sb_json(409, ['success' => false, 'error' => 'Not saved — these lessons already have attendance and cannot be removed: '.implode('; ', $blocked).'. Reload to restore them.']);
                }
                $pdo->prepare("DELETE FROM schedule WHERE id IN ($in)")->execute($toDelete);
            }

            $upd = $pdo->prepare(
                "UPDATE schedule SET day_of_week=:d, time_slot=:t, subject=:s, location=:l, type=:ty, week_type=:w, subgroup=:sg WHERE id=:id"
            );
            // export.php filters on semester = the computed current one, so new
            // rows must carry it or they would be invisible to exports.
            $ins = $pdo->prepare(
                "INSERT INTO schedule (group_id, day_of_week, time_slot, subject, location, type, week_type, subgroup, semester)
                 VALUES (:g, :d, :t, :s, :l, :ty, :w, :sg, :sem)"
            );
            $updated = 0; $inserted = 0;
            foreach ($clean as $row) {
                $p = [':d' => $row['day_of_week'], ':t' => $row['time_slot'], ':s' => $row['subject'],
                      ':l' => $row['location'], ':ty' => $row['type'], ':w' => $row['week_type'], ':sg' => $row['subgroup']];
                if ($row['id'] !== null) {
                    $upd->execute($p + [':id' => $row['id']]);
                    $updated += $upd->rowCount();
                } else {
                    $ins->execute($p + [':g' => $row['group_id'], ':sem' => $currentSemester]);
                    $inserted++;
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('schedule_builder save failed: '.$e->getMessage());
            sb_json(500, ['success' => false, 'error' => 'Database error — nothing was saved.']);
        }

        error_log(sprintf('schedule_builder: user %d saved schedule (+%d ~%d -%d)', (int)$me['id'], $inserted, $updated, count($toDelete)));
        sb_json(200, ['success' => true, 'inserted' => $inserted, 'updated' => $updated, 'deleted' => count($toDelete)] + sb_load_state($pdo));
    }

    if ($action === 'new_semester') {
        if (($data['confirm'] ?? '') !== SB_RESET_PHRASE) {
            sb_json(400, ['success' => false, 'error' => 'Confirmation phrase does not match.']);
        }
        try {
            $pdo->beginTransaction();
            // FK order. DELETE, not TRUNCATE: TRUNCATE auto-commits and is refused on FK parents.
            $nLog = $pdo->exec("DELETE FROM attendance_log");
            $nAtt = $pdo->exec("DELETE FROM attendance");
            $nSch = $pdo->exec("DELETE FROM schedule");
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('schedule_builder new_semester failed: '.$e->getMessage());
            sb_json(500, ['success' => false, 'error' => 'Database error — nothing was deleted.']);
        }
        error_log(sprintf('schedule_builder: user %d started a new semester (-%d schedule, -%d attendance, -%d log)', (int)$me['id'], $nSch, $nAtt, $nLog));
        sb_json(200, ['success' => true, 'deleted' => ['schedule' => $nSch, 'attendance' => $nAtt, 'attendance_log' => $nLog]] + sb_load_state($pdo));
    }

    sb_json(400, ['success' => false, 'error' => 'Unknown action']);
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------
$groups = $pdo->query("SELECT id, name FROM `groups` ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
foreach ($groups as &$g) { $g['id'] = (int)$g['id']; }
unset($g);

$boot = [
    'groups'      => $groups,
    'semester'    => $currentSemester,
    'week'        => $currentWeek,
    'weekType'    => $currentWeekType,
    'resetPhrase' => SB_RESET_PHRASE,
] + sb_load_state($pdo);

$theme      = (($_COOKIE['theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
$themeClass = $theme === 'dark' ? 'dark-theme' : '';
header('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html>
<html lang="en" class="<?= $themeClass ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Schedule Builder</title>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<script src="<?= asset('script.js') ?>"></script>
<link rel="stylesheet" href="<?= asset('style.css') ?>">
<link rel="stylesheet" href="<?= asset('schedule_builder.css') ?>">
</head>
<body class="sb-page">
<div id="theme-switch"><label class="switch"><input type="checkbox" id="theme-toggle" <?= $theme === 'dark' ? 'checked' : '' ?>><span class="slider"></span></label><span id="theme-label"><?= $theme === 'dark' ? 'Dark' : 'Light' ?></span></div>

<div class="sb-head">
  <a class="btn-nav no-underline" href="greeting.php?when=week">← Back to Schedule</a>
  <h2>Schedule Builder</h2>
  <span class="muted">Semester <?= (int)$currentSemester ?> · week <?= (int)$currentWeek ?> (<?= htmlspecialchars($currentWeekType) ?>)</span>
</div>

<div class="sb-toolbar">
  <div class="segmented" id="sb-view" role="tablist" aria-label="Group"></div>
  <label class="sb-check"><input type="checkbox" id="sb-sat"> Saturday</label>
  <button type="button" class="btn-nav" id="sb-add-slot">+ Time slot</button>
  <span class="sb-spacer"></span>
  <span id="sb-dirty" class="sb-dirty" hidden>● Unsaved changes</span>
  <button type="button" class="btn-nav" id="sb-revert" disabled>Revert</button>
  <button type="button" class="btn-submit sb-save" id="sb-save" disabled>Save schedule</button>
</div>

<div id="sb-banner" class="sb-banner" role="status" hidden></div>

<p class="muted sb-hint">Drag a block into a slot (hold, then drag on touch) — or tap a block, then tap a slot. Blocks can be reused any number of times, in both groups.</p>

<section class="panel sb-palette-panel">
  <div class="sb-palette-head">
    <p class="panel-title">Lesson blocks</p>
    <input type="search" id="sb-filter" class="sb-filter" placeholder="Filter…" aria-label="Filter lesson blocks" autocomplete="off">
    <button type="button" class="btn-nav" id="sb-nb-toggle" aria-expanded="false" aria-controls="sb-new-block">+ New block</button>
  </div>
  <form id="sb-new-block" class="sb-new-block" autocomplete="off" hidden>
    <input type="text" id="sb-nb-subject" name="subject" maxlength="50" placeholder="Subject" required>
    <select id="sb-nb-type" name="type" aria-label="Type"><option value="">no type</option><option value="curs">curs</option><option value="sem">sem</option><option value="lab">lab</option></select>
    <input type="text" id="sb-nb-room" name="room" maxlength="20" placeholder="Room" size="6">
    <button type="submit" class="btn-nav">Add</button>
  </form>
  <div id="sb-palette" class="sb-palette"></div>
</section>

<details id="sb-warnings" class="panel sb-warnings" hidden>
  <summary><span id="sb-warn-count"></span></summary>
  <ul id="sb-warn-list"></ul>
</details>

<div id="sb-grids"></div>

<div id="sb-trash" class="sb-trash" hidden>🗑 Drop here to remove</div>
<div id="sb-armed-pill" class="sb-armed-pill" hidden><span id="sb-armed-text"></span><button type="button" id="sb-armed-cancel">Cancel</button></div>

<section class="panel sb-danger">
  <p class="panel-title">New semester</p>
  <p class="muted">Deletes the whole schedule <b>and all attendance and edit history</b> for every group, so the new term starts from an empty grid. Students and groups are kept. Make a backup on the Pi first.</p>
  <button type="button" class="btn-nav sb-danger-btn" id="sb-reset">Start new semester…</button>
</section>

<div id="sb-modal" class="sb-modal" hidden><div class="sb-modal-card" role="dialog" aria-modal="true"></div></div>

<script type="application/json" id="sb-boot"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= asset('schedule_builder.js') ?>"></script>
</body>
</html>
