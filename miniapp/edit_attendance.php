<?php
// miniapp/edit_attendance.php


require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

// ─── CORS & PRELIGHT ─────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

// ─── LOOKUP & GUARD ──────────────────────────
$tg_id = intval($_GET['tg_id'] ?? 0);
$user  = getUserByTgId($tg_id) ?: exit('Invalid user');
// only admin, monitor, moderator
if (! in_array($user['role'], ['admin','monitor','moderator'], true)) {
  http_response_code(403);
  exit('Access denied');
}

// ─── GROUP NAME ──────────────────────────────
$stmt = $pdo->prepare("SELECT name FROM `groups` WHERE id=?");
$stmt->execute([$user['group_id']]);
$grp = $stmt->fetch(PDO::FETCH_ASSOC);
$groupName = $grp['name'] ?? 'Group ' . $user['group_id'];

// ─── COMPUTE DATE ────────────────────────────
$offset = intval($_GET['offset'] ?? 0);
$date   = date('Y-m-d', strtotime("$offset days"));
$dayLabel = match (true) {
  $offset ===  0 => 'Today',
  $offset === -1 => 'Yesterday',
  default        => abs($offset) . ' days ago'
};

// ─── AJAX SAVE (UPDATE + LOG) ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
  && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
) {
  header('Content-Type: application/json; charset=UTF-8');
  $raw  = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!$data || !isset($data['attendance'])) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Invalid payload']);
    exit;
  }

  // prepare statements
  $sel = $pdo->prepare("
    SELECT id,present,motivated,motivation
      FROM attendance
     WHERE date=:dt
       AND schedule_id=:sid
       AND user_id=:uid
    LIMIT 1
  ");
$upd = $pdo->prepare("
  UPDATE attendance
     SET present    = :pres,
         motivated  = :mot,
         motivation = :reason,
         updated_at = NOW(),
         updated_by = :editor
   WHERE id         = :att_id
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
       :old_mot,  :new_mot,
       :old_reason, :new_reason)
  ");

  foreach ($data['attendance'] as $r) {
    // fetch old
    $sel->execute([
      ':dt'  => $date,
      ':sid' => $r['schedule_id'],
      ':uid' => $r['user_id']
    ]);
    $old = $sel->fetch(PDO::FETCH_ASSOC);
    if (! $old) continue;

    $new_pres   = $r['present']   ? 1 : 0;
    $new_mot    = $r['motivated'] ? 1 : 0;
    $new_reason = $r['motivation'] ?: null;

    // only log+update if something changed
    if (
      $old['present']   != $new_pres ||
      $old['motivated'] != $new_mot  ||
      $old['motivation'] !== $new_reason
    ) {
      // insert log
$log->execute([
 'att_id'     => $old['id'],
  'editor'     => $user['id'],
  'old_pres'   => $old['present'],
  'new_pres'   => $new_pres,
  'old_mot'    => $old['motivated'],
  'new_mot'    => $new_mot,
  'old_reason' => $old['motivation'],
  'new_reason' => $new_reason,
]);

      // update attendance
$upd->execute([
'pres'   => $new_pres,
  'mot'    => $new_mot,
  'reason' => $new_reason,
  'editor' => $user['id'],
  'att_id' => $old['id'],
]);
    }
  }

  echo json_encode(['success'=>true]);
  exit;
}

// ─── PAGE RENDER ──────────────────────────────
$schedule = getScheduleForDate($tg_id, $date);
$students = getGroupStudents($user['group_id']);

