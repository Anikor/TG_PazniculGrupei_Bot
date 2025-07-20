<?php
// miniapp/export.php
ob_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

// — 1) Authenticate —
$tg_id = intval($_GET['tg_id'] ?? 0);
$user  = getUserByTgId($tg_id) ?: exit('Invalid user');
if (! in_array($user['role'], ['admin','monitor','moderator'], true)) {
    http_response_code(403);
    exit('Access denied');
}

$group_id = intval($_GET['group_id'] ?? $user['group_id']);
$action   = $_GET['action']   ?? '';

// — 2) Helper: render a styled HTML menu —
function render_page(string $title, array $links, int $tg_id, int $group_id) {
    ?><!DOCTYPE html>
    <html lang="en"><head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <title><?= htmlspecialchars($title) ?></title>
      <script>
        try {
          const h = document.documentElement;
          h.classList.add('js-ready');
          if (localStorage.getItem('theme') === 'dark') h.classList.add('dark-theme');
        } catch {}
      </script>
      <style>
        :root {
          --bg:#fff; --fg:#000; --bd:#ccc;
          --sec:#f5f5f5; --btn:#2a9df4; --btnfg:#fff;
        }
        .dark-theme {
          --bg:#2b2d2f; --fg:#e2e2e4; --bd:#444;
          --sec:#3b3f42; --btn:#1a73e8; --btnfg:#fff;
        }
        html.js-ready #theme-switch { visibility: visible }
        #theme-switch {
          visibility: hidden;
          display: inline-block;
          margin-right: 1em;
        }
        body {
          margin:0; padding:10px;
          font-family:sans-serif;
          background:var(--bg);
          color:var(--fg);
        }
        .btn-nav {
          margin:4px 0;
          padding:6px 12px;
          border:none; border-radius:4px;
          background:var(--sec);
          color:var(--fg);
          cursor:pointer;
        }
        .switch { position:relative; display:inline-block; width:50px; height:24px; }
        .switch input { opacity:0; width:0; height:0; }
        .slider {
          position:absolute; top:0; left:0; right:0; bottom:0;
          background:#ef5350; border-radius:24px; transition:.4s;
        }
        .slider:before {
          content:""; position:absolute;
          width:18px; height:18px;
          left:3px; bottom:3px;
          background:#fff; border-radius:50%; transition:.4s;
        }
        input:checked + .slider { background:#66bb6a; }
        input:checked + .slider:before { transform:translateX(26px); }
        .actions a {
          display:inline-block;
          margin:0 .5em .5em 0;
          padding:.6em 1.2em;
          background:var(--btn);
          color:var(--btnfg);
          text-decoration:none;
          border-radius:4px;
        }
        .actions a:hover { opacity:0.85; }
      </style>
    </head>
    <body>
      <div id="theme-switch">
        <label class="switch">
          <input type="checkbox" id="theme-toggle">
          <span class="slider"></span>
        </label>
        <span id="theme-label">Light</span>
      </div>
      <br><br>
            <button class="btn-nav"
        onclick="location.href='greeting.php?tg_id=<?= $tg_id ?>'">
        ← Back to Schedule
      </button>
      <h2><?= htmlspecialchars($title) ?></h2>
      <div class="actions">
        <?php foreach ($links as $link): ?>
          <a href="<?= htmlspecialchars($link['url']) ?>">
            <?= htmlspecialchars($link['label']) ?>
          </a>
        <?php endforeach ?>
      </div>

      <script>
        const root   = document.documentElement;
        const toggle = document.getElementById('theme-toggle');
        const label  = document.getElementById('theme-label');

        (()=>{
          const theme = localStorage.getItem('theme')||'light';
          toggle.checked = theme==='dark';
          root.classList.toggle('dark-theme', theme==='dark');
          label.textContent = theme==='dark'?'Dark':'Light';
        })();

        toggle.addEventListener('change', ()=>{
          const d = toggle.checked;
          root.classList.toggle('dark-theme', d);
          label.textContent = d?'Dark':'Light';
          localStorage.setItem('theme', d?'dark':'light');
        });
      </script>
    </body>
    </html><?php
    exit;
}

// — 3) Export Options menu —
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

