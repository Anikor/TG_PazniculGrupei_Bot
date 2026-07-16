<?php
ob_start();
require_once __DIR__.'/config.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/oe_weeks.php';

// Semester from the one shared implementation instead of a private
// re-derivation that could drift from oe_weeks.php.
[$currentSemester] = computeSemesterAndWeek(new DateTime('today', new DateTimeZone(APP_TZ)));

require_once __DIR__.'/tg_auth.php';
$user = tg_require_auth();
tg_require_role($user, ['admin', 'monitor', 'moderator']);
$tg_id    = (int)$user['tg_id'];
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

function render_page(string $title, array $links, int $tg_id, int $group_id): void {
    $theme = (($_COOKIE['theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
    $themeClass = ($theme === 'dark') ? 'dark-theme' : '';
    $themeLabel = ($theme === 'dark') ? 'Dark' : 'Light';
    ?><!DOCTYPE html><html lang="en" class="<?=$themeClass?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars($title)?></title><script src="script.js"></script><link rel="stylesheet" href="style.css"></head><body><div id="theme-switch"><label class="switch"><input type="checkbox" id="theme-toggle" <?=$theme==='dark'?'checked':''?>><span class="slider"></span></label><span id="theme-label"><?=$themeLabel?></span></div><br><br><button class="btn btn-ghost btn-nav" onclick="location.href='greeting.php?tg_id=<?=(int)$tg_id?>'">← Back to Schedule</button><h2><?=htmlspecialchars($title)?></h2><div class="actions"><?php foreach($links as $link):?><a class="btn btn-primary" href="<?=htmlspecialchars($link['url'])?>"><?=htmlspecialchars($link['label'])?></a><?php endforeach?></div></body></html><?php
    exit;
}

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

if ($action === 'subject' && !isset($_GET['subject'])) {
    $sSt = $pdo->prepare("SELECT DISTINCT subject FROM schedule WHERE group_id = ?");
    $sSt->execute([$group_id]);
    $links = [];
    while ($s = $sSt->fetchColumn()) {
        $links[] = ['label'=>$s, 'url'=>"?tg_id={$tg_id}&action=subject&subject=".urlencode($s)."&group_id={$group_id}"];
    }
    render_page('Select Subject', $links, $tg_id, $group_id);
}

if ($action === 'student' && !isset($_GET['student_id'])) {
    $uSt = $pdo->prepare("SELECT id,name FROM users WHERE group_id = ?");
    $uSt->execute([$group_id]);
    $links = [];
    while ($stu = $uSt->fetch(PDO::FETCH_ASSOC)) {
        $links[] = ['label'=>$stu['name'], 'url'=>"?tg_id={$tg_id}&action=student&student_id={$stu['id']}&group_id={$group_id}"];
    }
    render_page('Select Student', $links, $tg_id, $group_id);
}

$stmt = $pdo->prepare("SELECT name FROM `groups` WHERE id = ?");
$stmt->execute([$group_id]);
$group_name = $stmt->fetchColumn() ?: "Group #{$group_id}";

$where = '';
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
        $params[':subject'] = $_GET['subject'];
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

$sql = "SELECT a.date, s.subject, u.name AS student, a.present
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
$filename = "{$safeGroup}_attendance_{$action}_{$fileDate}.csv";
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
    $iso  = $r['date'];
    $stu  = $r['student'];
    $pres = $r['present'];
    $data[$subj]['dates'][$iso] = true;
    $data[$subj]['students'][$stu][$iso] = $pres;
}

foreach ($data as $subj => $tbl) {
    $isoDates = array_keys($tbl['dates']);
    sort($isoDates, SORT_STRING); // Y-m-d sorts correctly lexically
    $students = array_keys($tbl['students']);
    sort($students, SORT_STRING);

    csv_row($out, ['Subject', csv_safe($subj)]);
    $dateCells = array_map(fn($d) => date('d.m.Y', strtotime($d)), $isoDates);
    csv_row($out, array_merge([''], $dateCells));

    foreach ($students as $stuName) {
        $row = [csv_safe($stuName)];
        foreach ($isoDates as $d) {
            $row[] = !empty($tbl['students'][$stuName][$d]) ? '' : 'a';
        }
        csv_row($out, $row);
    }
    csv_row($out, []);
}

fclose($out);
exit;
