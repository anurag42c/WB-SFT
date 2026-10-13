// Weighing screen: live weight of every scale, scale picker (remembered per PC), ANPR button + auto-read.
(function () {
  const wrap = document.querySelector('[data-scales]');
  if (!wrap) return;
  const $$ = s => document.querySelectorAll(s);
  const auto = wrap.dataset.anprAuto === '1', csrf = wrap.dataset.csrf;
  let picked = null, last = {}, armed = true, busy = false;
  try { picked = parseInt(localStorage.getItem('wb_scale')) || null; } catch (e) {}

  function pick(id) {
    picked = id;
    try { localStorage.setItem('wb_scale', id); } catch (e) {}
    $$('.scale-card').forEach(c => c.classList.toggle('sel', +c.dataset.id === id));
    $$('input.scale_id').forEach(i => i.value = id);
    armed = true; render();
  }
  $$('.scale-card').forEach(c => c.addEventListener('click', () => pick(+c.dataset.id)));

  function render() {
    const s = last[picked];
    $$('[data-needs-stable]').forEach(b => b.disabled = !(s && s.fresh && s.stable));
    const anprBtn = document.getElementById('anpr_btn'); if (anprBtn) anprBtn.disabled = busy;
  }

  async function readPlate(manual) {
    if (busy) return; busy = true; render();
    const note = document.getElementById('anpr_note'), field = document.getElementById('vehicle_no');
    note.textContent = 'Reading plate...'; note.className = 'hint';
    try {
      const fd = new FormData(); fd.set('scale_id', picked); fd.set('csrf', csrf);
      const r = await (await fetch('api/anpr.php', {method: 'POST', body: fd})).json();
      if (r.ok) {
        field.value = r.plate;
        note.textContent = 'Plate ' + r.read + ' (' + Math.round((r.confidence ?? 1) * 100) + '%)' + (r.known ? ' - known vehicle' + (r.tare_kg ? ', stored tare ' + Math.round(r.tare_kg) + ' kg' : '') : ' - new vehicle')
          + (r.open_ticket_id ? ' - HAS AN OPEN TICKET: use "2nd weight" on the right' : '');
        note.className = 'hint ' + (r.open_ticket_id ? 'warnt' : 'okt');
      } else { note.textContent = (r.error || 'Not read') + (manual ? '' : ' (auto)'); note.className = 'hint badt'; }
    } catch (e) { note.textContent = 'ANPR request failed'; note.className = 'hint badt'; }
    busy = false; render();
  }
  const btn = document.getElementById('anpr_btn'); if (btn) btn.addEventListener('click', () => readPlate(true));

  async function tick() {
    try {
      const data = (await (await fetch('api/live.php', {cache: 'no-store'})).json()).scales || [];
      data.forEach(s => {
        last[s.id] = s;
        const c = document.querySelector('.scale-card[data-id="' + s.id + '"]'); if (!c) return;
        c.querySelector('[data-w]').firstChild.nodeValue = s.fresh ? Number(s.weight).toLocaleString('en-IN', {maximumFractionDigits: 2}) : '---';
        const b = c.querySelector('[data-st]');
        b.className = 'badge ' + (!s.fresh ? 'bad' : s.stable ? 'ok' : 'warn');
        b.textContent = !s.fresh ? 'NO DATA' : s.stable ? 'STABLE' : 'MOTION';
        c.querySelector('[data-msg]').textContent = s.daemon_alive ? (s.message || s.status) : 'Reader not running';
      });
      if (!picked || !last[picked]) { const f = data[0]; if (f) pick(f.id); }
      // Auto ANPR: once per truck, when it settles on the selected scale and the vehicle box is still empty.
      const s = last[picked], field = document.getElementById('vehicle_no');
      if (auto && s) {
        if (s.fresh && s.stable && s.weight >= s.min && armed && field && !field.value.trim()) { armed = false; readPlate(false); }
        if (s.fresh && s.weight < s.min) { armed = true; }
      }
      render();
    } catch (e) {}
  }
  tick(); setInterval(tick, 700);
})();

// Setup page: live daemon panel for one scale
(function () {
  const d = document.querySelector('[data-live="weight"]');
  if (!d) return;
  const id = d.dataset.scale;
  async function t() {
    try {
      const s = ((await (await fetch('api/live.php', {cache: 'no-store'})).json()).scales || []).find(x => String(x.id) === id);
      if (!s) return;
      d.firstChild.nodeValue = s.fresh ? Number(s.weight).toLocaleString('en-IN', {maximumFractionDigits: 2}) : '---';
      const st = document.querySelector('[data-live="stable"]'); st.className = 'badge ' + (!s.fresh ? 'bad' : s.stable ? 'ok' : 'warn'); st.textContent = !s.fresh ? 'NO DATA' : s.stable ? 'STABLE' : 'MOTION';
      document.querySelector('[data-live="msg"]').textContent = s.daemon_alive ? (s.message || s.status) : 'Reader not running (start bin/scale_supervisor.php)';
      document.querySelector('[data-live="raw"]').textContent = s.raw || '';
    } catch (e) {}
  }
  t(); setInterval(t, 800);
})();

