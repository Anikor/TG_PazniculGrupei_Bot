'use strict';

/* ===================== onReady helper ===================== */
function onReady(fn) {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fn, { once: true });
  } else {
    fn();
  }
}

/* ===================== nav(offset[, tg, actor]) ===================== */
/* Preserves existing query params (incl. group_id) and optionally
   overrides tg_id and/or actor_tg. Used by index.php day nav buttons. */
(function () {
  window.nav = function (off, tgOpt, actorOpt) {
    const url = new URL(location.href);
    if (tgOpt)   url.searchParams.set('tg_id', String(tgOpt));
    url.searchParams.set('offset', String(off));
    if (actorOpt) url.searchParams.set('actor_tg', String(actorOpt));
    url.searchParams.delete('when'); // normalize
    location.href = url.toString();
  };
})();

/* ===================== Telegram expand (no-op if absent) ===================== */
(function () {
  try {
    const tg = window.Telegram && window.Telegram.WebApp;
    if (tg && typeof tg.expand === 'function') tg.expand();
  } catch {}
})();

/* ===================== prepaint theme class to avoid flicker ===================== */
(function () {
  try {
    const html = document.documentElement;
    html.classList.add('js-ready');
    const saved = localStorage.getItem('theme') || 'light';
    html.classList.toggle('dark-theme', saved === 'dark');
  } catch {}
})();

/* ===================== theme toggle (sync to cookie + localStorage) ===================== */
onReady(function () {
  const root = document.documentElement;
  const toggle = document.getElementById('theme-toggle');
  const label = document.getElementById('theme-label');
  if (!toggle || !label) return;

  const saved = localStorage.getItem('theme') || 'light';
  toggle.checked = (saved === 'dark');
  label.textContent = saved === 'dark' ? 'Dark' : 'Light';
  root.classList.toggle('dark-theme', saved === 'dark');

  toggle.addEventListener('change', () => {
    const isDark = !!toggle.checked;
    root.classList.toggle('dark-theme', isDark);
    label.textContent = isDark ? 'Dark' : 'Light';
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
    document.cookie = 'theme=' + (isDark ? 'dark' : 'light') + ';path=/;max-age=31536000';
  });
});

/* ===================== optional: period slider (if present) ===================== */
onReady(function () {
  const slider = document.getElementById('period-slider');
  const out = document.getElementById('period-label');
  if (!slider || !out) return;

  const labels = JSON.parse(slider.getAttribute('data-labels') || '["Today","This Week","This Month","All Time"]');
  const ranges = JSON.parse(slider.getAttribute('data-ranges') || '[]');

  const apply = (idx) => {
    if (labels[idx]) out.textContent = labels[idx];
    const pair = ranges[idx];
    if (!pair) return;
    const url = new URL(location.href);
    url.searchParams.set('start', pair[0]);
    url.searchParams.set('end', pair[1]);
    history.replaceState({}, '', url.toString());
  };

  slider.addEventListener('input', (e) => {
    const idx = parseInt(e.target.value, 10) || 0;
    apply(idx);
  });

  apply(parseInt(slider.value, 10) || 0);
});

/* ===================== week table layout toggle (if present) ===================== */
onReady(function () {
  const tableToggle = document.getElementById('table-toggle');
  const tableLabel  = document.getElementById('table-label');
  const cssBig      = document.getElementById('css-big');
  if (!tableToggle || !cssBig) return;

  const ANIM_MS = 260;

  function applyLayout(mode) {
    cssBig.media = (mode === 'big') ? 'all' : 'not all';
    localStorage.setItem('tableLayout', mode);
    document.cookie = 'tableLayout=' + encodeURIComponent(mode) + ';path=/;max-age=31536000';
  }

  function setUI(mode) {
    tableToggle.checked = (mode === 'big');
    if (tableLabel) tableLabel.textContent = (mode === 'big') ? 'Big' : 'Compact';
  }

  const initial = (cssBig.media && cssBig.media.toLowerCase() !== 'not all') ? 'big' : 'small';
  setUI(initial);

  tableToggle.addEventListener('change', (e) => {
    const mode = e.target.checked ? 'big' : 'small';
    setUI(mode);
    setTimeout(() => { applyLayout(mode); }, ANIM_MS);
  });
});

