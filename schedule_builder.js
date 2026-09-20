'use strict';
/*
 * schedule_builder.js — client for schedule_builder.php.
 *
 * One row object == one `schedule` DB row. Palette blocks are templates: a drop
 * always creates a NEW row, so a subject can sit in many slots / both groups.
 * Drag & drop is built on Pointer Events because HTML5 DnD never fires on
 * touch, and this page has to work in Telegram's mobile webview too.
 */
(function () {
  const boot = JSON.parse(document.getElementById('sb-boot').textContent);
  const $ = (id) => document.getElementById(id);

  const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  const DAY_SHORT = { Monday: 'Mon', Tuesday: 'Tue', Wednesday: 'Wed', Thursday: 'Thu', Friday: 'Fri', Saturday: 'Sat' };
  const DEFAULT_SLOTS = ['08:00-09:30', '09:45-11:15', '11:30-13:00', '13:30-15:00', '15:15-16:45', '17:00-18:30', '18:45-20:15'];
  const WEEKS = [[null, 'every week'], ['odd', 'odd'], ['even', 'even']];
  const SLOT_RE = /^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-3]):([0-5]\d)$/;
  const LS_KEY = 'sb.v1';

  // ---- state ---------------------------------------------------------------
  let rows = [];
  let attCount = {};
  let snapshot = '';
  let cidSeq = 1;
  let armed = null; // palette template selected for tap-to-place
  const groupName = {};
  boot.groups.forEach((g) => { groupName[g.id] = g.name; });

  const prefs = loadPrefs();
  if (prefs.view !== 'both' && !groupName[prefs.view]) prefs.view = 'both';

  function loadPrefs() {
    const d = { view: 'both', sat: false, slots: [], palette: [] };
    try { return Object.assign(d, JSON.parse(localStorage.getItem(LS_KEY) || '{}')); } catch { return d; }
  }
  function savePrefs() { try { localStorage.setItem(LS_KEY, JSON.stringify(prefs)); } catch { /* private mode */ } }

  function adopt(state) {
    rows = state.rows.map((r) => Object.assign({ cid: cidSeq++ }, r));
    attCount = state.attCount || {};
    snapshot = serialize();
    if (rows.some((r) => r.day_of_week === 'Saturday')) prefs.sat = true;
  }
  const wire = (r) => ({
    id: r.id ?? null, group_id: r.group_id, day_of_week: r.day_of_week, time_slot: r.time_slot,
    subject: r.subject, location: r.location || null, type: r.type || null,
    week_type: r.week_type || null, subgroup: r.subgroup || null,
  });
  // Sunday rows (none today) are not drawn but must survive a save untouched.
  function serialize() {
    return JSON.stringify(rows.map(wire).sort((a, b) => JSON.stringify(a) < JSON.stringify(b) ? -1 : 1));
  }
  const isDirty = () => serialize() !== snapshot;
  const locked = (r) => (r.id && attCount[r.id]) || 0;

  // ---- helpers -------------------------------------------------------------
  function el(tag, attrs, ...kids) {
    const n = document.createElement(tag);
    for (const k in attrs || {}) {
      const v = attrs[k];
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') n.className = v;
      else if (k === 'text') n.textContent = v;
      else if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
      else if (k === 'dataset') Object.assign(n.dataset, v);
      else n.setAttribute(k, v === true ? '' : v);
    }
    kids.flat().forEach((c) => { if (c !== null && c !== undefined) n.append(c); });
    return n;
  }
  const slotStart = (s) => parseInt(s.slice(0, 2), 10) * 60 + parseInt(s.slice(3, 5), 10);
  const tplKey = (t) => [t.subject, t.type || '', t.location || ''].join('');
  const weeksOverlap = (a, b) => !a || !b || a === b;
  const sgOverlap = (a, b) => !a || !b || a === b;

  function allSlots() {
    const set = new Set(DEFAULT_SLOTS);
    prefs.slots.forEach((s) => set.add(s));
    rows.forEach((r) => set.add(r.time_slot));
    return [...set].sort((a, b) => slotStart(a) - slotStart(b) || (a < b ? -1 : 1));
  }
  function paletteItems() {
    const map = new Map();
    rows.forEach((r) => {
      const t = { subject: r.subject, type: r.type || null, location: r.location || null };
      if (!map.has(tplKey(t))) map.set(tplKey(t), t);
    });
    prefs.palette.forEach((t) => { if (!map.has(tplKey(t))) map.set(tplKey(t), Object.assign({ custom: true }, t)); });
    return [...map.values()].sort((a, b) => a.subject.localeCompare(b.subject, 'ro') || (a.type || '').localeCompare(b.type || ''));
  }

  // ---- conflicts -----------------------------------------------------------
  function computeWarnings() {
    const warn = []; const bad = new Set();
    const byCell = new Map(); const byRoom = new Map();
    rows.forEach((r) => {
      const c = r.group_id + '|' + r.day_of_week + '|' + r.time_slot;
      (byCell.get(c) || byCell.set(c, []).get(c)).push(r);
      if (r.location) {
        const k = r.location.toLowerCase() + '|' + r.day_of_week + '|' + r.time_slot;
        (byRoom.get(k) || byRoom.set(k, []).get(k)).push(r);
      }
    });
    const at = (r) => DAY_SHORT[r.day_of_week] + ' ' + r.time_slot;
    byCell.forEach((list) => {
      for (let i = 0; i < list.length; i++) for (let j = i + 1; j < list.length; j++) {
        const a = list[i], b = list[j];
        if (weeksOverlap(a.week_type, b.week_type) && sgOverlap(a.subgroup, b.subgroup)) {
          bad.add(a.cid); bad.add(b.cid);
          warn.push(groupName[a.group_id] + ', ' + at(a) + ': “' + a.subject + '” and “' + b.subject + '” overlap (same week and subgroup).');
        } else if (!a.week_type && !b.week_type) {
          warn.push(groupName[a.group_id] + ', ' + at(a) + ': two every-week subgroup lessons share a slot — the weekly table on the schedule page shows only the first one.');
        }
      }
    });
    byRoom.forEach((list) => {
      for (let i = 0; i < list.length; i++) for (let j = i + 1; j < list.length; j++) {
        const a = list[i], b = list[j];
        // Same subject in one room = shared lecture / split subgroups: normal.
        if (a.subject !== b.subject && weeksOverlap(a.week_type, b.week_type)) {
          bad.add(a.cid); bad.add(b.cid);
          warn.push('Room ' + a.location + ', ' + at(a) + ': “' + a.subject + '” (' + groupName[a.group_id] + ') and “' + b.subject + '” (' + groupName[b.group_id] + ').');
        }
      }
    });
    return { warn: [...new Set(warn)], bad };
  }

  // ---- rendering -----------------------------------------------------------
  function chipBody(t) {
    return [
      t.type ? el('span', { class: 'sb-type sb-type-' + t.type, text: t.type }) : null,
      el('span', { class: 'sb-subj', text: t.subject }),
      t.location ? el('span', { class: 'sb-room', text: t.location }) : null,
    ];
  }

  function renderPalette() {
    const box = $('sb-palette'); box.textContent = '';
    const items = paletteItems();
    if (!items.length) box.append(el('span', { class: 'muted', text: 'No blocks yet — add the first subject below.' }));
    items.forEach((t) => {
      const chip = el('div', {
        class: 'sb-chip sb-tpl' + (armed && tplKey(armed) === tplKey(t) ? ' armed' : ''),
        tabindex: '0', role: 'button', title: 'Drag into a slot, or tap then tap a slot',
      }, chipBody(t));
      if (t.custom) {
        chip.append(el('button', {
          type: 'button', class: 'sb-x', 'aria-label': 'Remove block', text: '×',
          onpointerdown: (e) => e.stopPropagation(),
          onclick: (e) => {
            e.stopPropagation();
            prefs.palette = prefs.palette.filter((p) => tplKey(p) !== tplKey(t));
            if (armed && tplKey(armed) === tplKey(t)) armed = null;
            savePrefs(); renderPalette();
          },
        }));
      }
      bindDrag(chip, { kind: 'tpl', tpl: t });
      box.append(chip);
    });
    document.body.classList.toggle('sb-armed', !!armed);
    $('sb-armed-pill').hidden = !armed;
    if (armed) $('sb-armed-text').textContent = 'Tap a slot to place “' + armed.subject + '”';
  }

  function renderViewTabs() {
    const box = $('sb-view'); box.textContent = '';
    const opts = boot.groups.map((g) => [g.id, g.name]);
    if (boot.groups.length > 1) opts.push(['both', 'Both']);
    opts.forEach(([v, label]) => {
      box.append(el('a', {
        href: '#', class: prefs.view === v ? 'active' : '', text: label,
        onclick: (e) => { e.preventDefault(); prefs.view = v; savePrefs(); renderViewTabs(); renderGrids(); },
      }));
    });
  }

  function renderGrids() {
    const host = $('sb-grids'); host.textContent = '';
    const { warn, bad } = computeWarnings();
    const days = prefs.sat ? DAYS : DAYS.slice(0, 5);
    const slots = allSlots();

    boot.groups.filter((g) => prefs.view === 'both' || prefs.view === g.id).forEach((g) => {
      const mine = rows.filter((r) => r.group_id === g.id);
      const table = el('table', { class: 'sb-grid' });
      table.append(el('thead', {}, el('tr', {}, el('th', { class: 'sb-timecol', text: 'Time' }), days.map((d) => el('th', { text: DAY_SHORT[d] })))));
      const tb = el('tbody');
      slots.forEach((s) => {
        const used = rows.some((r) => r.time_slot === s);
        const th = el('th', { class: 'sb-timecol' }, el('span', { text: s.replace('-', '–') }), el('small', { class: 'sb-rowgroup', text: g.name }));
        if (!used && !DEFAULT_SLOTS.includes(s)) {
          th.append(el('button', {
            type: 'button', class: 'sb-x', 'aria-label': 'Remove time slot', text: '×',
            onclick: () => { prefs.slots = prefs.slots.filter((x) => x !== s); savePrefs(); renderGrids(); },
          }));
        }
        const tr = el('tr', {}, th);
        days.forEach((d) => {
          const td = el('td', { class: 'sb-cell' });
          WEEKS.forEach(([w, label]) => {
            const zone = el('div', {
              class: 'sb-zone sb-zone-' + (w || 'all'),
              dataset: { gid: g.id, day: d, slot: s, week: w || '' },
              onclick: (e) => { if (armed && e.target === zone) { place(armed, zone.dataset); } },
            }, el('span', { class: 'sb-zone-label', text: w ? label : DAY_SHORT[d] + ' · ' + label }));
            mine.filter((r) => r.day_of_week === d && r.time_slot === s && (r.week_type || null) === w)
              .sort((a, b) => (a.subgroup || 0) - (b.subgroup || 0))
              .forEach((r) => zone.append(blockEl(r, bad.has(r.cid))));
            td.append(zone);
          });
          tr.append(td);
        });
        tb.append(tr);
      });
      table.append(tb);
      host.append(el('section', { class: 'sb-group' },
        el('h3', { class: 'sb-group-title' }, g.name, el('span', { class: 'muted', text: ' · ' + mine.length + ' lessons' })),
        el('div', { class: 'sb-grid-wrap' }, table)));
    });

    const box = $('sb-warnings');
    box.hidden = !warn.length;
    $('sb-warn-count').textContent = '⚠️ ' + warn.length + (warn.length === 1 ? ' warning' : ' warnings') + ' (saving is still allowed)';
    const ul = $('sb-warn-list'); ul.textContent = '';
    warn.forEach((w) => ul.append(el('li', { text: w })));
    refreshDirty();
  }

  function blockEl(r, conflict) {
    const n = locked(r);
    const b = el('div', {
      class: 'sb-chip sb-block' + (conflict ? ' conflict' : ''), tabindex: '0', role: 'button',
      title: r.subject + (n ? ' — ' + n + ' attendance records' : ''),
    }, chipBody(r),
      r.subgroup ? el('span', { class: 'sb-sg', text: 'sg ' + r.subgroup }) : null,
      n ? el('span', { class: 'sb-lock', text: '🔒' + n }) : null);
    bindDrag(b, { kind: 'row', row: r });
    b.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openEditor(r); } });
    return b;
  }

  function refreshDirty() {
    const d = isDirty();
    $('sb-dirty').hidden = !d;
    $('sb-save').disabled = !d;
    $('sb-revert').disabled = !d;
  }
  function renderAll() { renderViewTabs(); renderPalette(); renderGrids(); $('sb-sat').checked = !!prefs.sat; }

  function banner(msg, kind) {
    const b = $('sb-banner');
    b.hidden = !msg; b.textContent = msg || ''; b.className = 'sb-banner ' + (kind || 'ok');
    if (msg) b.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  // ---- mutations -----------------------------------------------------------
  function place(tpl, ds) {
    rows.push({
      cid: cidSeq++, id: null, group_id: Number(ds.gid), day_of_week: ds.day, time_slot: ds.slot,
      subject: tpl.subject, location: tpl.location || null, type: tpl.type || null,
      week_type: ds.week || null, subgroup: tpl.subgroup || null,
    });
    renderPalette(); renderGrids();
  }
  function moveRow(r, ds) {
    if (Number(ds.gid) !== r.group_id) { place(r, ds); return; } // other group's grid: copy → shared lecture
    r.day_of_week = ds.day; r.time_slot = ds.slot; r.week_type = ds.week || null;
    renderGrids();
  }
  function removeRow(r) {
    if (locked(r)) { banner('“' + r.subject + '” already has attendance logged, so it cannot be removed. Move or edit it instead.', 'err'); return false; }
    rows = rows.filter((x) => x !== r);
    renderPalette(); renderGrids();
    return true;
  }

  // ---- drag & drop (Pointer Events) ----------------------------------------
  let drag = null;
  const HOLD_MS = 280, MOVE_PX = 5;

  function bindDrag(node, payload) {
    node.addEventListener('pointerdown', (e) => {
      if (e.button !== undefined && e.button !== 0) return;
      if (drag) return;
      drag = { node, payload, x0: e.clientX, y0: e.clientY, x: e.clientX, y: e.clientY, id: e.pointerId, touch: e.pointerType !== 'mouse', active: false, ghost: null, over: null, timer: 0, raf: 0 };
      // Touch: a hold starts the drag, so a plain swipe still scrolls the page.
      if (drag.touch) drag.timer = setTimeout(() => { if (drag && !drag.active) startDrag(); }, HOLD_MS);
    });
    node.addEventListener('dragstart', (e) => e.preventDefault());
    node.addEventListener('contextmenu', (e) => { if (drag) e.preventDefault(); });
  }

  function startDrag() {
    drag.active = true;
    const r = drag.node.getBoundingClientRect();
    drag.dx = drag.x0 - r.left; drag.dy = drag.y0 - r.top;
    const g = drag.node.cloneNode(true);
    g.classList.add('sb-ghost'); g.style.width = r.width + 'px';
    document.body.append(g); drag.ghost = g;
    drag.node.classList.add('sb-dragging');
    document.body.classList.add('sb-is-dragging');
    $('sb-trash').hidden = drag.payload.kind !== 'row';
    try { window.Telegram.WebApp.HapticFeedback.impactOccurred('light'); } catch { /* not in Telegram */ }
    positionGhost();
    drag.raf = requestAnimationFrame(autoScroll);
  }

  function positionGhost() {
    drag.ghost.style.transform = 'translate(' + (drag.x - drag.dx) + 'px,' + (drag.y - drag.dy) + 'px)';
    const hit = document.elementFromPoint(drag.x, drag.y);
    const over = hit ? hit.closest('.sb-zone, #sb-trash') : null;
    if (over !== drag.over) {
      if (drag.over) drag.over.classList.remove('sb-over');
      if (over) over.classList.add('sb-over');
      drag.over = over;
    }
  }

  function autoScroll() {
    if (!drag || !drag.active) return;
    const EDGE = 56, MAX = 16, h = window.innerHeight;
    let dy = 0;
    if (drag.y < EDGE) dy = -MAX * (1 - drag.y / EDGE);
    else if (drag.y > h - EDGE) dy = MAX * (1 - (h - drag.y) / EDGE);
    if (dy) window.scrollBy(0, dy);
    const hit = document.elementFromPoint(drag.x, drag.y);
    const wrap = hit ? hit.closest('.sb-grid-wrap') : null;
    if (wrap) {
      const b = wrap.getBoundingClientRect();
      if (drag.x < b.left + EDGE) wrap.scrollLeft -= MAX;
      else if (drag.x > b.right - EDGE) wrap.scrollLeft += MAX;
    }
    if (dy || wrap) positionGhost();
    drag.raf = requestAnimationFrame(autoScroll);
  }

  document.addEventListener('pointermove', (e) => {
    if (!drag || e.pointerId !== drag.id) return;
    drag.x = e.clientX; drag.y = e.clientY;
    if (!drag.active) {
      const far = Math.hypot(drag.x - drag.x0, drag.y - drag.y0) > MOVE_PX;
      if (!far) return;
      if (drag.touch) { endDrag(false); return; } // moved before the hold elapsed → it's a scroll
      startDrag();
    }
    positionGhost();
  });
  // Once a drag is live, stop the browser from turning the gesture into a pan
  // (which would fire pointercancel). Must be non-passive to be allowed to.
  document.addEventListener('touchmove', (e) => { if (drag && drag.active) e.preventDefault(); }, { passive: false });
  document.addEventListener('pointerup', (e) => { if (drag && e.pointerId === drag.id) endDrag(true); });
  document.addEventListener('pointercancel', (e) => { if (drag && e.pointerId === drag.id) endDrag(false); });

  function endDrag(commit) {
    const d = drag; drag = null;
    clearTimeout(d.timer); cancelAnimationFrame(d.raf);
    if (d.ghost) d.ghost.remove();
    d.node.classList.remove('sb-dragging');
    document.body.classList.remove('sb-is-dragging');
    $('sb-trash').hidden = true;
    if (d.over) d.over.classList.remove('sb-over');
    if (!commit) return;

    if (!d.active) { // a click / tap
      if (d.payload.kind === 'row') openEditor(d.payload.row);
      else { armed = armed && tplKey(armed) === tplKey(d.payload.tpl) ? null : d.payload.tpl; renderPalette(); }
      return;
    }
    if (!d.over) return;
    banner('');
    if (d.over.id === 'sb-trash') { if (d.payload.kind === 'row') removeRow(d.payload.row); return; }
    if (d.payload.kind === 'tpl') place(d.payload.tpl, d.over.dataset);
    else moveRow(d.payload.row, d.over.dataset);
  }

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (drag) endDrag(false);
    else if (!$('sb-modal').hidden) closeModal();
    else if (armed) { armed = null; renderPalette(); }
  });

  // ---- modal / editor ------------------------------------------------------
  function openModal(...content) {
    const m = $('sb-modal'); const card = m.firstElementChild;
    card.textContent = ''; card.append(...content);
    m.hidden = false;
    const f = card.querySelector('input,select,button'); if (f) f.focus();
  }
  function closeModal() { $('sb-modal').hidden = true; }
  $('sb-modal').addEventListener('pointerdown', (e) => { if (e.target === $('sb-modal')) closeModal(); });

  function select(options, value) {
    const s = el('select');
    options.forEach(([v, label]) => { const o = el('option', { value: v, text: label }); if (String(v) === String(value ?? '')) o.selected = true; s.append(o); });
    return s;
  }
  const field = (label, control) => el('label', { class: 'sb-field' }, el('span', { text: label }), control);

  function openEditor(r) {
    const n = locked(r);
    const subject = el('input', { type: 'text', maxlength: '50', value: r.subject, required: true });
    const room = el('input', { type: 'text', maxlength: '20', value: r.location || '' });
    const type = select([['', 'no type'], ['curs', 'curs'], ['sem', 'sem'], ['lab', 'lab']], r.type);
    const week = select([['', 'every week'], ['odd', 'odd weeks'], ['even', 'even weeks']], r.week_type);
    const sg = select([['', 'whole group'], ['1', 'subgroup 1'], ['2', 'subgroup 2']], r.subgroup);
    const day = select((prefs.sat || r.day_of_week === 'Saturday' ? DAYS : DAYS.slice(0, 5)).map((d) => [d, d]), r.day_of_week);
    const slot = select(allSlots().map((s) => [s, s.replace('-', '–')]), r.time_slot);
    const others = boot.groups.filter((g) => g.id !== r.group_id);

    const apply = () => {
      const s = subject.value.trim();
      if (!s) { subject.focus(); return false; }
      Object.assign(r, {
        subject: s, location: room.value.trim() || null, type: type.value || null,
        week_type: week.value || null, subgroup: sg.value ? Number(sg.value) : null,
        day_of_week: day.value, time_slot: slot.value,
      });
      return true;
    };
    const form = el('form', { class: 'sb-editor', onsubmit: (e) => { e.preventDefault(); if (apply()) { closeModal(); renderPalette(); renderGrids(); } } },
      el('h3', { text: groupName[r.group_id] + ' · lesson' }),
      n ? el('p', { class: 'sb-note', text: '🔒 ' + n + ' attendance records are attached. Edits keep them linked; the lesson cannot be removed until the next “New semester”.' }) : null,
      field('Subject', subject),
      el('div', { class: 'sb-row' }, field('Type', type), field('Room', room)),
      el('div', { class: 'sb-row' }, field('Weeks', week), field('Subgroup', sg)),
      el('div', { class: 'sb-row' }, field('Day', day), field('Time', slot)),
      el('div', { class: 'sb-actions' },
        el('button', { type: 'submit', class: 'btn-submit', text: 'Apply' }),
        others.map((g) => el('button', {
          type: 'button', class: 'btn-nav', text: 'Copy to ' + g.name,
          onclick: () => {
            if (!apply()) return;
            rows.push(Object.assign({}, r, { cid: cidSeq++, id: null, group_id: g.id }));
            closeModal(); renderPalette(); renderGrids();
            banner('Copied “' + r.subject + '” to ' + g.name + ' (' + DAY_SHORT[r.day_of_week] + ' ' + r.time_slot + ').', 'ok');
          },
        })),
        el('button', {
          type: 'button', class: 'btn-nav', text: 'Duplicate',
          onclick: () => { if (!apply()) return; const c = Object.assign({}, r, { cid: cidSeq++, id: null }); rows.push(c); renderGrids(); openEditor(c); },
        }),
        el('button', {
          type: 'button', class: 'btn-nav sb-danger-btn', text: 'Remove', disabled: !!n,
          onclick: () => { closeModal(); removeRow(r); },
        }),
        el('button', { type: 'button', class: 'btn-nav', text: 'Cancel', onclick: closeModal })));
    openModal(form);
  }

  // ---- server --------------------------------------------------------------
  async function api(body) {
    let res;
    try {
      res = await fetch(location.pathname, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, credentials: 'same-origin', body: JSON.stringify(body) });
    } catch { throw new Error('Network error — is the Pi reachable?'); }
    let data = null;
    try { data = await res.json(); } catch { /* non-JSON error page */ }
    if (!res.ok || !data || !data.success) throw new Error((data && data.error) || ('Server error (' + res.status + ')'));
    return data;
  }

  $('sb-save').addEventListener('click', async () => {
    const btn = $('sb-save'); btn.disabled = true; btn.textContent = 'Saving…';
    try {
      const d = await api({ action: 'save', rows: rows.map(wire) });
      adopt(d); renderAll();
      banner('✅ Saved — ' + d.inserted + ' added, ' + d.updated + ' changed, ' + d.deleted + ' removed.', 'ok');
    } catch (e) {
      banner('❌ ' + e.message, 'err'); refreshDirty();
    } finally { btn.textContent = 'Save schedule'; }
  });

  $('sb-revert').addEventListener('click', () => {
    openModal(el('div', { class: 'sb-editor' },
      el('h3', { text: 'Discard unsaved changes?' }),
      el('p', { class: 'muted', text: 'The grid goes back to what is saved on the server.' }),
      el('div', { class: 'sb-actions' },
        el('button', { type: 'button', class: 'btn-nav sb-danger-btn', text: 'Discard', onclick: () => { snapshot = serialize(); location.reload(); } }), // snapshot: skip the beforeunload prompt
        el('button', { type: 'button', class: 'btn-nav', text: 'Keep editing', onclick: closeModal }))));
  });

  $('sb-reset').addEventListener('click', () => {
    const totalAtt = Object.values(attCount).reduce((a, b) => a + b, 0);
    const saved = rows.filter((r) => r.id).length;
    const input = el('input', { type: 'text', autocomplete: 'off', autocapitalize: 'characters', placeholder: boot.resetPhrase });
    const go = el('button', { type: 'submit', class: 'btn-nav sb-danger-btn', text: 'Delete everything', disabled: true });
    input.addEventListener('input', () => { go.disabled = input.value.trim() !== boot.resetPhrase; });
    openModal(el('form', {
      class: 'sb-editor',
      onsubmit: async (e) => {
        e.preventDefault(); go.disabled = true; go.textContent = 'Deleting…';
        try {
          // Keep last term's blocks in the palette: some subjects carry over.
          const keep = paletteItems().map((t) => ({ subject: t.subject, type: t.type || null, location: t.location || null }));
          const d = await api({ action: 'new_semester', confirm: input.value.trim() });
          prefs.palette = keep; savePrefs();
          adopt(d); closeModal(); renderAll();
          banner('✅ New semester started — removed ' + d.deleted.schedule + ' lessons and ' + d.deleted.attendance + ' attendance records. Last term’s blocks stay in the palette; remove the ones you no longer need with ×.', 'ok');
        } catch (err) { closeModal(); banner('❌ ' + err.message, 'err'); }
      },
    },
      el('h3', { text: 'Start a new semester' }),
      el('p', { text: 'This permanently deletes ' + saved + ' saved lessons and ' + totalAtt + ' attendance records (plus their edit history) for ALL groups.' }),
      isDirty() ? el('p', { class: 'sb-note', text: 'Your unsaved changes on this page will be discarded too.' }) : null,
      el('p', { class: 'sb-note', text: 'Run a backup on the Pi first (manual-backup.sh). This cannot be undone from the app.' }),
      field('Type ' + boot.resetPhrase + ' to confirm', input),
      el('div', { class: 'sb-actions' }, go, el('button', { type: 'button', class: 'btn-nav', text: 'Cancel', onclick: closeModal }))));
  });

  // ---- toolbar -------------------------------------------------------------
  $('sb-sat').addEventListener('change', (e) => {
    if (!e.target.checked && rows.some((r) => r.day_of_week === 'Saturday')) {
      e.target.checked = true; banner('Saturday has lessons — move or remove them before hiding the column.', 'err'); return;
    }
    prefs.sat = e.target.checked; savePrefs(); renderGrids();
  });

  $('sb-add-slot').addEventListener('click', () => {
    const from = el('input', { type: 'time', required: true });
    const to = el('input', { type: 'time', required: true });
    const err = el('p', { class: 'sb-note', hidden: true });
    openModal(el('form', {
      class: 'sb-editor',
      onsubmit: (e) => {
        e.preventDefault();
        const s = from.value.slice(0, 5) + '-' + to.value.slice(0, 5);
        const m = SLOT_RE.exec(s);
        if (!m || slotStart(s) >= Number(m[3]) * 60 + Number(m[4])) { err.hidden = false; err.textContent = 'End time must be after the start time.'; return; }
        if (!allSlots().includes(s)) { prefs.slots.push(s); savePrefs(); }
        closeModal(); renderGrids();
      },
    },
      el('h3', { text: 'Add a time slot' }),
      el('div', { class: 'sb-row' }, field('From', from), field('To', to)), err,
      el('div', { class: 'sb-actions' },
        el('button', { type: 'submit', class: 'btn-submit', text: 'Add' }),
        el('button', { type: 'button', class: 'btn-nav', text: 'Cancel', onclick: closeModal }))));
  });

  $('sb-new-block').addEventListener('submit', (e) => {
    e.preventDefault();
    const subject = $('sb-nb-subject').value.trim();
    if (!subject) return;
    const t = { subject, type: $('sb-nb-type').value || null, location: $('sb-nb-room').value.trim() || null };
    if (!paletteItems().some((p) => tplKey(p) === tplKey(t))) { prefs.palette.push(t); savePrefs(); }
    armed = t;
    $('sb-nb-room').value = '';
    renderPalette();
  });

  $('sb-armed-cancel').addEventListener('click', () => { armed = null; renderPalette(); });

  // The sticky palette sits right below the sticky toolbar, whose height varies with wrapping.
  const syncToolbarHeight = () => document.documentElement.style.setProperty('--sb-tb-h', document.querySelector('.sb-toolbar').offsetHeight + 'px');
  window.addEventListener('resize', syncToolbarHeight);
  syncToolbarHeight();

  window.addEventListener('beforeunload', (e) => { if (isDirty()) { e.preventDefault(); e.returnValue = ''; } });

  adopt(boot);
  renderAll();
})();
