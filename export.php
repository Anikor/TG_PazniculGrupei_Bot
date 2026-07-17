<?php
ob_start();
require_once __DIR__.'/config.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/oe_weeks.php';

// Semester from the one shared implementation instead of a private
// re-derivation that could drift from oe_weeks.php.
[$currentSemester] = computeSemesterAndWeek(new DateTime('today', new DateTimeZone(APP_TZ)));

require_once __DIR__.'/tg_auth.php';
$user = tg_require_auth();
tg_require_role($user, ['admin', 'monitor', 'moderator']);
$group_id = tg_resolve_group_id($user, $_GET['group_id'] ?? 0);
$action   = $_GET['action'] ?? '';

/**
 * fputcsv() wrapper. PHP 8.4+ deprecates omitting the $escape parameter, and
 * with display_errors on, that deprecation notice gets written straight into
 * the CSV output stream, corrupting the file. Pass it explicitly everywhere.
 */
function csv_row($out, array $fields): void {
    fputcsv($out, $fields, ';', '"', '\\');
}

function render_page(string $title, array $links, int $group_id): void {
    $theme = (($_COOKIE['theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
    $themeClass = ($theme === 'dark') ? 'dark-theme' : '';
    $themeLabel = ($theme === 'dark') ? 'Dark' : 'Light';
    ?><!DOCTYPE html><html lang="en" class="<?=$themeClass?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars($title)?></title><script src="<?= asset('script.js') ?>"></script><link rel="stylesheet" href="<?= asset('style.css') ?>"></head><body><div id="theme-switch"><label class="switch"><input type="checkbox" id="theme-toggle" <?=$theme==='dark'?'checked':''?>><span class="slider"></span></label><span id="theme-label"><?=$themeLabel?></span></div><br><br><button class="btn btn-ghost btn-nav" onclick="location.href='greeting.php'">← Back to Schedule</button><h2><?=htmlspecialchars($title)?></h2><div class="actions"><?php foreach($links as $link):?><a class="btn btn-primary" href="<?=htmlspecialchars($link['url'])?>"><?=htmlspecialchars($link['label'])?></a><?php endforeach?></div></body></html><?php
    exit;
}

if ($action === '') {
    $opts = [
        ['label'=>'This Week',  'url'=>"?action=week&group_id={$group_id}"],
        ['label'=>'This Month', 'url'=>"?action=month&group_id={$group_id}"],
        ['label'=>'All Time',   'url'=>"?action=all&group_id={$group_id}"],
        ['label'=>'By Subject', 'url'=>"?action=subject&group_id={$group_id}"],
        ['label'=>'By Student', 'url'=>"?action=student&group_id={$group_id}"],
    ];
    render_page('Export Options', $opts, $group_id);
}

if ($action === 'subject' && !isset($_GET['subject'])) {
    $sSt = $pdo->prepare("SELECT DISTINCT subject FROM schedule WHERE group_id = ?");
    $sSt->execute([$group_id]);
    $links = [];
    while ($s = $sSt->fetchColumn()) {
        $links[] = ['label'=>$s, 'url'=>"?action=subject&subject=".urlencode($s)."&group_id={$group_id}"];
    }
    render_page('Select Subject', $links, $group_id);
}

// Second step: subject chosen, no type yet → offer "All" plus only the lesson
// types this subject actually has (no empty Lab/Sem/Curs buttons).
if ($action === 'subject' && isset($_GET['subject']) && !isset($_GET['type'])) {
    $subject = is_array($_GET['subject']) ? '' : (string)$_GET['subject'];
    $tSt = $pdo->prepare("SELECT DISTINCT type FROM schedule WHERE group_id = ? AND subject = ? AND type IS NOT NULL AND type <> '' ORDER BY type");
    $tSt->execute([$group_id, $subject]);
    $types = $tSt->fetchAll(PDO::FETCH_COLUMN);
    $subjQ = urlencode($subject);
    $links = [['label'=>'All', 'url'=>"?action=subject&subject={$subjQ}&type=all&group_id={$group_id}"]];
    foreach ($types as $t) {
        $links[] = ['label'=>ucfirst($t), 'url'=>"?action=subject&subject={$subjQ}&type=".urlencode($t)."&group_id={$group_id}"];
    }
    render_page('Select Lesson Type — '.$subject, $links, $group_id);
}

if ($action === 'student' && !isset($_GET['student_id'])) {
    $uSt = $pdo->prepare("SELECT id,name FROM users WHERE group_id = ?");
    $uSt->execute([$group_id]);
    $links = [];
    while ($stu = $uSt->fetch(PDO::FETCH_ASSOC)) {
        $links[] = ['label'=>$stu['name'], 'url'=>"?action=student&student_id={$stu['id']}&group_id={$group_id}"];
    }
    render_page('Select Student', $links, $group_id);
}

$stmt = $pdo->prepare("SELECT name FROM `groups` WHERE id = ?");
$stmt->execute([$group_id]);
$group_name = $stmt->fetchColumn() ?: "Group #{$group_id}";

$where = '';
// Intentional: schedule.semester is maintained manually and the DB only ever
// holds ONE semester — it is dropped and reseeded each term (~every 3-4
// months). The filter guards against accidental cross-term leftovers, not
// against a second semester coexisting. Do not "fix" by removing it.
$params = [':group_id'=>$group_id, ':semester'=>$currentSemester];
switch ($action) {
    case 'week':
        $from = date('Y-m-d', strtotime('monday this week'));
        $to = date('Y-m-d');
        break;
    case 'month':
        $from = date('Y-m-01');
        $to = date('Y-m-d');
        break;
    case 'all':
        $from = '1970-01-01';
        $to = date('Y-m-d');
        break;
    case 'subject':
        $where = "AND s.subject = :subject";
        $params[':subject'] = is_array($_GET['subject']) ? '' : (string)$_GET['subject']; // ?subject[]=x would otherwise bind an array to PDO and 500
        $type = $_GET['type'] ?? 'all';
        if (!is_array($type) && $type !== 'all' && $type !== '') { // 'all' = every type; otherwise filter to the chosen curs/sem/lab
            $where .= " AND s.type = :type";
            $params[':type'] = (string)$type;
        }
        $from = '1970-01-01';
        $to = date('Y-m-d');
        break;
    case 'student':
        $where = "AND a.user_id = :student_id";
        $params[':student_id'] = intval($_GET['student_id']);
        $from = '1970-01-01';
        $to = date('Y-m-d');
        break;
    default:
        exit('Unknown action');
}
$params[':from'] = $from;
$params[':to'] = $to;

$sql = "SELECT a.date, s.subject, s.type, u.name AS student, a.present
        FROM attendance a
        JOIN schedule s ON s.id = a.schedule_id
        JOIN users u ON u.id = a.user_id
        WHERE s.group_id = :group_id AND s.semester = :semester AND a.date BETWEEN :from AND :to $where
        ORDER BY s.subject, a.date, u.name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

while (ob_get_level()) { ob_end_clean(); }

$safeGroup = preg_replace('/[^\w]+/u', '_', mb_strtolower($group_name));
$fileDate = date('d.m.Y');
$actionLabel = $action;
if ($action === 'subject') {
    $subjSafe = preg_replace('/[^\w]+/u', '_', mb_strtolower((string)($_GET['subject'] ?? '')));
    $typeSafe = preg_replace('/[^\w]+/u', '_', mb_strtolower((string)($_GET['type'] ?? 'all')));
    $actionLabel = "subject_{$subjSafe}_{$typeSafe}";
}
$filename = "{$safeGroup}_attendance_{$actionLabel}_{$fileDate}.csv";
header('Content-Type: text/csv; charset=UTF-8');
header("Content-Disposition: attachment; filename=\"$filename\"");

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
csv_row($out, ['Group', csv_safe($group_name)]);
csv_row($out, []);

// Group by subject, keyed by the ISO date (YYYY-MM-DD) from the DB so it
// sorts chronologically. Sorting the already-formatted 'd.m.Y' string (as
// this used to) sorts by day-of-month first, e.g. 04.02.2026 lands after
// 03.04.2026 — wrong. Format for display only after sorting.
$data = [];
foreach ($rows as $r) {
    $subj = $r['subject'];
    // #3: one column per (date, lesson type) — curs and sem on the same day
    // get SEPARATE columns, labeled by a type row under the date row. If the
    // same type somehow repeats that day, sessions merge via max (present in
    // any session of that type = present) so the cell stays deterministic.
    $col  = $r['date'] . '|' . (string)($r['type'] ?? '');
    $stu  = $r['student'];
    $pres = !empty($r['present']) ? 1 : 0;
    $data[$subj]['cols'][$col] = true;
    $data[$subj]['students'][$stu][$col] = max($data[$subj]['students'][$stu][$col] ?? 0, $pres);
}

foreach ($data as $subj => $tbl) {
    $cols = array_keys($tbl['cols']);
    sort($cols, SORT_STRING); // "Y-m-d|type": chronological, then type A→Z within a day
    $students = array_keys($tbl['students']);
    sort($students, SORT_STRING);

    csv_row($out, ['Subject', csv_safe($subj)]);
    $dateCells = array_map(fn($c) => date('d.m.Y', strtotime(substr($c, 0, 10))), $cols);
    $typeCells = array_map(fn($c) => csv_safe(substr($c, 11)), $cols);
    csv_row($out, array_merge([''], $dateCells));
    csv_row($out, array_merge([''], $typeCells)); // "curs" / "sem" / "lab" under each date

    foreach ($students as $stuName) {
        $row = [csv_safe($stuName)];
        foreach ($cols as $c) {
            // #2: no record for this student/lesson (e.g. the other
            // subgroup's session) is a BLANK cell, not 'a'. "No class" and
            // "absent" differ; 'a' means an actual present=0 row.
            $cell = $tbl['students'][$stuName][$c] ?? null;
            $row[] = ($cell === null || $cell) ? '' : 'a';
        }
        csv_row($out, $row);
    }
    csv_row($out, []);
}

fclose($out);
exit;