/* ===================== Telegram bootstrap (first visit shell) ===================== */
onReady(function () {
  if (!document.getElementById('tg-bootstrap')) return;

  const tg = window.Telegram && window.Telegram.WebApp;
  try { tg && tg.expand && tg.expand(); } catch {}

  const user = tg && tg.initDataUnsafe && tg.initDataUnsafe.user;
  if (!user || !user.id) {
    document.body.innerHTML = '<p style="color:red">Cannot detect user ID</p>';
    return;
  }

  fetch(location.href, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ tg_id: user.id })
  })
  .then(() => {
    history.replaceState(null, '', location.pathname + location.search);
    location.reload();
  })
  .catch(() => {
    document.body.insertAdjacentHTML('beforeend', '<p style="color:red">Failed to send Telegram ID.</p>');
  });
});

/* ===================== index.php: submit attendance ===================== */
onReady(function () {
  const isIndex   = (document.body.dataset.page === 'index') || (!!document.getElementById('save-confirm'));
  if (!isIndex) return;

  const submitBtn  = document.querySelector('.btn-submit');
  const saveBanner = document.getElementById('save-confirm');
  if (!submitBtn) return;

  const escapeHtml = (s) => String(s).replace(/[&<>"']/g,
    c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;', "'":'&#39;'}[c])
  );

  // Hide/show motivation editor based on presence
  document.querySelectorAll('.att-toggle').forEach(chk => {
    chk.addEventListener('change', () => {
      const id = chk.id.replace('att_', '');
      const cont = document.getElementById('mot_cont_' + id);
      if (cont) cont.style.display = chk.checked ? 'none' : 'block';
    });
  });

  // Toggle motivation text visibility
  const syncMotTxt = (chk) => {
    const id  = chk.id.replace('mot_', '');
    const txt = document.getElementById('mot_text_' + id);
    if (!txt) return;
    if (chk.checked) txt.style.display = 'block';
    else { txt.style.display = 'none'; txt.value = ''; }
  };
  document.querySelectorAll('.mot-toggle').forEach(chk => {
    chk.addEventListener('change', () => syncMotTxt(chk));
    syncMotTxt(chk);
  });

  submitBtn.addEventListener('click', async () => {
    const body = document.body;
    const dayLabel = body.dataset.dayLabel || 'this day';
    const dateDmy  = body.dataset.dateDmy  || '';
    if (!confirm(`Submit attendance for ${dayLabel} (${dateDmy})?`)) return;

    const out = { attendance: [] };

    document.querySelectorAll('.att-toggle').forEach(el => {
      if (el.disabled) return;
      const m = el.id.match(/^att_(\d+)_(\d+)$/);
      if (!m) return;
      const sid = +m[1], uid = +m[2];
      const pres = !!el.checked;
      const motChk = document.getElementById(`mot_${sid}_${uid}`);
      const txt = document.getElementById(`mot_text_${sid}_${uid}`);
      const mot = !pres && !!(motChk && motChk.checked);
      const rea = mot ? (txt?.value || '') : '';
      out.attendance.push({ schedule_id: sid, user_id: uid, present: pres, motivated: mot, motivation: rea });
    });

    const url = `${location.origin}${location.pathname}${location.search}`;
    try {
      const resp = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(out) });
      if (!resp.ok) throw new Error('HTTP ' + resp.status);

      // some hosts echo HTML before JSON; try to extract trailing JSON object
      const text = await resp.text();
      const m = text.match(/\{[\s\S]*\}$/);
      const j = m ? JSON.parse(m[0]) : JSON.parse(text);

      if (!j.success) { alert('Save failed: ' + (j.error || 'unknown')); return; }

      if (saveBanner) saveBanner.style.display = 'block';

      const params = new URLSearchParams(location.search);
      const tgId   = params.get('tg_id')     || '';
      const offset = params.get('offset')    || '0';
      const gid    = params.get('group_id');              // keep AI-241 (Primary view-mode)
      const actor  = params.get('actor_tg')  || '';       // keep actor

      // Swap "Submit" with "Edit Attendance" preserving group_id and actor_tg
      const newBtn = document.createElement('button');
      newBtn.className = 'btn-edit';
      newBtn.type = 'button';
      newBtn.textContent = 'Edit Attendance';
      newBtn.addEventListener('click', () => {
        let href = `edit_attendance.php?tg_id=${encodeURIComponent(tgId)}&offset=${encodeURIComponent(offset)}`;
        if (gid)   href += `&group_id=${encodeURIComponent(gid)}`;
        if (actor) href += `&actor_tg=${encodeURIComponent(actor)}`;
        location.href = href;
      });
      submitBtn.replaceWith(newBtn);

      const byName = escapeHtml(String(document.body.dataset.currentUserName || j.marked_by_name || 'Unknown'));

      // Lock down toggles and show "By ..." per cell
      out.attendance.forEach(r => {
        const att = document.getElementById(`att_${r.schedule_id}_${r.user_id}`);
        if (!att) return;
        att.checked  = !!r.present;
        att.disabled = true;
        const cont = document.getElementById(`mot_cont_${r.schedule_id}_${r.user_id}`);
        if (cont) {
          const td = cont.parentElement;
          cont.remove();
          const div = document.createElement('div');
          div.className = 'mot-reason';
          let html = '';
          if (!r.present && r.motivated && r.motivation) {
            html += 'Reason: ' + escapeHtml(r.motivation || '') + '<br>';
          }
          html += `<em>By ${byName}</em>`;
          div.innerHTML = html;
          td.appendChild(div);
        }
      });

      document.querySelectorAll('.att-toggle,.mot-toggle,.motiv-text').forEach(el => { el.disabled = true; });
    } catch (err) {
      console.error(err);
      alert('Network error: ' + err.message);
    }
  });
});