// fetch existing attendance + original markers + updaters
$existing   = [];
$markers    = [];
$updaters   = [];
if (!empty($schedule)) {
  $sids = array_column($schedule,'id');
  $in   = implode(',', array_fill(0,count($sids),'?'));
  // include updated columns
  $q = "SELECT schedule_id,user_id,present,motivated,motivation,
               marked_by,updated_at,updated_by
          FROM attendance
         WHERE date=?
           AND schedule_id IN($in)";
  $stmt = $pdo->prepare($q);
  $stmt->execute(array_merge([$date], $sids));
  $marker_ids = $editor_ids = [];
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $existing[$r['schedule_id']][$r['user_id']] = $r;
    $marker_ids[] = $r['marked_by'];
    if ($r['updated_by']) $editor_ids[] = $r['updated_by'];
  }
  // load markers
  $all_ids = array_values(array_unique(array_merge($marker_ids, $editor_ids)));

  if ($all_ids) {
    $in2 = implode(',', array_fill(0,count($all_ids),'?'));
    $stm2 = $pdo->prepare("SELECT id,name FROM users WHERE id IN($in2)");
    $stm2->execute($all_ids);
    while ($u = $stm2->fetch(PDO::FETCH_ASSOC)) {
      // use two maps
      $markers[$u['id']]  = $u['name'];
      $updaters[$u['id']] = $u['name'];
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Edit Attendance — <?= htmlspecialchars($dayLabel) ?> (<?= date('d.m.Y',strtotime($date)) ?>)</title>
 
<script>
  try {
    const html = document.documentElement;
    html.classList.add('js-ready');
    if (localStorage.getItem('theme') === 'dark') {
      html.classList.add('dark-theme');
    }
  } catch(e){}
</script>
 <style>
  /* copy your index.html styles… */
  :root {
    --bg:#fff; --fg:#000; --bd:#ccc;
    --sec:#f5f5f5; --btn:#2a9df4; --btnfg:#fff;
  }
  .dark-theme {
    --bg:#2b2d2f; --fg:#e2e2e4; --bd:#444;
    --sec:#3b3f42; --btn:#1a73e8; --btnfg:#fff;
  }

  body {
    margin:0; padding:10px;
    font-family:sans-serif;
    background:var(--bg);
    color:var(--fg);
  }
  table {
    width:100%;
    border-collapse:collapse;
    margin-top:10px;
  }
  th, td {
    border:1px solid var(--bd);
    padding:8px;
    text-align:center;
  }
  th { background:var(--sec); }

  .btn-submit {
    margin-top:15px;
    padding:10px 20px;
    border:none;
    border-radius:5px;
    background:var(--btn);
    color:var(--btnfg);
    cursor:pointer;
  }

  .switch {
    position:relative;
    display:inline-block;
    width:50px;
    height:24px;
  }
  .switch input {
    opacity:0; width:0; height:0;
  }
  .slider {
    position:absolute;
    top:0; left:0; right:0; bottom:0;
    background:#ef5350;
    border-radius:24px;
    transition:.4s;
  }
  .slider:before {
    content:"";
    position:absolute;
    width:18px; height:18px;
    left:3px; bottom:3px;
    background:#fff;
    border-radius:50%;
    transition:.4s;
  }
  input:checked + .slider {
    background:#66bb6a;
  }
  input:checked + .slider:before {
    transform:translateX(26px);
  }

  /* ─── Center Motivated under the switch ──────────────────────────── */
  .mot-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-top: 4px;
  }
  .motiv-text {
    width:80px;
    /* hidden by default, shown via JS */
    display: none;
  }
  .edit-info {
    font-size:0.8em;
    color:var(--fg);
    margin-top:4px;
  }

  .btn-nav {
    margin-top:15px;
    margin-right:8px;
    padding:10px 20px;
    border:none;
    border-radius:5px;
    background:var(--sec);
    color:var(--fg);
    cursor:pointer;
  }
  #theme-switch { visibility: hidden; }
  html.js-ready #theme-switch { visibility: visible; }

  /* ─── Grey out Present slider when disabled ──────────────────────── */
  .att-toggle:disabled + .slider {
    background-color: #ccc !important;
    cursor: not-allowed;
  }
  .att-toggle:disabled + .slider:before {
    background-color: #eee !important;
  }
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

  <!-- Your Back button / nav -->
  <div class="page-header">
    <button class="btn-nav" onclick="history.back()">← Back</button>
    <!-- etc… -->
  </div>


<h2>Group: <?= htmlspecialchars($groupName,ENT_QUOTES) ?></h2>
<h2>Edit attendance for <?= htmlspecialchars($dayLabel) ?> (<?= date('d.m.Y',strtotime($date)) ?>)</h2>
  
  <?php if(empty($schedule)): ?>
    <p style="color:red;">No lessons for this day.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th><th>Student</th>
          <?php foreach($schedule as $s): ?>
            <th><?= htmlspecialchars($s['time_slot']) ?><br><?= htmlspecialchars($s['subject']) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach($students as $i=>$stu): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><?= htmlspecialchars($stu['name']) ?></td>
          <?php foreach($schedule as $s): 
            $cell = $existing[$s['id']][$stu['id']] ?? null;
            $pres = $cell ? $cell['present'] : 0;
            $mot  = $cell ? $cell['motivated'] : 0;
            $txt  = $cell ? $cell['motivation'] : '';
          ?>
          <td>
            <label class="switch">
              <input type="checkbox" class="att-toggle"
                     id="att_<?= $s['id'].'_'.$stu['id'] ?>"
                     <?= $pres ? 'checked':'' ?>>
              <span class="slider"></span>
            </label>
            <div class="mot-container" id="mot_cont_<?= $s['id'].'_'.$stu['id'] ?>">
              <label>
                <input type="checkbox"
                       class="mot-toggle"
                       id="mot_<?= $s['id'].'_'.$stu['id'] ?>"
                       <?= $mot ? 'checked':'' ?>>
                Motivated
              </label>
              <input type="text"
                     id="mot_text_<?= $s['id'].'_'.$stu['id'] ?>"
                     class="motiv-text"
                     placeholder="Reason…"
                     value="<?= htmlspecialchars($txt,ENT_QUOTES) ?>">
            </div>
            <?php if($cell && $cell['updated_by']): ?>
              <div class="edit-info">
                Last edited by <?= htmlspecialchars($updaters[$cell['updated_by']]) ?>
                at <?= htmlspecialchars($cell['updated_at']) ?>
              </div>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <button class="btn-submit">Save changes</button>
  <?php endif; ?>

<script>
  // ─── Show/hide reason based on Present toggle ────────────────────────
  document.querySelectorAll('.att-toggle').forEach(chk => {
    chk.addEventListener('change', () => {
      const id = chk.id.replace('att_','');
      document.getElementById('mot_cont_'+id).style.display = chk.checked ? 'none' : 'block';
    });
  });

  // ─── Show/hide motivation text based on Motivated checkbox ───────────
  document.querySelectorAll('.mot-toggle').forEach(chk => {
    const id  = chk.id.replace('mot_',''),
          txt = document.getElementById('mot_text_'+id);

    // init: hide text if not checked
    if (!chk.checked) {
      txt.style.display = 'none';
    }

    chk.addEventListener('change', () => {
      if (chk.checked) {
        txt.style.display = 'inline-block';
      } else {
        txt.style.display = 'none';
        txt.value = '';
      }
    });
  });

  // ─── Disable “Present” slider when “Motivated” is checked ─────────────
  document.querySelectorAll('.mot-toggle').forEach(motChk => {
    const id  = motChk.id.replace('mot_',''),
          att = document.getElementById('att_'+id);

    // init: if Motivated already checked, clear & disable Present
    if (motChk.checked) {
      att.checked  = false;
      att.disabled = true;
    }

    motChk.addEventListener('change', () => {
      if (motChk.checked) {
        att.checked  = false;
        att.disabled = true;
      } else {
        att.disabled = false;
      }
    });
  });

  // ─── Submit handler ───────────────────────────────────────────────────
  document.querySelector('.btn-submit')?.addEventListener('click', async () => {
    if (!confirm('Really save edits for <?= $dayLabel ?>?')) return;

    const out = { attendance: [] };
    <?php foreach($schedule as $s): foreach($students as $u): ?>
    (() => {
      const id     = '<?= $s['id'].'_'.$u['id'] ?>',
            attEl  = document.getElementById('att_'+id),
            motEl  = document.getElementById('mot_'+id),
            reason = document.getElementById('mot_text_'+id).value;

      out.attendance.push({
        schedule_id: <?= $s['id'] ?>,
        user_id:     <?= $u['id'] ?>,
        present:     attEl.checked,
        motivated:   !attEl.checked && motEl.checked,
        motivation:  (!attEl.checked && motEl.checked) ? reason : ''
      });
    })();
    <?php endforeach; endforeach; ?>

    const url = `${location.origin}${location.pathname}${location.search}`;
    try {
      const resp = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(out)
      });
      const j = await resp.json();
      if (j.success) {
        alert('Changes saved successfully!');
        location.reload();
      } else {
        alert('Save failed: ' + (j.error || 'unknown'));
      }
    } catch (e) {
      alert('Network error: ' + e.message);
    }
  });

  // ─── Theme toggle (Light/Dark) ───────────────────────────────────────
  (function(){
    const htmlEl = document.documentElement;
    const toggle = document.getElementById('theme-toggle');
    const label  = document.getElementById('theme-label');

    // initialize from localStorage
    if (localStorage.getItem('theme') === 'dark') {
      toggle.checked = true;
      htmlEl.classList.add('dark-theme');
      label.textContent = 'Dark';
    } else {
      toggle.checked = false;
      label.textContent = 'Light';
    }

    toggle.addEventListener('change', () => {
      if (toggle.checked) {
        htmlEl.classList.add('dark-theme');
        localStorage.setItem('theme','dark');
        label.textContent = 'Dark';
      } else {
        htmlEl.classList.remove('dark-theme');
        localStorage.setItem('theme','light');
        label.textContent = 'Light';
      }
    });
  })();
</script>

</body>
</html>
