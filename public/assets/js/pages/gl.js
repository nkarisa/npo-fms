/**
 * General ledger (v5): one account's postings for a period, filtered by fund,
 * programme and award, ten rows a page, with the entry drawer. Figures come from
 * /api/gl.
 */
(async function () {
  const app = document.getElementById('app');
  const esc = UI.esc;
  const PAGE = 10;
  const params = new URLSearchParams(window.location.search);
  const state = {
    account: params.get('account') || '', period: params.get('period') || '', fund: 'All funds', program: 'All programmes', grant: params.get('grant') || 'All awards', q: '', page: 0,
  };
  let data = null;

  const query = () => new URLSearchParams({
    account: state.account, period: state.period, fund: state.fund, program: state.program, grant: state.grant, q: state.q,
  });

  async function refresh() {
    try {
      data = await UI.fetchJSON('/api/gl?' + query().toString());
    } catch (err) {
      app.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`;
      return;
    }
    state.account = data.account.code;
    Object.assign(state, { period: data.filters.period, fund: data.filters.fund, program: data.filters.program, grant: data.filters.grant });
    history.replaceState(null, '', '/gl?account=' + encodeURIComponent(state.account));
    render();
  }

  const select = (id, options, current, extra) => `<select id="${id}" ${extra || ''}>${options.map(o => {
    const [value, label] = typeof o === 'string' ? [o, o] : [o.code, o.label];
    return `<option value="${esc(value)}" ${value === current ? 'selected' : ''}>${esc(label)}</option>`;
  }).join('')}</select>`;

  function render() {
    const rows = data.rows;
    const pages = Math.max(1, Math.ceil(rows.length / PAGE));
    state.page = Math.min(state.page, pages - 1);
    const shown = rows.slice(state.page * PAGE, state.page * PAGE + PAGE);
    const o = data.options;

    app.innerHTML = `
      <div class="page-head">
        <div>
          <h1 class="page-title" style="margin-top:0;">General ledger</h1>
          <p class="page-blurb" style="max-width:640px;">Posted movements for a single account, in code order from the chart of accounts. Every line carries its fund, programme and grant segments so donor reports reconcile to the ledger.</p>
        </div>
        <div class="page-actions" style="margin-left:0;margin-inline-start:auto;">
          <a class="btn" href="/coa?q=${encodeURIComponent(data.account.code)}">Open in chart of accounts</a>
          <button type="button" class="btn" id="gl-export">Export</button>
          <button type="button" class="btn btn-primary" id="gl-post">+ Post journal</button>
        </div>
      </div>

      <div class="gl-card">
        <div class="gl-filters">
          <label class="jd-field gl-account">Account${select('gl-account', o.accounts, state.account)}</label>
          <label class="jd-field">Period${select('gl-period', o.periods, state.period)}</label>
          <label class="jd-field">Fund${select('gl-fund', o.funds, state.fund)}</label>
          <label class="jd-field">Programme${select('gl-program', o.programs, state.program)}</label>
          <label class="jd-field">Grant / award${select('gl-grant', o.grants, state.grant)}</label>
          <label class="coa-search gl-search"><span>⌕</span><input id="gl-q" value="${esc(state.q)}" placeholder="Reference or narration"></label>
        </div>
        <div class="gl-summary">
          ${data.summary.map(s => `
            <div>
              <div class="jd-caps">${esc(s.label)}</div>
              <div class="pc-mono" style="font-size:17px;font-weight:600;letter-spacing:-0.01em;">${esc(s.value)}</div>
              <div style="font-size:11px;color:#7A857F;">${esc(s.note)}</div>
            </div>`).join('')}
        </div>
      </div>

      <div class="coa-card" style="margin-top:18px;">
        <div style="overflow:auto;">
          <div style="min-width:1420px;">
            <div class="gl-grid coa-head">
              <div>Date</div><div>Reference</div><div>Narration</div><div>Source</div><div>Fund</div><div>Programme</div><div>Grant</div>
              <div style="text-align:end;">Debit</div><div style="text-align:end;">Credit</div><div style="text-align:end;">Balance</div>
            </div>
            <div class="gl-grid gl-row gl-opening">
              <div class="gl-date">${esc(data.openingDate)}</div><div style="color:#8B948F;font-size:11.5px;">—</div>
              <div style="font-size:12.5px;font-weight:600;color:#16211E;">Opening balance brought forward</div>
              <div></div><div></div><div></div><div></div><div></div><div></div>
              <div class="coa-amount" style="font-weight:600;color:#16211E;">${esc(data.opening)}</div>
            </div>
            ${shown.map((r, i) => `
              <div class="gl-grid gl-row" data-i="${state.page * PAGE + i}" tabindex="0">
                <div class="gl-date">${esc(r.date)}</div>
                <div class="gl-ref">${esc(r.ref)}</div>
                <div class="coa-name">${esc(r.narration)}</div>
                <div class="coa-muted">${esc(r.source)}</div>
                <div class="coa-cell">${esc(r.fund)}</div>
                <div class="coa-cell">${esc(r.program)}</div>
                <div style="display:flex;align-items:center;gap:5px;min-width:0;">
                  <span class="coa-cell">${esc(r.grant)}</span>
                  ${r.grantUnassigned ? '<span class="gl-flag" title="Restricted posting with no award attributed">!</span>' : ''}
                </div>
                <div class="coa-amount" style="color:#28352F;">${esc(r.debit)}</div>
                <div class="coa-amount" style="color:#28352F;">${esc(r.credit)}</div>
                <div class="coa-amount" style="color:#5C665F;">${esc(r.balance)}</div>
              </div>`).join('')}
            ${rows.length === 0 ? '<div class="coa-empty">No postings match the current filters.</div>' : ''}
            <div class="gl-grid gl-closing">
              <div></div><div></div>
              <div style="font-size:12.5px;font-weight:600;">Closing balance — ${esc(state.period)}</div>
              <div></div><div></div><div></div><div></div>
              <div class="coa-amount" style="font-weight:600;color:#16211E;">${esc(data.totalDebit)}</div>
              <div class="coa-amount" style="font-weight:600;color:#16211E;">${esc(data.totalCredit)}</div>
              <div class="coa-amount" style="font-size:13px;font-weight:700;color:var(--accent);">${esc(data.closing)}</div>
            </div>
          </div>
        </div>
        ${rows.length > PAGE ? pager(rows.length, pages) : ''}
        ${data.awards.length ? `
          <div class="gl-awards">
            <span class="jd-caps">By award</span>
            ${data.awards.map(a => `<span class="gl-award">${esc(a.grant)}<span class="pc-mono" style="color:var(--accent);">${esc(a.value)}</span></span>`).join('')}
          </div>` : ''}
        <div class="coa-foot">
          <span>${esc(data.footer)}</span>
          <span style="margin-inline-start:auto;">Balances stated in KES · ${esc(data.account.normal)} normal balance</span>
        </div>
      </div>`;

    bind();
  }

  function pager(total, pages) {
    const p = state.page;
    const from = pages > 9 ? Math.min(Math.max(p - 4, 0), pages - 9) : 0;
    const to = pages > 9 ? from + 9 : pages;
    const buttons = [];
    for (let k = from; k < to; k++) buttons.push(`<button type="button" class="coa-page ${k === p ? 'on' : ''}" data-page="${k}">${k + 1}</button>`);
    return `
      <div class="coa-pager">
        <span>Showing ${p * PAGE + 1}–${Math.min(total, (p + 1) * PAGE)} of ${total} postings</span>
        <div style="margin-inline-start:auto;display:flex;align-items:center;gap:4px;">
          <button type="button" class="coa-page" data-page="${Math.max(0, p - 1)}" ${p === 0 ? 'disabled' : ''}>‹</button>
          ${buttons.join('')}
          <button type="button" class="coa-page" data-page="${Math.min(pages - 1, p + 1)}" ${p >= pages - 1 ? 'disabled' : ''}>›</button>
        </div>
      </div>`;
  }

  let searchTimer;

  function bind() {
    const on = (id, key, reset) => document.getElementById(id).addEventListener('change', (e) => {
      state[key] = e.target.value;
      // A new account starts from all of its segments.
      if (reset) Object.assign(state, { fund: 'All funds', program: 'All programmes', grant: 'All awards' });
      state.page = 0;
      refresh();
    });
    on('gl-account', 'account', true);
    on('gl-period', 'period');
    on('gl-fund', 'fund');
    on('gl-program', 'program');
    on('gl-grant', 'grant');

    const q = document.getElementById('gl-q');
    q.addEventListener('input', () => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(async () => {
        state.q = q.value;
        state.page = 0;
        const at = q.selectionStart;
        await refresh();
        const again = document.getElementById('gl-q');
        again.focus();
        again.setSelectionRange(at, at);
      }, 250);
    });

    app.querySelectorAll('[data-page]').forEach(b => b.addEventListener('click', () => { state.page = +b.dataset.page; render(); }));
    app.querySelectorAll('.gl-row[data-i]').forEach(row => {
      const open = () => openEntry(data.rows[+row.dataset.i]);
      row.addEventListener('click', open);
      row.addEventListener('keydown', (e) => { if (e.key === 'Enter') open(); });
    });

    document.getElementById('gl-export').addEventListener('click', exportCsv);
    document.getElementById('gl-post').addEventListener('click', () => {
      UI.openNewJournalDrawer({
        defaultLine: { code: data.account.code, fund: data.account.fund === 'All funds' ? undefined : data.account.fund, program: data.account.program },
        onSaved: refresh,
      });
    });
  }

  async function exportCsv() {
    try {
      const res = await fetch('/api/gl/export?' + query().toString());
      if (!res.ok) throw new Error();
      UI.download(await res.blob(), `${UI.brand()} general ledger ${data.account.code}.csv`, res.headers.get('Content-Disposition'));
      UI.toast(`${data.rows.length} postings on ${data.account.code} exported to CSV.`);
    } catch (err) {
      UI.toast('The ledger could not be exported.');
    }
  }

  // ---- Entry drawer ----

  let drawerEl;

  async function openEntry(r) {
    if (!drawerEl) {
      drawerEl = document.createElement('div');
      drawerEl.className = 'pk';
      document.body.appendChild(drawerEl);
      drawerEl.addEventListener('click', (e) => { if (e.target.closest('[data-close]')) drawerEl.hidden = true; });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape') drawerEl.hidden = true; });
    }
    let entry = { lines: [], total: '—' };
    try {
      entry = await UI.fetchJSON('/api/gl/entry/' + encodeURIComponent(r.ref));
    } catch (err) {
      UI.toast('The entry could not be loaded.');
    }
    const detail = (label, value, style) => `<div style="display:flex;flex-direction:column;gap:3px;"><span class="jd-caps">${esc(label)}</span><span style="font-size:12.5px;${style || ''}">${value}</span></div>`;
    const segment = (label, value) => `<div style="display:flex;flex-direction:column;gap:2px;"><span style="font-size:11px;color:#7A857F;">${esc(label)}</span><span style="font-size:12.5px;">${esc(value)}</span></div>`;
    const trail = (what) => `<div style="display:flex;gap:9px;"><span class="pc-mono" style="color:#A3ABA7;font-size:11px;white-space:nowrap;">${esc(r.fullDate)}</span>${esc(what)}</div>`;

    drawerEl.innerHTML = `
      <div class="jd-backdrop" data-close></div>
      <div class="pk-panel" style="width:520px;" role="dialog" aria-modal="true" aria-label="Journal entry ${esc(r.ref)}">
        <div style="flex:0 0 auto;padding:18px 22px 14px;border-bottom:1px solid #EEEDE8;display:flex;align-items:flex-start;gap:12px;">
          <div style="display:flex;flex-direction:column;gap:3px;">
            <div class="jd-caps">Journal entry · ${esc(r.ref)}</div>
            <div style="font-size:16px;font-weight:600;letter-spacing:-0.015em;text-wrap:pretty;">${esc(r.narration)}</div>
          </div>
          <button type="button" class="jd-x" data-close aria-label="Close" style="margin-inline-start:auto;">✕</button>
        </div>
        <div style="flex:1;overflow-y:auto;padding:18px 22px 24px;display:flex;flex-direction:column;gap:18px;">
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;">
            ${detail('Posting date', esc(r.fullDate))}
            ${detail('Source', esc(r.source))}
            ${detail('Status', '● Posted', 'color:var(--calm);')}
          </div>
          <div style="display:flex;flex-direction:column;gap:9px;">
            <div class="jd-caps">Entry lines</div>
            <div style="border:1px solid #EEEDE8;border-radius:8px;overflow:hidden;">
              <div class="gl-lines" style="background:#FAF9F6;border-bottom:1px solid #EEEDE8;font-size:9.5px;letter-spacing:.08em;text-transform:uppercase;color:#8B948F;">
                <div>Code</div><div>Account</div><div style="text-align:end;">Debit</div><div style="text-align:end;">Credit</div>
              </div>
              ${entry.lines.map(l => `
                <div class="gl-lines" style="border-bottom:1px solid #F4F2EE;height:34px;">
                  <div class="pc-mono" style="font-size:11px;color:#5C665F;">${esc(l.code)}</div>
                  <div class="coa-name" style="font-size:12px;">${esc(l.name)}</div>
                  <div class="coa-amount" style="font-size:11.5px;">${esc(l.debitLabel)}</div>
                  <div class="coa-amount" style="font-size:11.5px;">${esc(l.creditLabel)}</div>
                </div>`).join('')}
              <div class="gl-lines" style="background:#FBFAF7;height:34px;">
                <div></div><div style="font-size:11.5px;font-weight:600;">Totals</div>
                <div class="coa-amount" style="font-size:11.5px;font-weight:600;">${esc(entry.total)}</div>
                <div class="coa-amount" style="font-size:11.5px;font-weight:600;">${esc(entry.total)}</div>
              </div>
            </div>
            <div style="font-size:11px;color:var(--calm);">✓ Entry is in balance</div>
          </div>
          <div style="display:flex;flex-direction:column;gap:9px;">
            <div class="jd-caps">Segments</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;border:1px solid #EEEDE8;border-radius:8px;padding:13px;background:#FBFAF7;">
              ${segment('Fund', r.fund)}${segment('Programme', r.program)}${segment('Grant / award', r.grant)}${segment('Funder', r.funder)}
            </div>
          </div>
          <div style="display:flex;flex-direction:column;gap:9px;">
            <div class="jd-caps">Audit trail</div>
            <div style="display:flex;flex-direction:column;gap:7px;font-size:12px;color:#3E4A44;">
              ${trail('Prepared by ' + r.preparer)}
              ${r.approver ? trail('Approved by ' + r.approver) : ''}
              ${r.doc ? trail('Supporting document ' + r.doc) : ''}
            </div>
          </div>
          ${r.archived ? '<div class="jd-msg idle">Held in the closed-period archive. A correction is made with a new journal in an open period.</div>' : ''}
        </div>
        <div class="jd-actions" style="padding:13px 22px;">
          ${r.archived ? '' : `<a class="btn" href="/journals/${encodeURIComponent(r.ref)}" style="border-color:#E0D7D2;color:#8A6A5C;">Reverse entry</a>`}
          <button type="button" class="btn" data-close style="margin-inline-start:auto;">Close</button>
          ${r.archived ? '' : `<a class="btn btn-primary" href="/journals/${encodeURIComponent(r.ref)}">View source document</a>`}
        </div>
      </div>`;
    drawerEl.hidden = false;
  }

  refresh();
})();
