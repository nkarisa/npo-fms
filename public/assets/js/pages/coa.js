/**
 * Chart of accounts (v5): the shared master chart as a tree or a flat list, ten
 * rows a page, with the account drawer, CSV import (dry run first) and export.
 * Balances, normal sides, statements and every rule come from /api/coa.
 */
(async function () {
  const app = document.getElementById('app');
  const esc = UI.esc;
  const PAGE = 10;
  let data = null;
  // ?q= opens the chart on a search, as the general ledger's "Open in chart of accounts" does.
  const state = { type: 'All', q: new URLSearchParams(window.location.search).get('q') || '', view: 'tree', collapsed: {}, page: 0 };

  // ---- Tree ----

  function parentIndex(list, idx) {
    for (let i = idx - 1; i >= 0; i--) if (list[i].level < list[idx].level) return i;
    return null;
  }

  function matches(a) {
    if (state.type !== 'All' && a.type !== state.type) return false;
    const q = state.q.trim().toLowerCase();
    return !q || `${a.code} ${a.name} ${a.fund} ${a.funder}`.toLowerCase().includes(q);
  }

  /** Flat: postable accounts that match. Tree: every match with its headings, less anything under a collapsed heading. */
  function visibleRows() {
    const list = data.accounts;
    if (state.view === 'flat') return list.filter(a => a.level === 2 && matches(a));

    const keep = new Set();
    list.forEach((a, i) => {
      if (!matches(a)) return;
      for (let cur = i; cur !== null; cur = parentIndex(list, cur)) keep.add(list[cur].code);
    });
    return list.filter((a, i) => {
      if (!keep.has(a.code)) return false;
      for (let p = parentIndex(list, i); p !== null; p = parentIndex(list, p)) if (state.collapsed[list[p].code]) return false;
      return true;
    });
  }

  const anyCollapsed = () => Object.values(state.collapsed).some(Boolean);

  // ---- Page ----

  function segmented(items, isActive, attr) {
    return `<div class="coa-seg">${items.map(i => `<button type="button" class="coa-seg-btn ${isActive(i) ? 'on' : ''}" ${attr}="${esc(i.value)}">${esc(i.label)}</button>`).join('')}</div>`;
  }

  function render() {
    const rows = visibleRows();
    const pages = Math.max(1, Math.ceil(rows.length / PAGE));
    state.page = Math.min(state.page, pages - 1);
    const shown = rows.slice(state.page * PAGE, state.page * PAGE + PAGE);
    const leaves = data.accounts.filter(a => a.level === 2).length;

    app.innerHTML = `
      <div class="page-head">
        <div>
          <h1 class="page-title" style="margin-top:0;">Chart of accounts</h1>
          <p class="page-blurb" style="max-width:620px;">Master account structure shared across every entity. Segment values for fund, programme, grant and funder are validated at posting.</p>
        </div>
        <div class="page-actions" style="margin-left:0;margin-inline-start:auto;">
          <button type="button" class="btn" id="coa-template">Start from a template</button>
          <button type="button" class="btn" id="coa-import">Import CSV</button>
          <button type="button" class="btn" id="coa-export">Export</button>
          <button type="button" class="btn btn-primary" id="coa-new">+ New account</button>
        </div>
      </div>
      <div id="coa-stats"></div>

      <div class="coa-toolbar">
        <label class="coa-search"><span>⌕</span><input id="coa-q" value="${esc(state.q)}" placeholder="Search code or account name"></label>
        ${segmented(data.types.map(t => ({ value: t, label: data.typeLabel[t] })), i => i.value === state.type, 'data-type')}
        <div style="margin-inline-start:auto;display:flex;align-items:center;gap:10px;">
          ${state.view === 'tree' ? `<button type="button" class="coa-link" id="coa-toggle-all">${anyCollapsed() ? 'Expand all' : 'Collapse all'}</button>` : ''}
          ${segmented([{ value: 'tree', label: 'Tree' }, { value: 'flat', label: 'Flat' }], i => i.value === state.view, 'data-view')}
        </div>
      </div>

      <div class="coa-card">
        <div style="overflow:auto;">
          <div style="min-width:1584px;">
            <div class="coa-grid coa-head">
              <div>Code</div><div>Account</div><div>Type</div><div>Statement</div><div>Normally</div><div>Restriction</div>
              <div>Fund</div><div>Programme</div><div>Funder</div><div style="text-align:end;">YTD balance (KES)</div><div>Status</div>
            </div>
            ${shown.map(rowHtml).join('')}
            ${rows.length === 0 ? (data.accounts.length === 0 ? emptyChart() : `<div class="coa-empty">No accounts match “${esc(state.q)}”.</div>`) : ''}
          </div>
        </div>
        ${rows.length > PAGE ? pagerHtml(rows.length, pages) : ''}
        <div class="coa-foot">
          <span>${rows.length} rows · ${leaves} postable accounts · ${data.archived} archived</span>
          ${data.lastEdited ? `<span style="margin-inline-start:auto;">Last edited ${esc(data.lastEdited.date)} by ${esc(data.lastEdited.who)}</span>` : ''}
        </div>
      </div>`;

    document.getElementById('coa-stats').appendChild(UI.statGrid(data.stats));
    bind();
  }

  function rowHtml(a) {
    const tree = state.view === 'tree';
    const indent = tree ? 14 + a.level * 18 : 14;
    const chev = a.isHeader && tree ? (state.collapsed[a.code] ? '▶' : '▼') : '';
    const debit = a.normal === 'Debit';
    const bs = a.statement === 'Balance sheet';
    return `
      <div class="coa-grid coa-row ${a.isHeader ? 'header' : ''}" data-code="${esc(a.code)}" style="min-height:${tree ? 32 : 30}px;">
        <div class="coa-code">${esc(a.code)}</div>
        <div style="display:flex;align-items:center;min-width:0;padding:0;">
          <span style="width:${indent}px;flex:0 0 ${indent}px;"></span>
          <button type="button" class="coa-chev" ${chev ? `data-toggle="${esc(a.code)}"` : 'tabindex="-1"'}>${chev}</button>
          <span class="coa-name">${esc(a.name)}</span>
        </div>
        <div class="coa-muted">${esc(a.type)}</div>
        <div style="display:flex;align-items:center;gap:6px;"><span class="coa-dot" style="background:${bs ? '#4B6E93' : '#9A7B3F'};"></span><span class="coa-muted" style="padding:0;white-space:nowrap;">${esc(a.statement)}</span></div>
        <div style="display:flex;align-items:center;gap:6px;"><span class="coa-side ${debit ? 'dr' : 'cr'}">${debit ? 'Dr' : 'Cr'}</span><span class="coa-muted" style="padding:0;">${esc(a.normal)}</span></div>
        <div class="coa-muted">${esc(a.restriction)}</div>
        <div class="coa-cell">${esc(a.fund)}</div>
        <div class="coa-cell">${esc(a.program)}</div>
        <div class="coa-cell" style="color:#6E7873;">${esc(a.funder)}</div>
        <div class="coa-amount">${UI.fmtMoney(a.amount)}</div>
        <div>${a.status === 'Archived' ? '<span style="font-size:10.5px;color:#A3948B;">○ Archived</span>' : '<span style="font-size:10.5px;color:var(--calm);">● Active</span>'}</div>
      </div>`;
  }

  function pagerHtml(total, pages) {
    const p = state.page;
    const from = pages > 9 ? Math.min(Math.max(p - 4, 0), pages - 9) : 0;
    const to = pages > 9 ? from + 9 : pages;
    const buttons = [];
    for (let k = from; k < to; k++) buttons.push(`<button type="button" class="coa-page ${k === p ? 'on' : ''}" data-page="${k}">${k + 1}</button>`);
    return `
      <div class="coa-pager">
        <span>Showing ${p * PAGE + 1}–${Math.min(total, (p + 1) * PAGE)} of ${total} accounts</span>
        <div style="margin-inline-start:auto;display:flex;align-items:center;gap:4px;">
          <button type="button" class="coa-page" data-page="${Math.max(0, p - 1)}" ${p === 0 ? 'disabled' : ''}>‹</button>
          ${buttons.join('')}
          <button type="button" class="coa-page" data-page="${Math.min(pages - 1, p + 1)}" ${p >= pages - 1 ? 'disabled' : ''}>›</button>
        </div>
      </div>`;
  }

  function bind() {
    const q = document.getElementById('coa-q');
    q.addEventListener('input', () => {
      state.q = q.value;
      state.page = 0;
      const at = q.selectionStart;
      render();
      const again = document.getElementById('coa-q');
      again.focus();
      again.setSelectionRange(at, at);
    });
    app.querySelectorAll('[data-type]').forEach(b => b.addEventListener('click', () => { state.type = b.dataset.type; state.page = 0; render(); }));
    app.querySelectorAll('[data-view]').forEach(b => b.addEventListener('click', () => { state.view = b.dataset.view; state.page = 0; render(); }));
    app.querySelectorAll('[data-page]').forEach(b => b.addEventListener('click', () => { state.page = +b.dataset.page; render(); }));
    const toggleAll = document.getElementById('coa-toggle-all');
    if (toggleAll) toggleAll.addEventListener('click', () => {
      state.collapsed = anyCollapsed() ? {} : Object.fromEntries(data.accounts.filter(a => a.level < 2).map(a => [a.code, true]));
      state.page = 0;
      render();
    });
    app.querySelectorAll('.coa-row').forEach(row => row.addEventListener('click', (e) => {
      const toggle = e.target.closest('[data-toggle]');
      if (toggle) {
        e.stopPropagation();
        state.collapsed = { ...state.collapsed, [toggle.dataset.toggle]: !state.collapsed[toggle.dataset.toggle] };
        render();
        return;
      }
      openAccount(data.accounts.find(a => a.code === row.dataset.code));
    }));
    document.getElementById('coa-new').addEventListener('click', () => openAccount(null));
    document.getElementById('coa-export').addEventListener('click', exportCsv);
    document.getElementById('coa-import').addEventListener('click', openImport);
    document.getElementById('coa-template').addEventListener('click', openTemplates);
    const first = document.getElementById('coa-template-first');
    if (first) first.addEventListener('click', openTemplates);
  }

  async function reload() {
    data = await UI.fetchJSON('/api/coa');
    render();
  }

  /** Downloads the chart as the server writes it, with the current filter and search applied. */
  async function exportCsv() {
    const filtered = state.type !== 'All' || state.q.trim() !== '';
    const p = new URLSearchParams({ type: state.type, q: state.q.trim() });
    try {
      const res = await fetch('/api/coa/export?' + p.toString());
      if (!res.ok) throw new Error('Export failed');
      const count = res.headers.get('X-Row-Count');
      UI.download(await res.blob(), `${UI.brand()} chart of accounts.csv`, res.headers.get('Content-Disposition'));
      UI.toast(`${count} accounts exported to CSV${filtered ? ' — the current filter and search were applied.' : '.'}`);
    } catch (err) {
      UI.toast('The chart could not be exported.');
    }
  }

  // ---- Drawers ----

  let drawerEl;

  function drawer(width, html) {
    if (!drawerEl) {
      drawerEl = document.createElement('div');
      drawerEl.className = 'pk';
      document.body.appendChild(drawerEl);
      drawerEl.addEventListener('click', (e) => { if (e.target.closest('[data-close]')) drawerEl.hidden = true; });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape') drawerEl.hidden = true; });
    }
    drawerEl.innerHTML = `<div class="jd-backdrop" data-close></div><div class="pk-panel" style="width:${width}px;" role="dialog" aria-modal="true">${html}</div>`;
    drawerEl.hidden = false;
    return drawerEl.querySelector('.pk-panel');
  }

  const options = (list, current) => list.map(o => {
    const [value, label] = Array.isArray(o) ? o : [o, o];
    return `<option value="${esc(value)}" ${value === current ? 'selected' : ''}>${esc(label)}</option>`;
  }).join('');
  const field = (label, control) => `<label class="coa-field">${esc(label)}${control}</label>`;

  function openAccount(a) {
    const editing = !!a;
    const list = data.accounts;
    const parentIdx = editing ? parentIndex(list, list.indexOf(a)) : null;
    const o = data.options;
    const f = editing ? {
      code: a.code, name: a.name, parent: parentIdx === null ? '— (top level)' : `${list[parentIdx].code} · ${list[parentIdx].name}`,
      type: a.type, normal: a.normal, restriction: a.restriction === '—' ? 'Unrestricted' : a.restriction, currency: a.currency || 'KES',
      fund: a.fund, program: a.program === '—' || a.program === 'Shared services' ? 'Shared' : a.program, grant: a.grant || 'Unassigned', funder: a.funder,
      notes: a.notes || '', postable: a.postable, reconcile: a.reconcile, donorReport: a.donorReport,
    } : {
      code: '', name: '', parent: '5300 · Administration and governance', type: 'Expense', normal: 'Debit', restriction: 'Unrestricted', currency: 'KES',
      fund: 'General Fund', program: 'Shared', grant: 'Unassigned', funder: '—', notes: '', postable: true, reconcile: false, donorReport: true,
    };
    const locked = !data.canManage;
    const check = (name, label, on) => `<label style="display:flex;align-items:center;gap:9px;font-size:12px;cursor:pointer;"><input type="checkbox" name="${name}" ${on ? 'checked' : ''} style="accent-color:var(--accent);width:14px;height:14px;">${esc(label)}</label>`;

    const panel = drawer(480, `
      <div style="flex:0 0 auto;padding:18px 22px 14px;border-bottom:1px solid #EEEDE8;display:flex;align-items:flex-start;gap:12px;">
        <div style="display:flex;flex-direction:column;gap:3px;">
          <div class="jd-caps">${editing ? `Edit account · ${esc(a.code)}` : 'New account'}</div>
          <div style="font-size:16px;font-weight:600;letter-spacing:-0.015em;">${editing ? esc(a.name) : 'Add an account to the chart'}</div>
        </div>
        <button type="button" class="jd-x" data-close aria-label="Close" style="margin-inline-start:auto;">✕</button>
      </div>
      <form id="coa-form" style="flex:1;overflow-y:auto;padding:18px 22px 24px;display:flex;flex-direction:column;gap:18px;">
        ${locked ? '<div class="jd-msg idle">Only the Finance Manager can change the chart. Switch actor in the account menu to make changes.</div>' : ''}
        <div class="coa-section">
          <div class="jd-caps">Identification</div>
          <div style="display:grid;grid-template-columns:118px 1fr;gap:10px;">
            ${field('Account code', `<input name="code" value="${esc(f.code)}" ${editing ? 'readonly title="The code of an existing account cannot change"' : ''} maxlength="${data.codeLength}" inputmode="numeric" placeholder="e.g. 5370" style="font-family:'IBM Plex Mono',monospace;">`)}
            ${field('Account name', `<input name="name" value="${esc(f.name)}">`)}
          </div>
          ${field('Parent account', `<select name="parent">${options(o.parents, f.parent)}</select>`)}
        </div>
        <div style="height:1px;background:#EEEDE8;"></div>
        <div class="coa-section">
          <div class="jd-caps">Classification (IFRS)</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            ${field('Account type', `<select name="type">${options(o.types, f.type)}</select>`)}
            ${field('Normal balance', `<select name="normal" disabled title="Follows the account type; contra accounts such as accumulated depreciation take the other side">${options(['Debit', 'Credit'], f.normal)}</select>`)}
            ${field('Restriction class', `<select name="restriction">${options(o.restrictions, f.restriction)}</select>`)}
            ${field('Currency', `<select name="currency">${options(o.currencies, f.currency)}</select>`)}
          </div>
        </div>
        <div style="height:1px;background:#EEEDE8;"></div>
        <div class="coa-section">
          <div style="display:flex;align-items:baseline;gap:8px;"><div class="jd-caps">Dimensions</div><div style="font-size:10.5px;color:#A3ABA7;">required at posting</div></div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            ${field('Fund', `<select name="fund">${options(o.funds.includes(f.fund) ? o.funds : [f.fund].concat(o.funds), f.fund)}</select>`)}
            ${field('Programme', `<select name="program">${options(o.programmes.includes(f.program) ? o.programmes : [f.program].concat(o.programmes), f.program)}</select>`)}
            ${field('Grant / award', `<select name="grant">${options(o.grants.includes(f.grant) ? o.grants : [f.grant].concat(o.grants), f.grant)}</select>`)}
            ${field('Funder', `<select name="funder">${options(o.funders.includes(f.funder) ? o.funders : [f.funder].concat(o.funders), f.funder)}</select>`)}
          </div>
        </div>
        <div style="height:1px;background:#EEEDE8;"></div>
        <div class="coa-section">
          <div class="jd-caps">Posting rules</div>
          <div style="display:flex;flex-direction:column;gap:8px;border:1px solid #EEEDE8;border-radius:7px;padding:12px 13px;background:#FBFAF7;">
            ${check('postable', 'Allow direct posting to this account', f.postable)}
            ${check('reconcile', 'Require monthly reconciliation', f.reconcile)}
            ${check('donorReport', 'Include in donor expenditure reports', f.donorReport)}
          </div>
          ${field('Description', `<textarea name="notes" rows="3">${esc(f.notes)}</textarea>`)}
        </div>
        ${editing ? `
          <div style="border:1px solid #E4E2DB;border-radius:8px;padding:14px;display:flex;flex-direction:column;gap:10px;background:#FBFAF7;">
            <div class="jd-caps">Balances</div>
            <div style="display:flex;gap:24px;">
              ${a.isHeader
                ? `<div class="coa-bal"><span>Rolled up</span><strong>${UI.fmtMoney(a.amount)}</strong></div>`
                : `<div class="coa-bal"><span>Opening</span><b>${UI.fmtMoney(a.opening)}</b></div>
                   <div class="coa-bal"><span>Movement</span><b>${UI.fmtMoney(a.movement)}</b></div>
                   <div class="coa-bal"><span>YTD</span><strong>${UI.fmtMoney(a.balance)}</strong></div>`}
            </div>
          </div>` : ''}
        <div class="jd-error" id="coa-error" hidden></div>
      </form>
      <div class="jd-actions" style="padding:13px 22px;">
        ${editing && a.status !== 'Archived' ? '<button type="button" class="btn" id="coa-archive" style="border-color:#E0D7D2;color:#8A6A5C;">Archive</button>' : ''}
        <button type="button" class="btn" data-close style="margin-inline-start:auto;">Cancel</button>
        <button type="button" class="btn btn-primary" id="coa-save" ${locked ? 'disabled' : ''}>${editing ? 'Save changes' : 'Create account'}</button>
      </div>`);

    const form = panel.querySelector('#coa-form');
    const error = panel.querySelector('#coa-error');
    const fail = (msg) => { error.textContent = msg; error.hidden = false; };

    panel.querySelector('#coa-save').addEventListener('click', async () => {
      error.hidden = true;
      const fd = new FormData(form);
      const payload = Object.fromEntries(fd.entries());
      ['postable', 'reconcile', 'donorReport'].forEach(k => { payload[k] = fd.has(k); });
      try {
        const res = await fetch(editing ? `/api/coa/${encodeURIComponent(a.code)}` : '/api/coa', {
          method: editing ? 'PUT' : 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify(payload),
        });
        const body = await res.json().catch(() => ({}));
        if (!res.ok) return fail(body.error || 'Could not save the account.');
        drawerEl.hidden = true;
        UI.toast(editing ? `Changes to ${body.account.code} saved.` : `${body.account.code} ${body.account.name} added to the chart of accounts.`);
        reload();
      } catch (err) {
        fail('Could not reach the server.');
      }
    });

    const archive = panel.querySelector('#coa-archive');
    if (archive) archive.addEventListener('click', async () => {
      try {
        await UI.postJSON(`/api/coa/${encodeURIComponent(a.code)}/archive`);
        drawerEl.hidden = true;
        UI.toast(`${a.code} archived. Historical postings are retained.`);
        reload();
      } catch (err) {
        fail(err.message);
      }
    });
  }

  // ---- Import: choose a file, map its columns, read the dry run, then commit ----

  const FIELDS = [
    { key: 'code', label: 'Account code', words: ['account code', 'code', 'account no', 'gl'] },
    { key: 'name', label: 'Account name', words: ['account name', 'description', 'name'] },
    { key: 'type', label: 'Type', words: ['type', 'class', 'category'] },
    { key: 'restriction', label: 'Restriction', words: ['restrict'] },
    { key: 'fund', label: 'Fund', words: ['fund'] },
    { key: 'program', label: 'Programme', words: ['programme', 'program', 'project'] },
    { key: 'funder', label: 'Funder', words: ['funder', 'donor'] },
  ];
  let ci = null;

  function parseCsv(text) {
    const out = [];
    let row = [], cell = '', quoted = false;
    const src = text.replace(/^﻿/, '');
    for (let i = 0; i < src.length; i++) {
      const c = src[i];
      if (quoted) {
        if (c === '"' && src[i + 1] === '"') { cell += '"'; i++; } else if (c === '"') quoted = false; else cell += c;
      } else if (c === '"') quoted = true;
      else if (c === ',') { row.push(cell); cell = ''; }
      else if (c === '\n') { row.push(cell); out.push(row); row = []; cell = ''; }
      else if (c !== '\r') cell += c;
    }
    if (cell.length || row.length) { row.push(cell); out.push(row); }
    return out.filter(r => r.some(v => String(v).trim() !== ''));
  }

  const toCsv = (rows) => rows.map(r => r.map(v => /[",\n]/.test(String(v)) ? `"${String(v).replace(/"/g, '""')}"` : v).join(',')).join('\r\n');

  function load(text, fileName) {
    const grid = parseCsv(text);
    if (grid.length < 2) { UI.toast('That file has no data rows.'); return; }
    const headers = grid[0].map(h => h.trim());
    const taken = [];
    // The most specific header claims its column before a looser word can.
    const map = {};
    FIELDS.forEach(f => {
      map[f.key] = -1;
      for (const w of f.words) {
        const i = headers.findIndex((h, ix) => !taken.includes(ix) && h.toLowerCase().includes(w));
        if (i >= 0) { taken.push(i); map[f.key] = i; break; }
      }
    });
    ci = { step: 2, fileName, headers, rows: grid.slice(1), map, mode: 'update', result: [] };
    dryRun();
  }

  function sample() {
    const rows = [['Account code', 'Account name', 'Type', 'Restriction', 'Fund', 'Programme', 'Funder']];
    data.accounts.filter(a => a.level === 2).slice(0, 6).forEach(a => rows.push([a.code, a.name, a.type, a.restriction, a.fund, a.program, a.funder]));
    rows.push(['5430', 'Field per diems — county observers', 'Expense', 'Restricted', 'Grant Fund', 'Election Observation', 'USAID / Uraia']);
    rows.push(['5440', 'Observer accreditation fees', 'Expense', 'Restricted', 'Grant Fund', 'Election Observation', 'USAID / Uraia']);
    rows.push(['5450', 'Data verification and call centre', 'Expense', 'Restricted', 'Grant Fund', 'Election Observation', 'EU Delegation']);
    rows.push(['4260', 'Investment income', 'Income', 'Unrestricted', 'General Fund', 'Shared', '—']);
    rows.push(['54A0', 'Mis-coded line', 'Expense', 'Restricted', 'Grant Fund', 'Election Observation', '—']);
    rows.push(['5460', 'Missing type line', '', 'Restricted', 'Grant Fund', 'Election Observation', '—']);
    rows.push(['5430', 'Duplicate of the per diems line', 'Expense', 'Restricted', 'Grant Fund', 'Election Observation', '—']);
    load(toCsv(rows), 'sample chart import.csv');
  }

  function mappedRows() {
    const val = (r, key) => { const i = ci.map[key]; return i >= 0 && r[i] !== undefined ? String(r[i]).trim() : ''; };
    return ci.rows.map((r, i) => Object.assign({ line: i + 2 }, Object.fromEntries(FIELDS.map(f => [f.key, val(r, f.key)]))));
  }

  async function dryRun() {
    try {
      const res = await UI.postJSON('/api/coa/import', { rows: mappedRows(), mode: ci.mode });
      ci.result = res.rows;
    } catch (err) {
      UI.toast(err.message);
    }
    renderImport();
  }

  /** A chart with nothing in it yet: the one place a template is most of the answer. */
  function emptyChart() {
    return `
      <div class="coa-empty">
        Nothing in the chart yet. Nothing can be posted until there is at least one postable account.
        <div style="margin-top:14px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">
          <button type="button" class="btn btn-primary" id="coa-template-first">Start from a template</button>
        </div>
        <div style="margin-top:10px;font-size:11.5px;">Or import a CSV, or add accounts one at a time. A template can be cloned later too — it fills the gaps and leaves what is already there.</div>
      </div>`;
  }

  let ct = { step: 1, templates: null, key: null, plan: null, edits: {}, busy: false };

  async function openTemplates() {
    ct = { step: 1, templates: ct.templates, key: null, plan: null, edits: {}, busy: false };
    renderTemplates();
    if (!ct.templates) {
      try {
        const res = await UI.fetchJSON('/api/coa/templates');
        ct.templates = res.templates;
        ct.canManage = res.canManage;
      } catch (err) {
        UI.toast(err.message);
        return;
      }
    }
    renderTemplates();
  }

  async function chooseTemplate(key) {
    ct.key = key;
    ct.plan = null;
    ct.edits = {};
    ct.step = 2;
    renderTemplates();
    try {
      ct.plan = (await UI.fetchJSON('/api/coa/templates?template=' + encodeURIComponent(key))).plan;
    } catch (err) {
      UI.toast(err.message);
      ct.step = 1;
    }
    renderTemplates();
  }

  // The edits for one row, defaulting to the template's own values until touched.
  function ctEdit(code) {
    if (!ct.edits[code]) ct.edits[code] = {};
    return ct.edits[code];
  }

  // A row is left out when it, or any heading above it, was excluded.
  function ctExcluded(code) {
    const byCode = {};
    (ct.plan.rows || []).forEach(r => { byCode[r.code] = r; });
    for (let r = byCode[code]; r; r = r.parent ? byCode[r.parent] : null) {
      if (ct.edits[r.code] && ct.edits[r.code].include === false) return true;
    }
    return false;
  }

  // Rows the chart does not already hold and that are still included — what adopting would open.
  function ctOpening() {
    return (ct.plan.rows || []).filter(r => r.state !== 'Held' && !ctExcluded(r.code));
  }

  async function adoptTemplate() {
    if (ct.busy) return;
    ct.busy = true;
    renderTemplates();
    try {
      // Only send rows the user actually changed or excluded; the rest take the template's values.
      const rows = Object.entries(ct.edits)
        .map(([code, e]) => Object.assign({ code }, e))
        .filter(r => r.name !== undefined || r.type !== undefined || r.restriction !== undefined || r.include === false);
      const res = await UI.postJSON('/api/coa/templates/adopt', { template: ct.key, rows });
      drawerEl.hidden = true;
      UI.toast(res.message);
      await reload();
    } catch (err) {
      UI.toast(err.message);
    } finally {
      ct.busy = false;
    }
  }

  const CT_TYPES = ['Asset', 'Liability', 'Equity', 'Income', 'Expense'];
  const CT_RESTRICTIONS = ['Unrestricted', 'Restricted', 'Endowment'];

  function renderTemplates() {
    const stepNote = { 1: 'Step 1 of 3 · choose a chart', 2: 'Step 2 of 3 · adjust it to fit', 3: 'Step 3 of 3 · confirm what it opens' };
    const head = `
      <div style="flex:0 0 auto;display:flex;align-items:flex-start;gap:12px;padding:16px 20px;border-bottom:1px solid #E4E2DB;">
        <div style="display:flex;flex-direction:column;gap:4px;min-width:0;">
          <span style="font-size:15px;font-weight:600;letter-spacing:-0.01em;">Start from a template</span>
          <span style="font-size:11px;color:#8B948F;">${stepNote[ct.step] || stepNote[1]}</span>
        </div>
        <button type="button" class="jd-x" data-close aria-label="Close" style="margin-inline-start:auto;">×</button>
      </div>`;

    if (ct.step === 1) {
      const panel = drawer(720, `${head}
        <div style="flex:1;overflow-y:auto;padding:20px;display:flex;flex-direction:column;gap:14px;">
          <p style="margin:0;font-size:12.5px;color:#5C665F;text-wrap:pretty;">A starting point, not a decision. Choose a chart, adjust it to fit — rename an account, change its type, or leave one out — then adopt what remains. Accounts you already have are left exactly as they are, and every account opens at zero; only journals move a balance.</p>
          ${ct.templates ? ct.templates.map(t => `
            <button type="button" class="ct-card" data-template="${esc(t.key)}">
              <span class="ct-name">${esc(t.name)}</span>
              <span class="ct-count">${t.accounts} accounts · ${t.postable} postable</span>
              <span class="ct-note">${esc(t.note)}</span>
            </button>`).join('')
          : '<div class="coa-empty">Loading…</div>'}
        </div>`);
      panel.querySelectorAll('[data-template]').forEach(b => b.addEventListener('click', () => chooseTemplate(b.dataset.template)));
      return;
    }

    const p = ct.plan;
    if (!p) {
      drawer(860, `${head}<div style="flex:1;padding:20px;"><div class="coa-empty">Reading the template…</div></div>`);
      return;
    }

    if (ct.step === 2) return renderAdjust(head, p);
    return renderConfirm(head, p);
  }

  // Step 2 — the template as an editable list. Codes are fixed; name, type,
  // restriction and whether to include each account are the organisation's.
  function renderAdjust(head, p) {
    const editable = p.rows.filter(r => r.state !== 'Held');
    const opening = ctOpening().length;

    const rowHtml = (r) => {
      const held = r.state === 'Held';
      const e = ct.edits[r.code] || {};
      const excluded = !held && ctExcluded(r.code);
      const name = e.name !== undefined ? e.name : r.name;
      const type = e.type !== undefined ? e.type : r.type;
      const restriction = e.restriction !== undefined ? (e.restriction || 'Unrestricted') : (r.restriction || 'Unrestricted');
      if (held) {
        return `<div class="ct-row held" style="padding-inline-start:${r.level * 16}px;">
            <span class="mono">${esc(r.code)}</span>
            <span>${esc(r.name)} <em>— you have ${esc(r.held)}</em></span>
            <span class="ct-state">Held</span>
          </div>`;
      }
      return `<div class="ct-adjust-row${excluded ? ' excluded' : ''}" data-row="${esc(r.code)}" style="padding-inline-start:${r.level * 16}px;">
          <label class="ct-inc"><input type="checkbox" data-inc="${esc(r.code)}" ${excluded ? '' : 'checked'}></label>
          <span class="mono">${esc(r.code)}</span>
          <input type="text" class="ct-name-in" data-name="${esc(r.code)}" value="${esc(name)}" ${excluded ? 'disabled' : ''} maxlength="120">
          <select class="ct-type-in" data-type="${esc(r.code)}" ${excluded ? 'disabled' : ''}>
            ${CT_TYPES.map(t => `<option value="${t}" ${t === type ? 'selected' : ''}>${t}</option>`).join('')}
          </select>
          <select class="ct-rest-in" data-rest="${esc(r.code)}" ${excluded ? 'disabled' : ''}>
            ${CT_RESTRICTIONS.map(x => `<option value="${x}" ${x === restriction ? 'selected' : ''}>${x}</option>`).join('')}
          </select>
        </div>`;
    };

    const panel = drawer(860, `${head}
      <div style="flex:0 0 auto;padding:12px 20px;border-bottom:1px solid #EEEDE8;display:flex;gap:16px;flex-wrap:wrap;align-items:center;font-size:12px;color:#5C665F;">
        <span><b style="color:#16211E;" id="ct-open-count">${opening}</b> to open</span>
        ${p.existing ? `<span><b style="color:#16211E;">${p.existing}</b> already held — left as they are</span>` : ''}
        <span style="margin-inline-start:auto;font-size:11px;color:#8B948F;">Rename, retype, or untick to leave out. Codes stay as they are.</span>
      </div>
      <div style="flex:0 0 auto;display:grid;grid-template-columns:34px 54px minmax(0,1fr) 116px 128px;gap:10px;padding:8px 20px;border-bottom:1px solid #EEEDE8;">
        ${['', 'Code', 'Account', 'Type', 'Restriction'].map(l => `<span class="jd-caps" style="font-size:10.5px;">${l}</span>`).join('')}
      </div>
      <div style="flex:1;overflow-y:auto;padding:4px 20px 20px;" id="ct-adjust-list">
        ${p.rows.map(rowHtml).join('')}
      </div>
      <div style="flex:0 0 auto;padding:14px 20px;border-top:1px solid #E4E2DB;display:flex;gap:8px;justify-content:flex-end;align-items:center;">
        <button type="button" class="btn" id="ct-back" style="margin-inline-end:auto;">Back</button>
        <button type="button" class="btn btn-primary" id="ct-next" ${opening ? '' : 'disabled'}>Review ${opening} ${opening === 1 ? 'account' : 'accounts'}</button>
      </div>`);

    const refreshCount = () => {
      const n = ctOpening().length;
      panel.querySelector('#ct-open-count').textContent = n;
      const next = panel.querySelector('#ct-next');
      next.disabled = !n;
      next.textContent = `Review ${n} ${n === 1 ? 'account' : 'accounts'}`;
    };

    panel.querySelectorAll('[data-name]').forEach(i => i.addEventListener('input', () => {
      const v = i.value.trim();
      if (v === '' || v === p.rows.find(r => r.code === i.dataset.name).name) delete ctEdit(i.dataset.name).name;
      else ctEdit(i.dataset.name).name = v;
    }));
    panel.querySelectorAll('[data-type]').forEach(s => s.addEventListener('change', () => { ctEdit(s.dataset.type).type = s.value; }));
    panel.querySelectorAll('[data-rest]').forEach(s => s.addEventListener('change', () => { ctEdit(s.dataset.rest).restriction = s.value; }));
    panel.querySelectorAll('[data-inc]').forEach(c => c.addEventListener('change', () => {
      ctEdit(c.dataset.inc).include = c.checked;
      // A heading toggled off greys out everything beneath it; re-render the list to reflect that.
      const list = panel.querySelector('#ct-adjust-list');
      list.innerHTML = p.rows.map(rowHtml).join('');
      rebind();
      refreshCount();
    }));

    function rebind() {
      panel.querySelectorAll('[data-name]').forEach(i => i.addEventListener('input', () => {
        const v = i.value.trim();
        if (v === '' || v === p.rows.find(r => r.code === i.dataset.name).name) delete ctEdit(i.dataset.name).name;
        else ctEdit(i.dataset.name).name = v;
      }));
      panel.querySelectorAll('[data-type]').forEach(s => s.addEventListener('change', () => { ctEdit(s.dataset.type).type = s.value; }));
      panel.querySelectorAll('[data-rest]').forEach(s => s.addEventListener('change', () => { ctEdit(s.dataset.rest).restriction = s.value; }));
      panel.querySelectorAll('[data-inc]').forEach(c => c.addEventListener('change', () => {
        ctEdit(c.dataset.inc).include = c.checked;
        panel.querySelector('#ct-adjust-list').innerHTML = p.rows.map(rowHtml).join('');
        rebind();
        refreshCount();
      }));
    }

    panel.querySelector('#ct-back').addEventListener('click', openTemplates);
    panel.querySelector('#ct-next').addEventListener('click', () => {
      if (!ctOpening().length) return;
      ct.step = 3;
      renderTemplates();
    });
  }

  // Step 3 — the adjusted chart exactly as it would be opened, and the adopt button.
  function renderConfirm(head, p) {
    const opening = ctOpening();
    const shown = opening.map(r => {
      const e = ct.edits[r.code] || {};
      return {
        code: r.code, level: r.level,
        name: e.name !== undefined ? e.name : r.name,
        type: e.type !== undefined ? e.type : r.type,
        restriction: e.restriction !== undefined ? (e.restriction === 'Unrestricted' ? '' : e.restriction) : (r.restriction || ''),
        changed: e.name !== undefined || e.type !== undefined || e.restriction !== undefined,
      };
    });
    const renamed = shown.filter(r => r.changed).length;
    const leftOut = p.rows.filter(r => r.state !== 'Held').length - opening.length;

    const panel = drawer(820, `${head}
      <div style="flex:0 0 auto;padding:14px 20px;border-bottom:1px solid #EEEDE8;display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:#5C665F;">
        <span><b style="color:#16211E;">${shown.length}</b> to open</span>
        ${renamed ? `<span><b style="color:#16211E;">${renamed}</b> adjusted</span>` : ''}
        ${leftOut ? `<span><b style="color:#16211E;">${leftOut}</b> left out</span>` : ''}
        ${p.existing ? `<span><b style="color:#16211E;">${p.existing}</b> already held</span>` : ''}
      </div>
      <div style="flex:1;overflow-y:auto;padding:0 20px 20px;">
        <div class="ct-rows">
          ${shown.map(r => `
            <div class="ct-row" style="padding-inline-start:${r.level * 16}px;">
              <span class="mono">${esc(r.code)}</span>
              <span>${esc(r.name)}${r.restriction ? ` <em>· ${esc(r.restriction)}</em>` : ''}${r.changed ? ' <em>· adjusted</em>' : ''}</span>
              <span class="ct-state">${esc(r.type)}</span>
            </div>`).join('')}
        </div>
      </div>
      <div style="flex:0 0 auto;padding:14px 20px;border-top:1px solid #E4E2DB;display:flex;gap:8px;justify-content:flex-end;">
        <button type="button" class="btn" id="ct-back" style="margin-inline-end:auto;">Back to adjust</button>
        <button type="button" class="btn btn-primary" id="ct-go" ${ct.busy || !shown.length ? 'disabled' : ''}>${ct.busy ? 'Opening…' : `Adopt ${shown.length} ${shown.length === 1 ? 'account' : 'accounts'}`}</button>
      </div>`);

    panel.querySelector('#ct-back').addEventListener('click', () => { ct.step = 2; renderTemplates(); });
    panel.querySelector('#ct-go').addEventListener('click', adoptTemplate);
  }

  function openImport() {
    ci = { step: 1 };
    renderImport();
  }

  function renderImport() {
    const rules = [
      'Balances are never imported — accounts open at zero and only journals move them.',
      'An account that already carries postings cannot change its type or code, only its descriptive fields.',
      'Every code must sit under a parent that already exists in the chart.',
    ];
    const head = `
      <div style="flex:0 0 auto;display:flex;align-items:flex-start;gap:12px;padding:16px 20px;border-bottom:1px solid #E4E2DB;">
        <div style="display:flex;flex-direction:column;gap:4px;min-width:0;">
          <span style="font-size:15px;font-weight:600;letter-spacing:-0.01em;">Import chart of accounts</span>
          <span style="font-size:11px;color:#8B948F;">${ci.step === 1 ? 'Step 1 of 2 · choose a file' : 'Step 2 of 2 · check the dry run'}</span>
        </div>
        <button type="button" class="jd-x" data-close aria-label="Close" style="margin-inline-start:auto;">×</button>
      </div>`;

    if (ci.step === 1) {
      const panel = drawer(720, `${head}
        <div style="flex:1;overflow-y:auto;padding:20px;display:flex;flex-direction:column;gap:16px;">
          <p style="margin:0;font-size:12.5px;color:#5C665F;text-wrap:pretty;">Choose a CSV of accounts. Nothing changes until you have seen the dry run and confirmed it — the file is read, checked row by row and summarised first.</p>
          <label class="coa-drop">
            <span style="font-size:13px;font-weight:600;color:#16211E;">Choose a CSV file</span>
            <span style="font-size:11.5px;color:#7A857F;">Code, name and type are required · restriction, fund, programme and funder optional</span>
            <input type="file" accept=".csv,text/csv" id="ci-file" hidden>
          </label>
          <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:11.5px;color:#7A857F;">No file to hand?</span>
            <button type="button" class="btn" id="ci-sample">Load a sample file</button>
          </div>
          <div style="border-top:1px solid #EEEDE8;padding-top:14px;display:flex;flex-direction:column;gap:7px;">
            <span class="jd-caps" style="font-size:11px;">Rules the import enforces</span>
            ${rules.map(r => `<span style="font-size:11.5px;color:#5C665F;text-wrap:pretty;">— ${esc(r)}</span>`).join('')}
          </div>
        </div>`);
      panel.querySelector('#ci-file').addEventListener('change', (e) => {
        const file = e.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = () => load(String(reader.result || ''), file.name);
        reader.readAsText(file);
      });
      panel.querySelector('#ci-sample').addEventListener('click', sample);
      return;
    }

    const rows = ci.result;
    const count = (s) => rows.filter(r => r.state === s).length;
    const adds = count('New'), edits = count('Update'), rejects = count('Rejected'), skips = count('Skipped');
    const importable = adds + edits;
    const pill = { New: 'settled', Update: 'waiting', Rejected: 'blocking', Skipped: 'skipped' };

    const panel = drawer(720, `${head}
      <div style="flex:1;min-height:0;overflow-y:auto;">
        <div style="padding:14px 20px;border-bottom:1px solid #EEEDE8;display:flex;align-items:center;gap:12px;">
          <span style="font-size:12px;font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(ci.fileName)}</span>
          <button type="button" class="coa-link" id="ci-back" style="margin-inline-start:auto;">Choose another file</button>
        </div>
        <div style="padding:14px 20px;border-bottom:1px solid #EEEDE8;display:flex;flex-direction:column;gap:9px;">
          <span class="jd-caps" style="font-size:11px;">Column mapping</span>
          <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;">
            ${FIELDS.map(f => field(f.label, `<select data-map="${f.key}">${options([[-1, 'Not mapped']].concat(ci.headers.map((h, i) => [i, h])).map(([v, l]) => [String(v), l]), String(ci.map[f.key]))}</select>`)).join('')}
          </div>
        </div>
        <div style="padding:12px 20px;border-bottom:1px solid #EEEDE8;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
          <span style="font-size:11.5px;color:#5C665F;">${edits + skips ? `${edits + skips} codes already exist in the chart` : 'No code in this file already exists'}</span>
          <div style="margin-inline-start:auto;">${segmented([{ value: 'update', label: 'Update them' }, { value: 'skip', label: 'Skip them' }], i => i.value === ci.mode, 'data-mode')}</div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1px;background:#EEEDE8;border-bottom:1px solid #EEEDE8;">
          ${[['Rows read', rows.length], ['New accounts', adds], ['Updates', edits], ['Rejected', rejects]].map(([l, v]) => `
            <div style="background:#FFF;padding:11px 16px;display:flex;flex-direction:column;gap:3px;">
              <span class="jd-caps">${l}</span><span class="pc-mono" style="font-size:16px;font-weight:600;">${v}</span>
            </div>`).join('')}
        </div>
        <div style="padding:10px 20px;border-bottom:1px solid #EEEDE8;display:flex;align-items:center;gap:12px;">
          <span style="font-size:11.5px;color:#5C665F;">${rejects ? `${rejects} ${rejects === 1 ? 'row is rejected and will not be imported' : 'rows are rejected and will not be imported'}` : 'Every row passed validation'}</span>
          ${rejects ? '<button type="button" class="coa-link" id="ci-rejects" style="margin-inline-start:auto;">Download rejects →</button>' : ''}
        </div>
        ${rows.slice(0, 60).map(r => `
          <div style="display:grid;grid-template-columns:38px 62px minmax(0,1fr) 92px 84px;align-items:start;gap:10px;padding:9px 20px;border-bottom:1px solid #F2F1EC;">
            <span class="pc-mono" style="font-size:11px;color:#9AA39E;">${r.line}</span>
            <span class="pc-mono" style="font-size:11.5px;color:#28352F;">${esc(r.code || '—')}</span>
            <div style="display:flex;flex-direction:column;gap:2px;min-width:0;">
              <span style="font-size:12px;color:#16211E;">${esc(r.name || '—')}</span>
              ${r.reason ? `<span style="font-size:11px;color:#A5442F;text-wrap:pretty;">${esc(r.reason)}</span>` : ''}
            </div>
            <span style="font-size:11.5px;color:#7A857F;">${esc(r.type || '—')}</span>
            <div style="display:flex;justify-content:flex-end;"><span class="pc-pill ${pill[r.state]}">${esc(r.state)}</span></div>
          </div>`).join('')}
        ${rows.length > 60 ? `<div style="padding:10px 20px;font-size:11.5px;color:#8B948F;">Showing the first 60 of ${rows.length} rows</div>` : ''}
      </div>
      <div style="flex:0 0 auto;border-top:1px solid #E4E2DB;padding:13px 20px;background:#FAF9F6;display:flex;align-items:center;gap:12px;">
        <span style="font-size:11px;color:#7A857F;min-width:0;">Imported accounts open at zero — only journals move a balance.</span>
        <div style="margin-inline-start:auto;display:flex;align-items:center;gap:8px;flex:0 0 auto;">
          <button type="button" class="btn" data-close>Cancel</button>
          <button type="button" class="${importable && data.canManage ? 'btn btn-primary' : 'pc-close-blocked'}" id="ci-commit">${rejects === rows.length && rows.length ? 'Nothing to import' : `Import ${importable} ${importable === 1 ? 'account' : 'accounts'}`}</button>
        </div>
      </div>`);

    panel.querySelector('#ci-back').addEventListener('click', openImport);
    panel.querySelectorAll('[data-map]').forEach(s => s.addEventListener('change', () => { ci.map[s.dataset.map] = parseInt(s.value, 10); dryRun(); }));
    panel.querySelectorAll('[data-mode]').forEach(b => b.addEventListener('click', () => { ci.mode = b.dataset.mode; dryRun(); }));
    const rejectsBtn = panel.querySelector('#ci-rejects');
    if (rejectsBtn) rejectsBtn.addEventListener('click', () => {
      const csv = toCsv([['Line', 'Code', 'Name', 'Type', 'Reason']].concat(rows.filter(r => r.state === 'Rejected').map(r => [r.line, r.code, r.name, r.type, r.reason])));
      UI.download(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }), `${UI.brand()} import rejects.csv`);
    });
    panel.querySelector('#ci-commit').addEventListener('click', async () => {
      if (!importable) { UI.toast('Nothing in this file can be imported — every row was rejected.'); return; }
      if (!data.canManage) { UI.toast('Only the Finance Manager can change the chart of accounts.'); return; }
      try {
        const res = await UI.postJSON('/api/coa/import', { rows: mappedRows(), mode: ci.mode, fileName: ci.fileName, commit: true });
        drawerEl.hidden = true;
        UI.toast(res.message);
        reload();
      } catch (err) {
        UI.toast(err.message);
      }
    });
  }

  try {
    data = await UI.fetchJSON('/api/coa');
    render();
  } catch (err) {
    app.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`;
  }
})();
