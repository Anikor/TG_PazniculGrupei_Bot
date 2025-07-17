<?php
// miniapp/view_attendance.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

// 1) Lookup & validate user
if (!isset($_GET['tg_id'])) die('Missing tg_id');
$tg_id   = intval($_GET['tg_id']);
$user    = getUserByTgId($tg_id);
if (!$user)   die('Unknown user');
$user_id = $user['id'];

$backUrl = 'greeting.php?tg_id=' . $tg_id;
if (
  isset($_GET['return'], $_GET['monitor_id'], $_GET['group_id'])
  && $_GET['return'] === 'group'
) {
  $backUrl = 'view_group_attendance.php'
           . '?tg_id='    . intval($_GET['monitor_id'])
           . '&group_id=' . intval($_GET['group_id']);
}


// 2) Build date ranges
$today      = date('Y-m-d');
$weekStart  = date('Y-m-d', strtotime('monday this week'));
$monthStart = date('Y-m-01');

// 3) Helper to fetch summary stats
function fetchStats($pdo, $uid, $from, $to=null) {
  $params = [':uid'=>$uid, ':from'=>$from];
  $cond   = "a.date >= :from";
  if ($to !== null) {
    $cond .= " AND a.date <= :to";
    $params[':to'] = $to;
  }

  // total sessions
  $totalQ = $pdo->prepare("
    SELECT COUNT(*) FROM attendance a
     WHERE a.user_id=:uid AND $cond
  ");
  $totalQ->execute($params);
  $total = (int)$totalQ->fetchColumn();

  // absent sessions
  $absQ = $pdo->prepare("
    SELECT COUNT(*) FROM attendance a
     WHERE a.user_id=:uid AND $cond AND a.present=0
  ");
  $absQ->execute($params);
  $absent = (int)$absQ->fetchColumn();

  // motivated sessions
  $motQ = $pdo->prepare("
    SELECT COUNT(*) FROM attendance a
     WHERE a.user_id=:uid AND $cond AND a.present=0 AND a.motivated=1
  ");
  $motQ->execute($params);
  $motiv = (int)$motQ->fetchColumn();

  // detailed rows
  $lstQ = $pdo->prepare("
    SELECT a.date,
           s.time_slot,
           s.subject,
           s.type,
           a.motivation
      FROM attendance a
      JOIN schedule s ON s.id = a.schedule_id
     WHERE a.user_id=:uid
       AND $cond
       AND a.present=0
     ORDER BY a.date DESC, s.time_slot
  ");
  $lstQ->execute($params);
  $rows = $lstQ->fetchAll(PDO::FETCH_ASSOC);

  return [
    'total'     => $total,
    'absent'    => $absent,
    'motivated' => $motiv,
    'unmotiv'   => $absent - $motiv,
    'rows'      => $rows,
  ];
}

// 4) Compute stats for each period
$stats = [
  'Today'      => fetchStats($pdo,$user_id,$today,$today),
  'This Week'  => fetchStats($pdo,$user_id,$weekStart,$today),
  'This Month' => fetchStats($pdo,$user_id,$monthStart,$today),
  'All Time'   => fetchStats($pdo,$user_id,'1970-01-01',$today),
];

// 5) Compute estimated lab fee (50 Lei per missed lab) for “All Time”
$all = $stats['All Time'];
// Count only lab absences in that set
$labMissQ = $pdo->prepare("
  SELECT COUNT(*) FROM attendance a
  JOIN schedule s ON s.id=a.schedule_id
  WHERE a.user_id=:uid AND a.present=0 AND s.type='lab'
");
$labMissQ->execute([':uid'=>$user_id]);
$labMissCount = (int)$labMissQ->fetchColumn();
$labFee = $labMissCount * 50;

// 6) Build per‐subject breakdown
$subjRows = $pdo->prepare("
  SELECT s.subject, s.type,
         COUNT(*) AS total,
         SUM(a.present=0) AS absent
    FROM attendance a
    JOIN schedule s ON s.id=a.schedule_id
   WHERE a.user_id=:uid
   GROUP BY s.subject, s.type
");
$subjRows->execute([':uid'=>$user_id]);
$subjStats = [];
while($r = $subjRows->fetch(PDO::FETCH_ASSOC)){
  $sub = $r['subject'];
  $typ = $r['type'];
  $subjStats[$sub]['labels'][$typ] = [
    'total'=>$r['total'],
    'absent'=>$r['absent'],
  ];
  // accumulate overall
  if (!isset($subjStats[$sub]['overall'])) {
    $subjStats[$sub]['overall'] = ['total'=>0,'absent'=>0];
  }
  $subjStats[$sub]['overall']['total']  += $r['total'];
  $subjStats[$sub]['overall']['absent'] += $r['absent'];
}

// 7) Theme cookie
$theme = (($_COOKIE['theme'] ?? 'light')==='dark') ? 'dark' : 'light';

?>
<!DOCTYPE html>
<html lang="en" class="<?= $theme==='dark'?'dark-theme':'' ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>My Attendance</title>
  <style>
    /* ─── THEME & RESET ───────────────────────────────── */
    :root {
      --bg:#fff; --fg:#000; --sec:#f5f5f5; --bd:#ccc;
      --btnbg:#2a9df4; --btnfg:#fff;
    }
    .dark-theme {
      --bg:#2b2d2f; --fg:#e2e2e4; --sec:#3b3f42; --bd:#444;
      --btnbg:#1a73e8; --btnfg:#fff;
    }
    body {
      margin:0; padding:10px;
      background:var(--bg);
      color:var(--fg);
      font-family:sans-serif;
    }

    /* ─── THEME TOGGLE ──────────────────────────────── */
    #theme-switch {
      margin-bottom:15px;
    }
    .switch {position:relative;display:inline-block;width:50px;height:24px;}
    .switch input{opacity:0;width:0;height:0;}
    .slider{position:absolute;top:0;left:0;right:0;bottom:0;
            background:#ef5350;border-radius:24px;transition:.4s;}
    .slider:before{content:"";position:absolute;width:18px;height:18px;
                   left:3px;bottom:3px;background:#fff;border-radius:50%;
                   transition:.4s;}
    input:checked+.slider{background:#66bb6a;}
    input:checked+.slider:before{transform:translateX(26px);}

    /* ─── NAV BUTTON ─────────────────────────────────── */
    .btn-nav {
      display:inline-block;
      margin:10px 0 20px;
      padding:6px 12px;
      background:var(--sec);
      border:none;
      border-radius:4px;
      color:var(--fg);
      cursor:pointer;
    }

    /* ─── SUMMARY CARDS ─────────────────────────────── */
    .cards { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:20px; }
    .card {
      flex:1; min-width:180px;
      padding:12px;
      background:var(--sec);
      border:1px solid var(--bd);
      border-radius:6px;
    }
    .card h3 { margin:0 0 8px; font-size:1em; }
    .card p { margin:4px 0; font-size:.9em; }

    /* ─── ABSENCES TABLE ───────────────────────────── */
    table { width:100%; border-collapse:collapse; margin-top:20px; }
    th,td {
      border:1px solid var(--bd);
      padding:6px; text-align:left;
    }
    th { background:var(--sec); }

    /* ─── SUBJECT STATS ────────────────────────────── */
    .subj-table { margin-top:30px; width:100%; border-collapse:collapse; }
    .subj-table th,.subj-table td {
      border:1px solid var(--bd); padding:6px; text-align:center;
    }
    .subj-table th { background:var(--sec); }
  </style>
</head>
<body>

  <!-- Theme toggle -->
  <div id="theme-switch">
    <label class="switch">
      <input type="checkbox" id="theme-toggle"
             <?= $theme==='dark'?'checked':''?>>
      <span class="slider"></span>
    </label>
    <span id="theme-label">
      <?= $theme==='dark' ? 'Dark' : 'Light' ?>
    </span>
  </div>

<button class="btn-nav"
        onclick="location.href='<?= $backUrl ?>'">
  ← Back
</button>

  <h1>My Attendance</h1>

  <!-- Summary cards + lab‐fee -->
  <div class="cards">
    <?php foreach($stats as $label=>$st):
      $rate = $st['total']
            ? round(100*$st['absent']/$st['total'],1)
            : 0;
    ?>
    <div class="card">
      <h3><?= $label ?></h3>
      <p><strong>Sessions:</strong> <?= $st['total'] ?></p>
      <p><strong>Absent:</strong> <?= $st['absent'] ?></p>
      <p style="margin-left:12px;">– unmotivated: <?= $st['unmotiv'] ?></p>
      <p style="margin-left:12px;">– motivated: <?= $st['motivated'] ?></p>
      <p><strong>Absence Rate:</strong> <?= $rate ?>%</p>
      <?php if($label==='All Time'): ?>
        <hr>
        <p><strong>Lab Misses:</strong> <?= $labMissCount ?></p>
        <p><strong>Est. Fee:</strong> <?= $labFee ?> Lei</p>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>


  <!-- By-Subject breakdown -->
 <h2>By Subject Absence Rates</h2>
  <table class="subj-table">
    <thead>
      <tr>
        <th>Subject</th>
        <th>Curs Rate</th>
        <th>Sem Rate</th>
        <th>Lab Rate</th>
        <th>Overall Rate</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($subjStats as $sub=>$data):
        // overall totals & rate
        $oTotal = $data['overall']['total'];
        $oAbsent= $data['overall']['absent'];
        $oRate  = $oTotal ? 100 * $oAbsent / $oTotal : 0;
      ?>
      <tr>
        <td><?= htmlspecialchars($sub,ENT_QUOTES) ?></td>
        <?php foreach(['curs','sem','lab'] as $t):
          $dTotal  = $data['labels'][$t]['total']  ?? 0;
          $dAbsent = $data['labels'][$t]['absent'] ?? 0;
          $dRate   = $dTotal ? (100 * $dAbsent / $dTotal) : 0;
        ?>
          <td>
            <?= $dAbsent ?>/<?= $dTotal ?>
            (<?= number_format($dRate,2) ?>%)
          </td>
        <?php endforeach; ?>
        <td>
          <?= $oAbsent ?>/<?= $oTotal ?>
          (<?= number_format($oRate,2) ?>%)
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>


  <!-- Absence details per period -->
  <?php foreach($stats as $label=>$st):
    if(empty($st['rows'])) continue;
  ?>
    <h2><?= $label ?> Absences</h2>
    <table>
      <thead>
        <tr>
          <th>Date</th><th>Time</th><th>Subject</th><th>Type</th><th>Reason</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($st['rows'] as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['date'],ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($r['time_slot'],ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($r['subject'],ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($r['type'],ENT_QUOTES) ?></td>
          <td>
            <?= $r['motivation']
                 ? htmlspecialchars($r['motivation'],ENT_QUOTES)
                 : '<em>none</em>' ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endforeach; ?>

  

<script>
const toggle = document.getElementById('theme-toggle');
  const label  = document.getElementById('theme-label');
  const root   = document.documentElement;

  // initialize from localStorage
  (saved => {
    root.classList.toggle('dark-theme', saved === 'dark');
    label.textContent = saved === 'dark' ? 'Dark' : 'Light';
    toggle.checked = saved === 'dark';
  })(localStorage.getItem('theme') || 'light');

  // persist on change
  toggle.addEventListener('change', () => {
    const isDark = toggle.checked;
    root.classList.toggle('dark-theme', isDark);
    label.textContent = isDark ? 'Dark' : 'Light';
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
  });
</script>
</body>
</html>