// — 4) “By Subject” selection —
if ($action==='subject' && !isset($_GET['subject'])) {
    $sSt = $pdo->prepare("SELECT DISTINCT subject FROM schedule WHERE group_id = ?");
    $sSt->execute([$group_id]);
    $links = [];
    while ($s=$sSt->fetchColumn()) {
        $links[] = [
          'label'=>$s,
          'url'=>"?tg_id={$tg_id}&action=subject&subject=".urlencode($s)
                 ."&group_id={$group_id}"
        ];
    }
    render_page('Select Subject', $links, $tg_id, $group_id);
}

// — 5) “By Student” selection —
if ($action==='student' && !isset($_GET['student_id'])) {
    $uSt = $pdo->prepare("SELECT id,name FROM users WHERE group_id = ?");
    $uSt->execute([$group_id]);
    $links=[];
    while ($stu=$uSt->fetch(PDO::FETCH_ASSOC)) {
        $links[]=[
          'label'=>$stu['name'],
          'url'=>"?tg_id={$tg_id}&action=student&student_id={$stu['id']}"
                 ."&group_id={$group_id}"
        ];
    }
    render_page('Select Student', $links, $tg_id, $group_id);
}

// — 6) CSV export logic remains exactly as before —

$stmt = $pdo->prepare("SELECT name FROM `groups` WHERE id = ?");
$stmt->execute([$group_id]);
$group_name = $stmt->fetchColumn() ?: "Group #{$group_id}";

$where  = '';
$params = [':group_id'=>$group_id];
switch ($action) {
    case 'week':
        $from=date('Y-m-d',strtotime('monday this week'));
        $to  =date('Y-m-d');
        break;
    case 'month':
        $from=date('Y-m-01');
        $to  =date('Y-m-d');
        break;
    case 'all':
        $from='1970-01-01';
        $to  =date('Y-m-d');
        break;
    case 'subject':
        // subject param guaranteed by render_page above
        $where="AND s.subject=:subject";
        $params[':subject']=$_GET['subject'];
        $from='1970-01-01'; $to=date('Y-m-d');
        break;
    case 'student':
        $where="AND a.user_id=:student_id";
        $params[':student_id']=intval($_GET['student_id']);
        $from='1970-01-01'; $to=date('Y-m-d');
        break;
    default:
        exit('Unknown action');
}
$params[':from']=$from;
$params[':to']=$to;

$sql="
  SELECT a.date, s.subject, u.name AS student, a.present
    FROM attendance a
    JOIN schedule s ON s.id=a.schedule_id
    JOIN users u ON u.id=a.user_id
   WHERE s.group_id=:group_id
     AND a.date BETWEEN :from AND :to
     $where
   ORDER BY s.subject,a.date,u.name
";
$stmt=$pdo->prepare($sql);
$stmt->execute($params);
$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);

// clear buffer
while (ob_get_level()) { ob_end_clean(); }

// filename
$safeGroup=preg_replace('/[^\w]+/u','_',mb_strtolower($group_name));
$fileDate=date('d.m.Y');
$filename="{$safeGroup}_attendance_{$action}_{$fileDate}.csv";

header('Content-Type:text/csv; charset=UTF-8');
header("Content-Disposition:attachment; filename=\"$filename\"");

$out=fopen('php://output','w');
// BOM
fprintf($out,chr(0xEF).chr(0xBB).chr(0xBF));

// metadata
fputcsv($out,['Group',$group_name],';');
fputcsv($out,[], ';');

// pivot data
$data=[];
foreach($rows as $r){
    $subj=$r['subject'];
    $dt=date('d.m.Y',strtotime($r['date']));
    $stu=$r['student'];
    $pres=$r['present'];
    $data[$subj]['dates'][$dt]=true;
    $data[$subj]['students'][$stu][$dt]=$pres;
}

// output mini‑tables
foreach($data as $subj=>$tbl){
    $dates=array_keys($tbl['dates']); sort($dates,SORT_STRING);
    $students=array_keys($tbl['students']); sort($students,SORT_STRING);

    fputcsv($out,['Subject',$subj],';');
    // Date row
    $dateCells=array_map(fn($d)=>"'".$d,$dates);
    fputcsv($out,array_merge(['Date'],$dateCells),';');
    // Present headers
    fputcsv($out,array_merge(['Student'],array_fill(0,count($dates),'Present')), ';');
    // student rows
    foreach($students as $stu){
        $row=[$stu];
        foreach($dates as $d){
            $row[]= !empty($tbl['students'][$stu][$d])?'':'a';
        }
        fputcsv($out,$row,';');
    }
    fputcsv($out,[], ';');
}

fclose($out);
exit;