// Gate screen: live gate state, open/close buttons, plate read for the entry form.
(function () {
  const wrap = document.querySelector('[data-gates]');
  if (!wrap) return;
  const csrf = wrap.dataset.csrf, msg = document.getElementById('gmsg');
  const cls = {OPEN: 'ok', CLOSED: 'bad', UNKNOWN: ''};
  async function refresh() {
    try {
      const d = await (await fetch('api/gate.php?action=state', {cache: 'no-store'})).json();
      (d.gates || []).forEach(g => {
        const c = document.querySelector('.gate-card[data-id="' + g.id + '"]'); if (!c) return;
        const b = c.querySelector('[data-gstate]'); b.className = 'badge ' + (cls[g.state] || '');
        b.textContent = g.state === 'UNKNOWN' ? 'NO COMMAND YET' : ('LAST: ' + g.state) + (g.closes_in !== null ? ' (closes in ' + g.closes_in + 's)' : '');
        c.querySelector('[data-glast]').textContent = g.last ? (g.last.at.substring(11) + ' ' + g.last.action + ' by ' + g.last.source + (g.last.ok == 1 ? '' : ' - FAILED: ' + g.last.message)) : '';
      });
    } catch (e) {}
  }
  document.querySelectorAll('[data-gcmd]').forEach(btn => btn.addEventListener('click', async () => {
    const id = btn.closest('.gate-card').dataset.id, act = btn.dataset.gcmd;
    btn.disabled = true; msg.textContent = 'Sending ' + act + '...'; msg.className = 'hint';
    const fd = new FormData(); fd.set('action', 'command'); fd.set('do', act); fd.set('gate_id', id); fd.set('csrf', csrf);
    try {
      const r = await (await fetch('api/gate.php', {method: 'POST', body: fd})).json();
      msg.textContent = r.message; msg.className = 'hint ' + (r.ok ? 'okt' : 'badt');
    } catch (e) { msg.textContent = 'Request failed'; msg.className = 'hint badt'; }
    setTimeout(() => { btn.disabled = false; }, 800); refresh();
  }));
  const anpr = document.getElementById('g_anpr');
  if (anpr) anpr.addEventListener('click', async () => {
    const note = document.getElementById('g_note'), f = document.getElementById('g_vehicle');
    note.textContent = 'Reading plate...'; note.className = 'hint'; anpr.disabled = true;
    const fd = new FormData(); fd.set('action', 'anpr'); fd.set('gate_id', document.getElementById('entry_gate').value); fd.set('csrf', csrf);
    try {
      const r = await (await fetch('api/gate.php', {method: 'POST', body: fd})).json();
      if (r.ok) {
        f.value = r.plate;
        note.textContent = 'Plate ' + r.plate + ' (' + Math.round(r.confidence * 100) + '%)' + (r.blocked ? ' - BLOCKED VEHICLE' + (r.block_reason ? ': ' + r.block_reason : '') : r.known ? ' - known vehicle' : ' - new vehicle') + (r.inside_entry_no ? ' - ALREADY INSIDE (' + r.inside_entry_no + ')' : '');
        note.className = 'hint ' + (r.blocked || r.inside_entry_no ? 'badt' : 'okt');
      } else { note.textContent = r.error; note.className = 'hint badt'; }
    } catch (e) { note.textContent = 'ANPR request failed'; note.className = 'hint badt'; }
    anpr.disabled = false;
  });
  refresh(); setInterval(refresh, 2000);
})();

// Document screens: upload spinner, tag switch, editable line items
(function () {
  const up = document.getElementById('upform');
  if (up) {
    up.addEventListener('submit', () => { const m = document.getElementById('upmsg'); m.className = 'hint busy'; m.textContent = 'Uploading and reading the document - this can take up to a minute...'; document.getElementById('upbtn').disabled = true; });
    const lab = document.getElementById('ref_l');
    up.querySelectorAll('input[name=doc_type]').forEach(r => r.addEventListener('change', () => { lab.textContent = r.value === 'PO_INVOICE' ? 'PO number (optional - read from the picture if left empty)' : 'Original invoice / return reference (optional)'; }));
  }
  const dt = document.getElementById('doc_type');
  if (dt) {
    const sync = () => { document.querySelectorAll('.t_po').forEach(e => e.style.display = dt.value === 'PO_INVOICE' ? '' : 'none'); document.querySelectorAll('.t_ret').forEach(e => e.style.display = dt.value === 'MATERIAL_RETURN' ? '' : 'none'); };
    dt.addEventListener('change', sync); sync();
  }
  const tb = document.getElementById('lines');
  if (tb) {
    const renum = () => tb.querySelectorAll('tr.ln').forEach((tr, i) => tr.querySelectorAll('input').forEach(inp => { inp.name = inp.name.replace(/line\[\d+\]/, 'line[' + i + ']'); }));
    tb.addEventListener('click', e => { if (e.target.classList.contains('rmline')) { const rows = tb.querySelectorAll('tr.ln'); if (rows.length > 1) { e.target.closest('tr').remove(); renum(); } else { rows[0].querySelectorAll('input').forEach(i => i.value = ''); } } });
    const add = document.getElementById('addline');
    if (add) add.addEventListener('click', () => { const last = tb.querySelector('tr.ln:last-child'); const c = last.cloneNode(true); c.querySelectorAll('input').forEach(i => { i.value = ''; i.classList.remove('missing'); }); tb.appendChild(c); renum(); });
  }
})();