/* ===================== edit_attendance.php: save changes ===================== */
onReady(function () {
  if (document.body.dataset.page !== 'edit') return;

  const submitBtn = document.querySelector('.btn-submit');
  if (!submitBtn) return;

  // Hide motivation UI when present is checked
  document.querySelectorAll('.att-toggle').forEach(chk => {
    const apply = () => {
      const id = chk.id.replace('att_', '');
      const cont = document.getElementById('mot_cont_' + id);
      if (cont) cont.style.display = chk.checked ? 'none' : 'block';
    };
    chk.addEventListener('change', apply);
    apply();
  });

  // Motivation checkbox forces present OFF and enables text
  document.querySelectorAll('.mot-toggle').forEach(motChk => {
    const id = motChk.id.replace('mot_', '');
    const txt = document.getElementById('mot_text_' + id);
    const att = document.getElementById('att_' + id);
    const apply = () => {
      if (motChk.checked) {
        if (txt) txt.style.display = 'inline-block';
        if (att) { att.checked = false; att.disabled = true; }
      } else {
        if (txt) { txt.style.display = 'none'; txt.value = ''; }
        if (att) att.disabled = false;
      }
    };
    motChk.addEventListener('change', apply);
    apply();
  });

  submitBtn.addEventListener('click', async () => {
    const dayLabel = document.body.dataset.dayLabel || 'this day';
    if (!confirm(`Really save edits for ${dayLabel}?`)) return;

    const out = { attendance: [] };
    document.querySelectorAll('.att-toggle').forEach(attEl => {
      const m = attEl.id.match(/^att_(\d+)_(\d+)$/);
      if (!m) return;
      const sid = +m[1], uid = +m[2];
      const motEl = document.getElementById(`mot_${sid}_${uid}`);
      const txtEl = document.getElementById(`mot_text_${sid}_${uid}`);
      const present   = !!attEl.checked;
      const motivated = !present && !!(motEl && motEl.checked);
      const reason    = motivated ? (txtEl?.value || '') : '';
      out.attendance.push({ schedule_id: sid, user_id: uid, present, motivated, motivation: reason });
    });

    const url = `${location.origin}${location.pathname}${location.search}`;
    try {
      const resp = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(out) });
      const j = await resp.json();
      if (j.success) { alert('Changes saved successfully!'); location.reload(); }
      else { alert('Save failed: ' + (j.error || 'unknown')); }
    } catch (e) {
      alert('Network error: ' + e.message);
    }
  });
});

/* ===================== Optional: Primary / Reset view-mode buttons ===================== */
onReady(function () {
  const primary = document.getElementById('btnPrimary');
  if (primary) {
    primary.addEventListener('click', () => {
      location.href = 'greeting.php?view=primary';
    });
  }

  const reset = document.getElementById('btnResetView');
  if (reset) {
    reset.addEventListener('click', () => {
      location.href = 'greeting.php?view=reset';
    });
  }
});
