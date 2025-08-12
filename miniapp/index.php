<?php
// miniapp/index.php
// Fully inlined page that: (1) saves attendance via JSON POST,
// (2) renders current day with prior marks, including "By ..." and optional
//     "Last edited by ... at ...", and
// (3) turns "Submit Attendance" into "Edit Attendance" on-the-fly after save.

/* ─────────────────────────────────────────────────────────────
   Includes
   ────────────────────────────────────────────────────────────*/
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/oe_weeks.php';

/* ─────────────────────────────────────────────────────────────
   CORS / preflight (so fetch() works from anywhere you embed)
   ────────────────────────────────────────────────────────────*/
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

/* ─────────────────────────────────────────────────────────────
   JSON SAVE endpoint
   Expect:
     URL:   index.php?tg_id=...&offset=...
     Body:  {"attendance":[{schedule_id,user_id,present,motivated,motivation},...]}
   ────────────────────────────────────────────────────────────*/
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

  // Compute date from offset
  $offset = (int)($_GET['offset'] ?? 0);
  $date   = date('Y-m-d', strtotime("$offset days"));

  // Insert rows
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
        ':mb'     => (int)$user['id'],
      ]);
    }

    $pdo->commit();

    echo json_encode([
      'success' => true,
      // echo back who marked + the date so the client can update UI instantly
      'marked_by_name' => $user['name'] ?? 'Unknown',
      'date' => $date,
    ]);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'DB error']);
  }
  exit;
}

/* ─────────────────────────────────────────────────────────────
   PAGE RENDER (GET)
   ────────────────────────────────────────────────────────────*/
$tg_id  = (int)($_GET['tg_id']  ?? 0);
$offset = (int)($_GET['offset'] ?? 0);
$date   = date('Y-m-d', strtotime("$offset days"));

$dayLabel = match (true) {
  $offset ===  0 => 'Today',
  $offset === -1 => 'Yesterday',
  default        => abs($offset) . ' days ago'
};

$prev1 = $offset - 1;
$prev2 = $offset - 2;
$next1 = $offset + 1;

// Teacher/monitor
$user = getUserByTgId($tg_id) ?: exit('Invalid user');

// Academic odd/even computed from semester logic
$dt = new DateTime($date, new DateTimeZone('Europe/Chisinau'));
[, , , $weekType] = computeSemesterAndWeek($dt);

// Load lessons for this academic weekType
$schedule = getScheduleForDate($tg_id, $date, $weekType);

// Students in group
$students = getGroupStudents($user['group_id']);

// Existing attendance for THIS date + these schedule slots
$sids     = array_column($schedule, 'id');
$existing = [];
$markers  = []; // user_id => name

if ($sids) {
  $in = implode(',', array_fill(0, count($sids), '?'));

  // Note: updated_at, updated_by are optional but supported if present
  $q = "SELECT schedule_id,user_id,present,motivated,motivation,marked_by,updated_at,updated_by
          FROM attendance
         WHERE date=?
           AND schedule_id IN ($in)";

  $stmt = $pdo->prepare($q);
  $stmt->execute(array_merge([$date], $sids));

  $teacherIds = [];
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $existing[$r['schedule_id']][$r['user_id']] = $r;
    if (!empty($r['marked_by'])) $teacherIds[] = (int)$r['marked_by'];
    if (!empty($r['updated_by'])) $teacherIds[] = (int)$r['updated_by'];
  }

  // Resolve names for "By ..." and "Last edited by ..."
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

