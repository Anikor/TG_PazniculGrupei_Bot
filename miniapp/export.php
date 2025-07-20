<?php
// miniapp/export.php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

// 1 Identify & guard user
$tg_id = intval($_GET['tg_id'] ?? 0);
$user  = getUserByTgId($tg_id) ?: exit('Invalid user');
if (!in_array($user['role'], ['admin','monitor','moderator'], true)) {
    http_response_code(403);
    exit('Access denied');
}

// 2 Determine group & action
$group_id = intval($_GET['group_id']  ?? $user['group_id']);
$action   = $_GET['action'] ?? '';

// 3 If no action yet, show export choices
if (!$action) {
    echo "<h2>Export Options</h2>";
    echo "<ul>";
    echo "<li><a href=\"?tg_id=$tg_id&action=week&group_id=$group_id\">This Week</a></li>";
    echo "<li><a href=\"?tg_id=$tg_id&action=month&group_id=$group_id\">This Month</a></li>";
    echo "<li><a href=\"?tg_id=$tg_id&action=all&group_id=$group_id\">All Time</a></li>";
    echo "<li><a href=\"?tg_id=$tg_id&action=subject&group_id=$group_id\">By Subject</a></li>";
    echo "<li><a href=\"?tg_id=$tg_id&action=student&group_id=$group_id\">By Student</a></li>";
    echo "</ul>";
    exit;
}

// 4 Handle subject & student selection (same as before)
$where = '';
$params = [':group_id'=>$group_id];
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
    if (!isset($_GET['subject'])) {
      $stmt = $pdo->prepare("SELECT DISTINCT subject FROM schedule WHERE group_id = ?");
      $stmt->execute([$group_id]);
      echo "<h2>Select Subject</h2>";
      while ($s = $stmt->fetchColumn()) {
        $u = urlencode($s);
        echo "<a href=\"?tg_id=$tg_id&action=subject&subject=$u&group_id=$group_id\">$s</a><br>";
      }
      exit;
    }
    $where = "AND s.subject = :subject";
    $params[':subject'] = $_GET['subject'];
    $from = '1970-01-01'; $to = date('Y-m-d');
    break;
  case 'student':
    if (!isset($_GET['student_id'])) {
      $stmt = $pdo->prepare("SELECT id,name FROM users WHERE group_id = ?");
      $stmt->execute([$group_id]);
      echo "<h2>Select Student</h2>";
      while ($stu = $stmt->fetch()) {
        echo "<a href=\"?tg_id=$tg_id&action=student&student_id={$stu['id']}&group_id=$group_id\">"
             .htmlspecialchars($stu['name'])."</a><br>";
      }
      exit;
    }
    $where = "AND a.user_id = :student_id";
    $params[':student_id'] = intval($_GET['student_id']);
    $from = '1970-01-01'; $to = date('Y-m-d');
    break;
  default:
    exit('Unknown action');
}
$params[':from'] = $from;
$params[':to']   = $to;

// 5 Fetch the data
$sql = "
  SELECT a.date, s.subject, u.name AS student, a.present
    FROM attendance a
    JOIN schedule  s ON s.id       = a.schedule_id
    JOIN users     u ON u.id       = a.user_id
   WHERE s.group_id   = :group_id
     AND a.date BETWEEN :from AND :to
     $where
   ORDER BY a.date, s.subject, u.name
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 6️⃣ Stream CSV to browser
$filename = "attendance_{$action}_" . date('Ymd') . ".csv";
header('Content-Type: text/csv; charset=UTF-8');
header("Content-Disposition: attachment; filename=\"$filename\"");
$output = fopen('php://output', 'w');

// Optional: BOM for Excel on Windows
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write header row
fputcsv($output, ['Date','Subject','Student','Present']);

// Write data rows
foreach ($rows as $r) {
    fputcsv($output, [
      $r['date'],
      $r['subject'],
      $r['student'],
      $r['present'] ? 'Yes' : 'No'
    ]);
}

fclose($output);
exit;
