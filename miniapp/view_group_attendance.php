<?php 
// miniapp/view_group_attendance.php
  require_once __DIR__ . '/../config.php';
  require_once __DIR__ . '/../db.php';

  // 1) Who’s looking?
  $tg_id    = intval($_GET['tg_id'] ?? 0);
  $me       = getUserByTgId($tg_id) ?: die('Unknown user');

  // 2) Permission guard
  if (! in_array($me['role'], ['admin','monitor'], true)) {
    die('Access denied');
  }

  // 3) Which group are we viewing? 
  //    If none was passed, default to the viewer’s own group
  $group_id = intval($_GET['group_id'] ?? $me['group_id']);

  // 4) Compute date ranges
  $today      = date('Y-m-d');
  $weekStart  = date('Y-m-d', strtotime('monday this week'));
  $monthStart = date('Y-m-01');
  $periods = [
    'Today'      => [$today,      $today],
    'This Week'  => [$weekStart,  $today],
    'This Month' => [$monthStart, $today],
    'All Time'   => ['1970-01-01',$today],
  ];

  // 5) stats helper (your existing function, unchanged)
  function fetchStats(PDO $pdo, $uid, $from, $to=null) {
    $params = [':uid'=>$uid,':from'=>$from];
    $cond   = "a.date >= :from";
    if($to!==null) {
      $cond.=" AND a.date <= :to";
      $params[':to']=$to;
    }
    // total
    $tot = $pdo->prepare("
      SELECT COUNT(*) FROM attendance a
      WHERE a.user_id=:uid AND $cond
    ");
    $tot->execute($params);
    $total=(int)$tot->fetchColumn();
    // absent
    $abs = $pdo->prepare("
      SELECT COUNT(*) FROM attendance a
      WHERE a.user_id=:uid AND $cond AND a.present=0
    ");
    $abs->execute($params);
    $absent=(int)$abs->fetchColumn();
    return [$total, $absent];
  }

  // 6) load students (ORDER BY name for alphabetical order)
$stuQ = $pdo->prepare("
    SELECT id, name, tg_id
      FROM users
     WHERE group_id = ?
     ORDER BY name
");
$stuQ->execute([$group_id]);
$students = $stuQ->fetchAll(PDO::FETCH_ASSOC);

  // 7) compute per‐student stats (your existing loop, unchanged)
  $stats = [];
  foreach($students as $stu) {
    $sid = $stu['id'];
    foreach($periods as $lbl=>[$f,$t]) {
      [$tot,$abs] = fetchStats($pdo,$sid,$f,$t);
      $stats[$sid][$lbl] = [
        'total'  => $tot,
        'absent' => $abs,
        // rate = percent **present** = ((total-absent)/total)*100
        'rate'   => $tot ? round(100*($tot-$abs)/$tot,2) : 0
      ];
    }
  }

  // ─── NEW: compute **group summary** ────────────────────────────────────────
  // initialize
  $sum = [];
  foreach(array_keys($periods) as $lbl) {
    $sum[$lbl] = ['absent'=>0,'total'=>0];
  }
  // accumulate
  foreach($students as $stu) {
    $sid = $stu['id'];
    foreach(array_keys($periods) as $lbl) {
      $sum[$lbl]['absent'] += $stats[$sid][$lbl]['absent'];
      $sum[$lbl]['total']  += $stats[$sid][$lbl]['total'];
    }
  }
  // compute present & percent-present
  $summary = [];
  foreach($sum as $lbl=>$vals) {
    $present = $vals['total'] - $vals['absent'];
    $pct     = $vals['total'] ? round(100*$present/$vals['total'],2) : 0;
    $summary[$lbl] = [
      'present'=>$present,
      'total'  =>$vals['total'],
      'pct'    =>$pct
    ];
  }
  // ────────────────────────────────────────────────────────────────────────────

  // 8) theme cookie (unchanged)
  $theme = (($_COOKIE['theme'] ?? 'light')==='dark')?'dark':'light';

  // 9) fetch real group name if you have a groups table (optional)
  $groupName = "Group {$group_id}";
  $gQ = $pdo->prepare("SELECT name FROM groups WHERE id=?");
  if ($gQ->execute([$group_id]) && ($n=$gQ->fetchColumn())) {
    $groupName = $n;
  }
?>
<!DOCTYPE html>
<html lang="en" class="<?= $theme==='dark'?'dark-theme':'' ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Group Attendance</title>
  <style>
    /* ← keep all your existing CSS here… */
    :root{--bg:#fff;--fg:#000;--sec:#f5f5f5;--bd:#ccc}
    .dark-theme { --bg:#2b2d2f;--fg:#e2e2e4;--sec:#3b3f42;--bd:#444; }
    body { margin:0;padding:10px;font-family:sans-serif;
           background:var(--bg);color:var(--fg); }
    /* theme toggle */
    .switch{position:relative;display:inline-block;width:50px;height:24px;}
    .switch input{opacity:0;width:0;height:0;}
    .slider{position:absolute;top:0;left:0;right:0;bottom:0;
            background:#ef5350;border-radius:24px;transition:.4s;}
    .slider:before{content:"";position:absolute;width:18px;height:18px;
                   left:3px;bottom:3px;background:#fff;border-radius:50%;
                   transition:.4s;}
    input:checked+.slider{background:#66bb6a;}
    input:checked+.slider:before{transform:translateX(26px);}
    #theme-switch{margin-bottom:15px;}
    .btn-nav {
      display:inline-block;margin:10px 0 20px;
      padding:6px 12px;background:var(--sec);
      border:none;border-radius:4px;color:var(--fg);
      cursor:pointer;
    }
    /* ─── NEW: stat‐cards */
    .stat-cards{display:flex;gap:1rem;margin-bottom:1.5rem;}
    .stat{background:var(--sec);border-radius:8px;padding:1rem;flex:1;text-align:center;}
    .stat h3{margin:0 0 .5rem;font-size:1rem;color:var(--fg);}
    .stat p{margin:0;font-size:1.25rem;font-weight:bold;}
    /* ───────────────────────────────────────────── */
    table{width:100%;border-collapse:collapse;margin-top:10px;}
    th,td{border:1px solid var(--bd);padding:8px;text-align:center;}
    th{background:var(--sec);}
    /* ─── NEW: “View” button style */
    .btn-view {
      padding:4px 8px;
      background:#2196F3;color:#fff;
      border:none;border-radius:4px;
      cursor:pointer;
    }
    .btn-view:hover { background:#1976D2; }
    /* ───────────────────────────────────────────── */
  </style>
</head>
<body>

  <!-- theme switch (unchanged) -->
  <div id="theme-switch">
    <label class="switch">
      <input type="checkbox" id="theme-toggle"
             <?= $theme==='dark'?'checked':''?> >
      <span class="slider"></span>
    </label>
    <span id="theme-label">
      <?= $theme==='dark'?'Dark':'Light' ?>
    </span>
  </div>

  <!-- ← Back to group list -->
  <button class="btn-nav"
          onclick="location.href='greeting.php?tg_id=<?= $tg_id ?>&group_id=<?= $group_id ?>'">
    ← Back
  </button>

  <!-- ─── Title with actual group name ─────────────────── -->
  <h1>Group Attendance: <?= htmlspecialchars($groupName, ENT_QUOTES) ?></h1>

  <!-- ─── Summary cards at the top ─────────────────────── -->
  <div class="stat-cards">
    <?php foreach($summary as $lbl => $d): ?>
      <div class="stat">
        <h3><?= htmlspecialchars($lbl,ENT_QUOTES) ?></h3>
        <p><?= "{$d['present']}/{$d['total']} ({$d['pct']}%)" ?></p>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- ─── Attendance table with numbering & View‐buttons ─ -->
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Student</th>
        <?php foreach (array_keys($periods) as $lbl): ?>
          <th><?= htmlspecialchars($lbl,ENT_QUOTES) ?></th>
        <?php endforeach; ?>
        <th>View</th>
      </tr>
    </thead>
    <tbody>
      <?php $i=1; foreach($students as $stu): 
        $sid = $stu['id'];
      ?>
      <tr>
        <td><?= $i++ ?></td>
        <td style="text-align:left;"><?= htmlspecialchars($stu['name'],ENT_QUOTES) ?></td>
        <?php foreach(array_keys($periods) as $lbl): 
          $r = $stats[$sid][$lbl];
        ?>
          <td>
            <?= "{$r['absent']}/{$r['total']} (" . number_format($r['rate'],2) . "%)" ?>
          </td>
        <?php endforeach; ?>
        <td>
          <?php if ($stu['tg_id']): ?>
            <?php 
              $qs = http_build_query([
                'tg_id'=>$stu['tg_id'],
                'return'=>'group',
                'monitor_id'=>$tg_id,
                'group_id'=>$group_id
              ]);
            ?>
            <button class="btn-view"
                    onclick="location.href='view_attendance.php?<?= $qs ?>'">
              View
            </button>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

<script>
  const toggle = document.getElementById('theme-toggle');
  const label  = document.getElementById('theme-label');
  // initialize from localStorage
  (saved => {
    document.documentElement.classList.toggle('dark-theme', saved==='dark');
    label.textContent = saved==='dark'?'Dark':'Light';
    toggle.checked     = saved==='dark';
  })(localStorage.getItem('theme')||'light');
  // persist on change
  toggle.addEventListener('change',()=>{
    const isDark = toggle.checked;
    document.documentElement.classList.toggle('dark-theme',isDark);
    label.textContent = isDark?'Dark':'Light';
    localStorage.setItem('theme', isDark?'dark':'light');
  });
</script>

</body>
</html>