// Group name
$stmG = $pdo->prepare("SELECT name FROM `groups` WHERE id=?");
$stmG->execute([$user['group_id']]);
$grp       = $stmG->fetch(PDO::FETCH_ASSOC);
$groupName = $grp['name'] ?? ('Group ' . $user['group_id']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Log Attendance</title>

<script>
try {
  const html = document.documentElement;
  html.classList.add('js-ready');
  if (localStorage.getItem('theme') === 'dark') {
    html.classList.add('dark-theme');
  }
} catch (e) {}
</script>

<style>
  /* ───────────────── THEME ───────────────── */
  :root {
    --bg:#fff; --fg:#000; --bd:#ccc;
    --sec:#f5f5f5; --btn:#2a9df4; --btnfg:#fff;
  }
  .dark-theme {
    --bg:#2b2d2f; --fg:#e2e2e4; --bd:#444;
    --sec:#3b3f42; --btn:#1a73e8; --btnfg:#fff;
  }
  #theme-switch { visibility: hidden; }
  html.js-ready #theme-switch { visibility: visible; }

  /* ───────────────── LAYOUT ───────────────── */
  body { margin:0; padding:10px; font-family:system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:var(--bg); color:var(--fg); }
  h2 { margin:10px 0 4px; }
  table { width:100%; border-collapse:collapse; margin-top:10px; }
  th, td { border:1px solid var(--bd); padding:8px; text-align:center; vertical-align:middle; }
  th { background:var(--sec); }

  .btn-submit, .btn-edit {
    margin-top:15px; padding:10px 20px;
    border:none; border-radius:5px;
    background:var(--btn); color:var(--btnfg);
    cursor:pointer;
  }
  .btn-submit[disabled] { opacity:.8; cursor:not-allowed; }

  .btn-nav {
    margin:4px 6px 4px 0; padding:6px 12px;
    border:none; border-radius:4px;
    background:var(--sec); color:var(--fg); cursor:pointer;
  }

  /* ───────────────── SLIDER ───────────────── */
  .switch { position:relative; display:inline-block; width:50px; height:24px; }
  .switch input { opacity:0; width:0; height:0; }
  .slider {
    position:absolute; inset:0;
    background:#ef5350; border-radius:24px; transition:.25s;
  }
  .slider:before {
    content:""; position:absolute; width:18px; height:18px; left:3px; bottom:3px;
    background:#fff; border-radius:50%; transition:.25s;
  }
  input:checked + .slider { background:#66bb6a; }
  input:checked + .slider:before { transform:translateX(26px); }

  /* Disabled-but-colored */
  input:disabled:not(:checked) + .slider { background:#e57373; }
  input:checked:disabled + .slider        { background:#a5d6a7; }

  /* Motivation area */
  .mot-reason { font-size:0.85em; color:var(--fg); margin-top:4px; }
  .mot-edited { margin-top:2px; font-size:0.8em; opacity:0.85; }
  .mot-container { text-align:center; margin-top:4px; }
  .motiv-text { display:block; margin:4px auto 0; }

  /* Save confirmation */
  #save-confirm {
    display:none; margin-top:15px; padding:10px;
    background:#d4edda; color:#155724;
    border:1px solid #c3e6cb; border-radius:4px;
  }
</style>
</head>
<body>

<!-- Theme toggle -->
<div id="theme-switch">
  <label class="switch">
    <input type="checkbox" id="theme-toggle">
    <span class="slider"></span>
  </label>
  <span id="theme-label">Light</span>
</div>
<br>

<!-- Navigation -->
<div style="margin-top:6px;">
  <button class="btn-nav" onclick="nav(<?= $prev2 ?>)">« <?= abs($prev2) ?>d</button>
  <button class="btn-nav" onclick="nav(<?= $prev1 ?>)">← <?= abs($prev1) ?>d</button>
  <?php if ($offset!==0): ?>
    <button class="btn-nav" onclick="nav(0)">Today</button>
  <?php endif; ?>
  <?php if ($offset < 0): ?>
    <button class="btn-nav" onclick="nav(<?= $next1 ?>)">→ 1d</button>
  <?php endif; ?>
  <button class="btn-nav" onclick="location.href='greeting.php?tg_id=<?= $tg_id ?>'">
    Back to Schedule
  </button>
</div>

<h2>Group: <?= htmlspecialchars($groupName, ENT_QUOTES) ?></h2>

<?php if (empty($schedule)): ?>
  <p style="color:red;font-weight:bold;">
    No lessons for <?= $dayLabel ?> (<?= date('d.m.Y', strtotime($date)) ?>).
  </p>

<?php else: ?>
  <table>
    <thead>
      <tr>
        <th>#</th>
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
                <!-- New (not yet saved) -->
                <label class="switch">
                  <input type="checkbox"
                         class="att-toggle"
                         id="att_<?= $s['id'] ?>_<?= $stu['id'] ?>">
                  <span class="slider"></span>
                </label>

                <div class="mot-container" id="mot_cont_<?= $s['id'] ?>_<?= $stu['id'] ?>">
                  <label>
                    <input type="checkbox"
                           class="mot-toggle"
                           id="mot_<?= $s['id'] ?>_<?= $stu['id'] ?>">
                    Motivated
                  </label>
                  <input type="text"
                         id="mot_text_<?= $s['id'] ?>_<?= $stu['id'] ?>"
                         class="motiv-text"
                         placeholder="Reason…">
                </div>
              <?php endif; ?>
            </td>
          <?php endforeach; ?>

        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div id="save-confirm">
    ✅ Attendance saved for <?= date('d.m.Y', strtotime($date)) ?>!
  </div>

  <?php if (empty($existing)): ?>
    <button class="btn-submit">Submit Attendance</button>
  <?php else: ?>
    <button class="btn-edit" onclick="location.href='edit_attendance.php?tg_id=<?= $tg_id ?>&offset=<?= $offset ?>'">
      Edit Attendance
    </button>
  <?php endif; ?>

<?php endif; ?>

<script>
/* ─────────────────────────────────────────────────────────────
   Tiny helpers
   ────────────────────────────────────────────────────────────*/
const CURRENT_USER_NAME = <?= json_encode($user['name'] ?? 'Unknown') ?>;

function escapeHtml(str){
  return String(str).replace(/[&<>"']/g, s => (
    {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]
  ));
}

/* ─────────────────────────────────────────────────────────────
   Navigation
   ────────────────────────────────────────────────────────────*/
function nav(off){
  location.search = `?tg_id=<?= $tg_id ?>&offset=${off}`;
}

/* ─────────────────────────────────────────────────────────────
   Theme toggle
   ────────────────────────────────────────────────────────────*/
const toggle = document.getElementById('theme-toggle');
const label  = document.getElementById('theme-label');
const root   = document.documentElement;

// initialize from localStorage
(() => {
  const saved = localStorage.getItem('theme') || 'light';
  root.classList.toggle('dark-theme', saved === 'dark');
  label.textContent = saved === 'dark' ? 'Dark' : 'Light';
  toggle.checked = (saved === 'dark');
})();

toggle.addEventListener('change', () => {
  const isDark = toggle.checked;
  root.classList.toggle('dark-theme', isDark);
  label.textContent = isDark ? 'Dark' : 'Light';
  localStorage.setItem('theme', isDark ? 'dark' : 'light');
});

/* ─────────────────────────────────────────────────────────────
   Interactive toggles (present/motivated)
   ────────────────────────────────────────────────────────────*/
document.querySelectorAll('.att-toggle').forEach(chk=>{
  chk.addEventListener('change', ()=>{
    const id   = chk.id.replace('att_','');
    const cont = document.getElementById('mot_cont_'+id);
    if (cont) cont.style.display = chk.checked ? 'none' : 'block';
  });
});

// Motivation text only shows when "Motivated" checked
document.querySelectorAll('.mot-toggle').forEach(chk=>{
  chk.addEventListener('change', ()=>{
    const id  = chk.id.replace('mot_','');
    const txt = document.getElementById('mot_text_'+id);
    if (!txt) return;
    if (chk.checked) txt.style.display='block';
    else { txt.style.display='none'; txt.value=''; }
  });
});

// Initialize motivation inputs visibility
document.querySelectorAll('.mot-toggle').forEach(chk=>{
  const id  = chk.id.replace('mot_','');
  const txt = document.getElementById('mot_text_'+id);
  if (!txt) return;
  if (chk.checked) txt.style.display='block';
  else { txt.style.display='none'; txt.value=''; }
});

/* ─────────────────────────────────────────────────────────────
   Submit via AJAX
   On success:
     - show green banner
     - transform the button into "Edit Attendance" (no refresh)
     - freeze each cell like saved state and add "By <name>"
   ────────────────────────────────────────────────────────────*/
document.querySelector('.btn-submit')?.addEventListener('click', async ()=>{
  const msg = `Submit attendance for <?= $dayLabel ?> (<?= date('d.m.Y', strtotime($date)) ?>)?`;
  if (!confirm(msg)) return;

  const out = { attendance: [] };

  <?php foreach ($schedule as $s): foreach ($students as $u): ?>
    (() => {
      const el = document.getElementById('att_<?= $s['id'] ?>_<?= $u['id'] ?>');
      if (!el || el.disabled) return;
      const pres = !!el.checked;
      const mot  = !pres && !!document.getElementById('mot_<?= $s['id'] ?>_<?= $u['id'] ?>').checked;
      const rea  = mot ? (document.getElementById('mot_text_<?= $s['id'] ?>_<?= $u['id'] ?>').value || '') : '';
      out.attendance.push({
        schedule_id: <?= (int)$s['id'] ?>,
        user_id:     <?= (int)$u['id'] ?>,
        present:     pres,
        motivated:   mot,
        motivation:  rea
      });
    })();
  <?php endforeach; endforeach; ?>

  const url = `${location.origin}${location.pathname}${location.search}`;

  try {
    const resp = await fetch(url, {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(out)
    });

    if (!resp.ok) throw new Error('HTTP '+resp.status);

    // Some servers echo headers/footer — be liberal parsing JSON
    const text = await resp.text();
    const m = text.match(/\{[\s\S]*\}$/);
    if (!m) throw new Error('Bad JSON');
    const j = JSON.parse(m[0]);

    if (!j.success) {
      alert('Save failed: ' + (j.error || 'unknown'));
      return;
    }

    // 1) Show success ribbon
    document.getElementById('save-confirm').style.display='block';


    // 2) Turn "Submit" into "Edit Attendance" (no refresh) — REPLACE NODE to drop old listeners
const oldBtn = document.querySelector('.btn-submit');
if (oldBtn) {
  const newBtn = document.createElement('button');
  newBtn.className = 'btn-edit';
  newBtn.type = 'button';
  newBtn.textContent = 'Edit Attendance';
  newBtn.addEventListener('click', () => {
    location.href = 'edit_attendance.php?tg_id=<?= $tg_id ?>&offset=<?= $offset ?>';
  });
  oldBtn.replaceWith(newBtn); // removes the old confirm listener
}


    // 3) Freeze cells and add "By <name>" with optional Reason (client-side)
    out.attendance.forEach(r => {
      const att = document.getElementById(`att_${r.schedule_id}_${r.user_id}`);
      if (!att) return;

      // lock switch
      att.checked  = !!r.present;
      att.disabled = true;

      // remove editable motivation UI and replace with read-only line
      const cont = document.getElementById(`mot_cont_${r.schedule_id}_${r.user_id}`);
      if (cont) {
        const td = cont.parentElement;
        cont.remove();

        const div = document.createElement('div');
        div.className = 'mot-reason';

        let html = '';
        if (!r.present && r.motivated && r.motivation) {
          html += 'Reason: ' + escapeHtml(r.motivation) + '<br>';
        }
        // we know who just marked; use server-echoed name if provided
        const byName = escapeHtml(j.marked_by_name || CURRENT_USER_NAME);
        html += `<em>By ${byName}</em>`;
        div.innerHTML = html;

        td.appendChild(div);
      }
    });

    // 4) Safety: disable any remaining inputs
    document.querySelectorAll('.att-toggle,.mot-toggle,.motiv-text')
      .forEach(el => el.disabled = true);

  } catch(err) {
    console.error(err);
    alert('Network error: ' + err.message);
  }
});
</script>
</body>
</html>
