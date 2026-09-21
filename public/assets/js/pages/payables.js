/**
 * Payables (v5): supplier bills from approval through to payment. An ageing strip,
 * the bill list (status tabs, search, fund; ten a page) with a selection that can be
 * approved, scheduled or paid together, the bill drawer, the new bill form, and
 * withholding tax remittance. /payables/<bill> opens a bill straight away. Figures
 * and rules come from /api/payables; the API applies every rule again.
 */
(async function () {
  const app = document.getElementById('app');
  const esc = UI.esc;
  const fmt = UI.fmtMoney;
  const params = new URLSearchParams(location.search);
  const state = { status: params.get('status') || 'All', age: 'All', fund: 'All funds', q: '', page: 1 };
  const selected = new Map(); // bill no → { net, wht }
  let data = null;

  const PILL = { 'Awaiting approval': 'pending', Approved: 'approved', Scheduled: 'scheduled', Paid: 'posted', Rejected: 'reversed' };
  const pill = (status) => `<span class="jr-pill ${PILL[status] || 'draft'}">${esc(status)}</span>`;
  const plural = (n, one, many) => n + ' ' + (n === 1 ? one : many);

  async function refresh() {
    const p = new URLSearchParams({ status: state.status, age: state.age, fund: state.fund, q: state.q, page: state.page });
    try {
      data = await UI.fetchJSON('/api/payables?' + p.toString());
    } catch (err) {
      app.querySelector('#ap-table').innerHTML = `<div class="coa-empty">${esc(err.message)}</div>`;
      return;
    }
    state.page = data.page;
    render();
  }

  function shell() {
    app.innerHTML = `
      <div class="page-head" style="margin-bottom:0;">
        <div>
          <h1 class="page-title" style="margin-top:0;">Payables</h1>
          <p class="page-blurb" style="max-width:640px;">Supplier bills from approval through to payment. Withholding tax is computed per invoice and held for remittance to KRA by the 20th.</p>
        </div>
        <div class="page-actions" style="margin-left:0;margin-inline-start:auto;">
          <button type="button" class="btn" id="ap-remit">Remit WHT to KRA</button>
          <button type="button" class="btn btn-primary" id="ap-new">+ New bill</button>
        </div>
      </div>
      <div class="stat-grid" id="ap-stats" style="margin:18px 0 0;"></div>
      <div class="ap-aging" id="ap-aging"></div>
      <div class="jr-filters">
        <div class="coa-seg" id="ap-tabs"></div>
        <label class="coa-search" style="flex:1 1 220px;min-width:190px;max-width:300px;width:auto;">⌕
          <input type="search" id="ap-q" placeholder="Supplier, bill number or KRA PIN">
        </label>
        <label class="ap-fund">Fund <select id="ap-fund"></select></label>
        <div class="jr-hint" id="ap-hint"></div>
      </div>
      <div class="ap-selbar" id="ap-selbar" hidden>
        <span style="font-size:12.5px;font-weight:600;" id="ap-sel-count"></span>
        <span style="font-size:12.5px;color:var(--rail-sub);" id="ap-sel-total"></span>
        <div style="margin-inline-start:auto;display:flex;flex-wrap:wrap;align-items:center;gap:8px;">
          <button type="button" class="ap-selbar-btn" data-bulk="approve">Approve</button>
          <button type="button" class="ap-selbar-btn" data-bulk="schedule">Schedule</button>
          <button type="button" class="ap-selbar-btn go" data-bulk="pay">Pay now</button>
          <button type="button" class="ap-selbar-btn quiet" data-bulk="clear">Clear</button>
        </div>
      </div>
      <div class="ap-authority" id="ap-authority" hidden>
        <span id="ap-authority-text"></span>
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;">
          <input class="jd-reason" id="ap-authority-ref" placeholder="Board minute or authority reference">
          <button type="button" class="btn" data-authority="cancel">Cancel</button>
          <button type="button" class="btn btn-primary" data-authority="go">Release payment</button>
        </div>
      </div>
      <div class="coa-card">
        <div style="overflow-x:auto;"><div style="min-width:1400px;" id="ap-table"></div></div>
        <div id="ap-pager"></div>
        <div class="coa-foot">
          <span id="ap-footer"></span>
          <span style="margin-inline-start:auto;">WHT remitted by the 20th · VAT at 16% · payments cleared through KCB and M-Pesa</span>
        </div>
      </div>`;

    let searchTimer;
    app.querySelector('#ap-q').addEventListener('input', (e) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => { state.q = e.target.value; state.page = 1; refresh(); }, 200);
    });
    app.querySelector('#ap-fund').addEventListener('change', (e) => { state.fund = e.target.value; state.page = 1; refresh(); });
    app.querySelector('#ap-tabs').addEventListener('click', (e) => {
      const tab = e.target.closest('[data-status]');
      if (!tab) return;
      state.status = tab.dataset.status;
      state.age = 'All';
      state.page = 1;
      refresh();
    });
    app.querySelector('#ap-aging').addEventListener('click', (e) => {
      const b = e.target.closest('[data-age]');
      if (!b) return;
      state.age = state.age === b.dataset.age ? 'All' : b.dataset.age;
      state.page = 1;
      refresh();
    });
    app.querySelector('#ap-pager').addEventListener('click', (e) => {
      const b = e.target.closest('[data-page]');
      if (!b || b.disabled) return;
      state.page = +b.dataset.page;
      refresh();
    });

    const table = app.querySelector('#ap-table');
    table.addEventListener('change', (e) => {
      const box = e.target.closest('[data-select]');
      if (!box) return;
      const row = data.rows.find(r => r.no === box.dataset.select);
      if (box.checked) selected.set(row.no, { net: row.net, wht: row.wht });
      else selected.delete(row.no);
      renderSelection();
    });
    table.addEventListener('click', (e) => {
      if (e.target.closest('.ap-check')) return;
      const row = e.target.closest('[data-no]');
      if (row) open(row.dataset.no);
    });
    table.addEventListener('keydown', (e) => {
      const row = e.target.closest('[data-no]');
      if (row && e.target === row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); open(row.dataset.no); }
    });

    app.querySelector('#ap-selbar').addEventListener('click', (e) => {
      const b = e.target.closest('[data-bulk]');
      if (b) bulk(b.dataset.bulk);
    });
    app.querySelector('#ap-authority').addEventListener('click', (e) => {
      const b = e.target.closest('[data-authority]');
      if (!b) return;
      if (b.dataset.authority === 'cancel') return askAuthority(null);
      const ref = app.querySelector('#ap-authority-ref').value.trim();
      if (!ref) return UI.toast('Record the board minute or authority reference first.');
      const retry = pendingAuthority;
      askAuthority(null);
      retry(ref);
    });
    app.querySelector('#ap-new').addEventListener('click', () => NewBill.open());
    app.querySelector('#ap-remit').addEventListener('click', remit);
  }

  function render() {
    app.querySelector('#ap-stats').innerHTML = data.stats.map(s => `
      <div class="stat">
        <div class="stat-label">${esc(s.label)}</div>
        <div class="stat-value">${esc(s.value)}</div>
        <div class="stat-note">${esc(s.note)}</div>
      </div>`).join('');

    app.querySelector('#ap-aging').innerHTML = data.aging.map(a => `
      <button type="button" class="ap-bucket ${a.label === state.age ? 'on' : ''}" data-age="${esc(a.label)}">
        <span class="stat-label">${esc(a.label)}</span>
        <span class="ap-bucket-value">${esc(a.value)}</span>
        <span class="stat-note">${esc(a.count)}</span>
      </button>`).join('');

    app.querySelector('#ap-tabs').innerHTML = data.tabs.map(t => `
      <button type="button" class="coa-seg-btn ${t.label === state.status ? 'on' : ''}" data-status="${esc(t.label)}">${esc(t.label)} (${t.count})</button>`).join('');
    app.querySelector('#ap-fund').innerHTML = data.fundOptions.map(f => `<option ${f === state.fund ? 'selected' : ''}>${esc(f)}</option>`).join('');
    app.querySelector('#ap-hint').textContent = data.hint;
    app.querySelector('#ap-footer').textContent = data.footer;

    app.querySelector('#ap-table').innerHTML = `
      <div class="ap-grid coa-head">
        <div></div><div>Bill</div><div>Supplier</div><div>Invoice</div><div>Due</div><div>Fund</div><div>Programme</div>
        <div style="text-align:end;">Gross</div><div style="text-align:end;">WHT</div><div style="text-align:end;">Net due</div><div>Status</div><div style="text-align:end;">Age</div>
      </div>
      ${data.rows.map(b => `
        <div class="ap-grid ap-row" data-no="${esc(b.no)}" tabindex="0">
          <div class="ap-check"><input type="checkbox" data-select="${esc(b.no)}" aria-label="Select ${esc(b.no)}" ${selected.has(b.no) ? 'checked' : ''}></div>
          <div class="jr-ref">${esc(b.no)}</div>
          <div>
            <span class="coa-cell" style="display:block;font-size:12.5px;color:#28352F;">${esc(b.supplier)}</span>
            <span class="ap-sub">${esc(b.subtitle)}</span>
          </div>
          <div class="jr-date" style="font-size:11px;color:#6E7873;">${esc(b.invDate)}</div>
          <div class="jr-date" style="font-size:11px;color:#6E7873;">${esc(b.dueDate)}</div>
          <div class="coa-cell">${esc(b.fund)}</div>
          <div class="coa-cell">${esc(b.program)}</div>
          <div class="coa-amount" style="color:#28352F;">${fmt(b.gross)}</div>
          <div class="coa-amount" style="font-size:11.5px;color:#8B948F;">${fmt(b.wht)}</div>
          <div class="coa-amount" style="font-weight:600;color:#16211E;">${fmt(b.net)}</div>
          <div>${pill(b.status)}</div>
          <div class="ap-age ${b.overdue ? 'late' : ''}">${esc(b.age)}</div>
        </div>`).join('')}
      ${data.rows.length === 0 ? '<div class="coa-empty">No bills match this view.</div>' : ''}`;

    app.querySelector('#ap-pager').innerHTML = data.pages > 1 ? pager() : '';
    renderSelection();
  }

  function renderSelection() {
    const bar = app.querySelector('#ap-selbar');
    bar.hidden = selected.size === 0;
    const all = [...selected.values()];
    app.querySelector('#ap-sel-count').textContent = plural(selected.size, 'bill', 'bills') + ' selected';
    app.querySelector('#ap-sel-total').textContent = `Net payable ${fmt(all.reduce((a, b) => a + b.net, 0))} · WHT withheld ${fmt(all.reduce((a, b) => a + b.wht, 0))}`;
  }

  function pager() {
    const p = data.page;
    const pages = data.pages;
    const buttons = [];
    for (let k = 1; k <= pages; k++) buttons.push(`<button type="button" class="coa-page ${k === p ? 'on' : ''}" data-page="${k}">${k}</button>`);
    return `
      <div class="coa-pager">
        <span>Showing ${(p - 1) * data.pageSize + 1}–${Math.min(data.filtered, p * data.pageSize)} of ${data.filtered} bills</span>
        <div style="margin-inline-start:auto;display:flex;align-items:center;gap:4px;">
          <button type="button" class="coa-page" data-page="${p - 1}" ${p <= 1 ? 'disabled' : ''}>‹</button>
          ${buttons.join('')}
          <button type="button" class="coa-page" data-page="${p + 1}" ${p >= pages ? 'disabled' : ''}>›</button>
        </div>
      </div>`;
  }

  // ---- Actions ----

  /** " · 2 skipped — BILL-0418 is approved, not awaiting approval." */
  const skippedNote = (d) => d.skipped && d.skipped.length
    ? ` ${plural(d.skipped.length, 'bill', 'bills')} skipped — ${d.skipped[0].reason}${d.skipped.length > 1 ? ' …' : ''}`
    : '';

  const messages = {
    approve: (d) => (d.done.length === 1
      ? `${d.done[0].no} approved for payment and posted as ${d.done[0].journal}.`
      : `${d.done.length} bills approved for payment and posted to the ledger.`) + skippedNote(d),
    schedule: (d) => (d.done.length === 1
      ? `${d.done[0].no} added to the ${d.run.date} payment run.`
      : `${d.done.length} bills scheduled for ${d.run.date}.`) + skippedNote(d),
    pay: (d) => d.runs.map(r => `Payment run ${r.ref} released and posted as ${r.journal} — ${fmt(r.total)} to ${plural(r.count, 'supplier', 'suppliers')} from ${r.account}, cleared from trade payables.`).join(' ') + skippedNote(d),
  };

  /**
   * A payment above the in-system approvers' reach goes to an authority outside the
   * system; the approver records that authority's reference and releases it.
   * `retry` is called with the reference; null hides the bar.
   */
  let pendingAuthority = null;
  function askAuthority(message, retry) {
    pendingAuthority = message ? retry : null;
    const bar = app.querySelector('#ap-authority');
    bar.hidden = !message;
    if (!message) return;
    app.querySelector('#ap-authority-text').textContent = message;
    app.querySelector('#ap-authority-ref').value = '';
    app.querySelector('#ap-authority-ref').focus();
  }

  async function bulk(action, authorityRef) {
    if (action === 'clear') {
      selected.clear();
      askAuthority(null);
      render();
      return;
    }
    const buttons = app.querySelectorAll('#ap-selbar button');
    buttons.forEach(b => { b.disabled = true; });
    try {
      const d = await UI.postJSON('/api/payables/' + action, { nos: [...selected.keys()], authorityRef: authorityRef || '' });
      selected.clear();
      UI.toast(messages[action](d));
      await refresh();
    } catch (err) {
      if (err.data && err.data.needsAuthority) askAuthority(err.message, (ref) => bulk(action, ref));
      else UI.toast(err.message);
    } finally {
      buttons.forEach(b => { b.disabled = false; });
    }
  }

  async function remit(authorityRef) {
    const button = app.querySelector('#ap-remit');
    button.disabled = true;
    try {
      const d = await UI.postJSON('/api/payables/wht-remittance', { authorityRef: typeof authorityRef === 'string' ? authorityRef : '' });
      const r = d.remittance;
      UI.toast(`WHT remittance of ${fmt(r.amount)} posted as ${r.journal} — 2240 cleared for ${plural(r.bills, 'bill', 'bills')} and paid to KRA iTax.`);
      await refresh();
    } catch (err) {
      if (err.data && err.data.needsAuthority) askAuthority(err.message, remit);
      else UI.toast(err.message);
    } finally {
      button.disabled = false;
    }
  }

  /** Opens a bill in the drawer, keeping its address in the location bar while it is open. */
  function open(no) {
    history.replaceState(null, '', '/payables/' + encodeURIComponent(no));
    Bill.open(no);
  }

  // ---- Bill drawer ----

  const Bill = (() => {
    let el;
    let current = null; // { bill, can, methodOptions }
    let rejecting = false;
    let authorising = false; // asking for the authority reference before releasing payment

    function build() {
      el = document.createElement('div');
      el.className = 'rt';
      el.hidden = true;
      el.innerHTML = `
        <div class="jd-backdrop" data-ap-close></div>
        <div class="rt-panel ap-panel" role="dialog" aria-modal="true" aria-label="Supplier bill">
          <div class="jd-head" id="apd-head"></div>
          <div class="rt-body ap-body" id="apd-body"></div>
          <div class="jd-actions" id="apd-foot"></div>
        </div>`;
      document.body.appendChild(el);

      el.addEventListener('click', (e) => {
        if (e.target.closest('[data-ap-close]')) return close();
        const journal = e.target.closest('[data-journal]');
        if (journal) return UI.openJournal(journal.dataset.journal);
        const act = e.target.closest('[data-act]');
        if (!act) return;
        const no = current.bill.no;
        switch (act.dataset.act) {
          case 'reject':
            rejecting = true;
            renderFoot();
            el.querySelector('#apd-reason').focus();
            return;
          case 'cancel-reject':
            rejecting = false;
            return renderFoot();
          case 'confirm-reject': {
            const reason = el.querySelector('#apd-reason').value.trim();
            if (!reason) return UI.toast('Say why the bill is rejected — the preparer needs to know what to correct.');
            return run(() => UI.postJSON(`/api/payables/${encodeURIComponent(no)}/reject`, { reason }), () => `${no} rejected and returned to ${current.bill.preparer}.`, true);
          }
          case 'approve':
            return run(() => UI.postJSON('/api/payables/approve', { nos: [no] }), messages.approve);
          case 'schedule':
            return run(() => UI.postJSON('/api/payables/schedule', { nos: [no] }), messages.schedule);
          case 'pay':
            if (current.can.payNeedsAuthority) {
              authorising = true;
              renderFoot();
              el.querySelector('#apd-authority').focus();
              return;
            }
            return run(() => UI.postJSON('/api/payables/pay', { nos: [no] }), messages.pay, true);
          case 'cancel-authority':
            authorising = false;
            return renderFoot();
          case 'confirm-pay': {
            const authorityRef = el.querySelector('#apd-authority').value.trim();
            if (!authorityRef) return UI.toast('Record the board minute or authority reference first.');
            return run(() => UI.postJSON('/api/payables/pay', { nos: [no], authorityRef }), messages.pay, true);
          }
        }
      });
      el.addEventListener('change', async (e) => {
        if (e.target.id !== 'apd-method') return;
        const no = current.bill.no;
        try {
          await UI.postJSON(`/api/payables/${encodeURIComponent(no)}/method`, { method: e.target.value });
          UI.toast(`${no} will be paid by ${e.target.value}.`);
          await load(no);
          refresh();
        } catch (err) {
          UI.toast(err.message);
          e.target.value = current.bill.method;
        }
      });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !el.hidden && !document.querySelector('.jd:not([hidden])')) close(); });
    }

    async function load(no) {
      current = await UI.fetchJSON('/api/payables/' + encodeURIComponent(no));
      rejecting = false;
      authorising = false;
      render();
    }

    /** Runs an action, then reloads the drawer (or closes it) and the list. */
    async function run(request, message, closeAfter) {
      const buttons = el.querySelectorAll('#apd-foot button, #apd-body select');
      buttons.forEach(b => { b.disabled = true; });
      try {
        const d = await request();
        UI.toast(message(d));
        if (closeAfter) close();
        else await load(current.bill.no);
        refresh();
      } catch (err) {
        UI.toast(err.message);
        authorising = false;
        render();
      }
    }

    function render() {
      const b = current.bill;
      el.querySelector('#apd-head').innerHTML = `
        <div style="display:flex;flex-direction:column;gap:5px;min-width:0;">
          <div style="display:flex;align-items:center;gap:9px;">
            <span class="jd-caps" style="white-space:nowrap;">${esc(b.no)}</span>${pill(b.status)}
          </div>
          <div style="font-size:17px;font-weight:600;letter-spacing:-.015em;">${esc(b.supplier)}</div>
          <div style="font-size:11.5px;color:#7A857F;">KRA PIN ${esc(b.pin)} · ${esc(b.category)}${b.invoiceNo ? ' · invoice ' + esc(b.invoiceNo) : ''}</div>
        </div>
        <button type="button" class="jd-x" data-ap-close aria-label="Close" style="margin-inline-start:auto;">✕</button>`;

      const field = (label, value) => `<div style="display:flex;flex-direction:column;gap:3px;"><span class="jd-caps">${esc(label)}</span><span style="font-size:12.5px;">${esc(value)}</span></div>`;
      const small = (label, value) => `<div style="display:flex;flex-direction:column;gap:3px;"><span style="font-size:11px;color:#7A857F;">${esc(label)}</span><span style="font-size:12.5px;">${value}</span></div>`;
      const ledger = [
        b.journal ? `Cost posted as <button type="button" class="ap-link" data-journal="${esc(b.journal)}">${esc(b.journal)}</button>` : '',
        b.run ? `${b.status === 'Paid' ? 'Paid in' : 'In'} payment run <span class="pc-mono">${esc(b.run.ref)}</span> · ${esc(b.run.date)}` : '',
        b.whtRemittance ? `WHT remitted to KRA in <span class="pc-mono">${esc(b.whtRemittance)}</span>` : '',
      ].filter(Boolean);

      el.querySelector('#apd-body').innerHTML = `
        ${b.overdue ? `<div class="ap-late">Overdue by ${esc(b.overdueBy)}. Supplier payment terms are ${esc(b.terms)}.</div>` : ''}
        ${b.status === 'Rejected' && b.rejectedReason ? `<div class="ap-reject-note">Rejected — ${esc(b.rejectedReason)}</div>` : ''}
        <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;">
          ${field('Invoice date', b.invFull)}${field('Due date', b.dueFull)}${field('Terms', b.terms)}
        </div>
        <div style="display:flex;flex-direction:column;gap:9px;">
          <div class="jd-caps">Coding</div>
          <div style="border:1px solid #EEEDE8;border-radius:8px;overflow:hidden;">
            <div class="ap-coding" style="background:#FAF9F6;border-bottom:1px solid #EEEDE8;font-size:9.5px;letter-spacing:.08em;text-transform:uppercase;color:#8B948F;">
              <div style="padding:7px 10px;">Code</div><div style="padding:7px 10px;">Account and description</div><div style="padding:7px 10px;text-align:end;">Amount</div>
            </div>
            ${b.lines.map(l => `
              <div class="ap-coding" style="border-bottom:1px solid #F4F2EE;min-height:38px;">
                <div style="padding:0 10px;font-family:'IBM Plex Mono',monospace;font-size:11px;color:#5C665F;">${esc(l.code)}</div>
                <div style="padding:6px 10px;display:flex;flex-direction:column;gap:1px;min-width:0;">
                  <span class="coa-cell" style="font-size:12px;color:#16211E;">${esc(l.name)}</span>
                  <span class="ap-sub">${esc(l.desc)}</span>
                </div>
                <div class="coa-amount" style="padding:0 10px;font-size:11.5px;color:#16211E;">${fmt(l.amount)}</div>
              </div>`).join('')}
          </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:9px;">
          <div class="jd-caps">Supplier's invoice</div>
          <div id="apd-docs"></div>
        </div>
        <div style="display:flex;flex-direction:column;gap:9px;">
          <div class="jd-caps">Tax and settlement</div>
          <div class="ap-settle">
            <div><span>Taxable amount</span><span>${fmt(b.taxable)}</span></div>
            <div><span>VAT at 16%</span><span>${fmt(b.vat)}</span></div>
            <div class="rule"><span>Gross invoice value</span><span style="font-weight:600;">${fmt(b.gross)}</span></div>
            <div><span>Withholding tax at ${esc(b.whtRate)}%</span><span style="color:#A45B3E;">${b.wht ? '(' + fmt(b.wht) + ')' : 'nil'}</span></div>
            <div class="rule" style="font-size:13.5px;"><span style="font-weight:600;color:#16211E;">Net payable to supplier</span><span style="font-weight:700;color:var(--accent);">${fmt(b.net)}</span></div>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;">
          ${small('Fund', esc(b.fund))}${small('Programme', esc(b.program))}${small('Grant / award', esc(b.grant))}${small('Budget line remaining', esc(b.budget))}
        </div>
        <label class="ap-method">Payment method
          <select id="apd-method" ${current.can.method ? '' : 'disabled'}>
            ${(current.methodOptions.includes(b.method) ? current.methodOptions : [b.method, ...current.methodOptions]).map(m => `<option ${m === b.method ? 'selected' : ''}>${esc(m)}</option>`).join('')}
          </select>
        </label>
        ${ledger.length ? `
          <div style="display:flex;flex-direction:column;gap:6px;">
            <div class="jd-caps">In the ledger</div>
            ${ledger.map(x => `<div style="font-size:12px;color:#3E4A44;">${x}</div>`).join('')}
          </div>` : ''}
        <div style="display:flex;flex-direction:column;gap:9px;">
          <div class="jd-caps">Approval trail</div>
          ${b.trail.map(t => `
            <div style="display:flex;gap:10px;font-size:12px;color:#3E4A44;">
              <span style="color:#A3ABA7;font-family:'IBM Plex Mono',monospace;font-size:11px;min-width:56px;">${esc(t.when)}</span><span>${esc(t.what)}</span>
            </div>`).join('')}
        </div>`;
      UI.docPanel(el.querySelector('#apd-docs'), {
        kind: 'bill', ref: b.no, docs: b.documents, canAdd: current.can.attach,
        empty: "No supplier's invoice on file. Bills captured before documents were required may not have one — attach it before approving.",
        label: 'Attach the invoice or a supporting document',
        onAdded: (documents) => { b.documents = documents; },
      });

      renderFoot();
    }

    function renderFoot() {
      const b = current.bill;
      const can = current.can;
      const foot = el.querySelector('#apd-foot');
      foot.style.flexWrap = authorising ? 'wrap' : '';
      if (authorising) {
        foot.innerHTML = `
          <span class="jd-sod" style="max-width:none;flex:1 1 100%;">${esc(current.authorityNote || '')}</span>
          <input class="jd-reason" id="apd-authority" placeholder="Board minute or authority reference">
          <button type="button" class="btn" data-act="cancel-authority">Cancel</button>
          <button type="button" class="btn btn-primary" data-act="confirm-pay">Release ${fmt(b.net)}</button>`;
        return;
      }
      if (rejecting) {
        foot.innerHTML = `
          <input class="jd-reason" id="apd-reason" placeholder="Why the bill is going back to ${esc(b.preparer)}">
          <button type="button" class="btn" data-act="cancel-reject">Cancel</button>
          <button type="button" class="btn jd-quiet" data-act="confirm-reject">Reject bill</button>`;
        return;
      }
      foot.innerHTML = `
        ${can.reject ? '<button type="button" class="btn jd-quiet" data-act="reject">Reject</button>' : ''}
        ${can.sodNote ? `<span class="jd-sod">${esc(can.sodNote)}</span>` : ''}
        <div style="margin-inline-start:auto;display:flex;align-items:center;gap:9px;">
          <button type="button" class="btn" data-ap-close>Close</button>
          ${can.approve ? '<button type="button" class="btn btn-primary" data-act="approve">Approve for payment</button>' : ''}
          ${can.schedule ? '<button type="button" class="btn" data-act="schedule">Schedule</button>' : ''}
          ${can.pay ? `<button type="button" class="btn btn-primary" data-act="pay">Pay ${fmt(b.net)}</button>` : ''}
        </div>`;
    }

    async function openDrawer(no) {
      if (!el) build();
      try {
        await load(no);
      } catch (err) {
        UI.toast(`Bill ${no} could not be loaded.`);
        history.replaceState(null, '', '/payables' + location.search);
        return;
      }
      el.hidden = false;
    }

    function close() {
      if (el) el.hidden = true;
      history.replaceState(null, '', '/payables');
    }

    return { open: openDrawer };
  })();

  // ---- New bill ----

  const NewBill = (() => {
    let el;
    let form = null; // /api/payables/form
    let f = null;
    let lines = null;
    let docs = null;
    let saving = false;

    const blank = () => ({
      supplier: '', pin: '', category: 'Professional fees', invoiceNo: '', invoiceDate: form.today, terms: '30',
      budgetLine: String((form.budgetLines.find(l => l.code === '5150') || form.budgetLines[0] || {}).id || ''),
      method: form.methods[0] || '', wht: 'auto', whtReason: '', overReason: '',
    });
    const amountOf = (v) => parseFloat(String(v || '').replace(/[^0-9.]/g, '')) || 0;
    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const shortDate = (d) => String(d.getDate()).padStart(2, '0') + ' ' + MONTHS[d.getMonth()];

    function build() {
      el = document.createElement('div');
      el.className = 'ap-modal';
      el.hidden = true;
      el.innerHTML = `
        <div class="ap-modal-scrim" data-nb-close></div>
        <div class="ap-modal-box" role="dialog" aria-modal="true" aria-label="New bill">
          <div class="jd-head" style="padding:16px 20px;border-bottom-color:#E4E2DB;">
            <div style="display:flex;flex-direction:column;gap:3px;">
              <div class="jd-caps" style="letter-spacing:.1em;">New bill</div>
              <div style="font-size:16px;font-weight:600;letter-spacing:-.01em;">Capture a supplier invoice and code it to a budget line</div>
            </div>
            <button type="button" class="rt-close" data-nb-close aria-label="Close">×</button>
          </div>
          <div class="ap-modal-body"><div id="nb-body" style="display:contents;"></div><div id="nb-docs"></div></div>
          <div class="ap-modal-foot">
            <div style="min-width:0;flex:1;font-size:12px;line-height:1.5;" id="nb-status"></div>
            <button type="button" class="btn" data-nb-close style="height:34px;padding:0 14px;">Cancel</button>
            <button type="button" class="btn btn-primary" id="nb-create" style="height:34px;padding:0 18px;">Capture bill</button>
          </div>
        </div>`;
      document.body.appendChild(el);

      el.addEventListener('click', (e) => {
        if (e.target.closest('[data-nb-close]')) return close();
        if (e.target.closest('[data-nb-add]')) {
          lines.push({ desc: '', amount: '' });
          renderBody();
          const inputs = el.querySelectorAll('[data-line-desc]');
          inputs[inputs.length - 1].focus();
          return;
        }
        const remove = e.target.closest('[data-nb-remove]');
        if (remove) {
          lines.splice(+remove.dataset.nbRemove, 1);
          return renderBody();
        }
        if (e.target.closest('#nb-create')) create();
      });

      // Typing updates the figures without rebuilding the field being typed in.
      el.addEventListener('input', (e) => {
        const t = e.target;
        if (t.dataset.lineDesc !== undefined) lines[+t.dataset.lineDesc].desc = t.value;
        else if (t.dataset.lineAmount !== undefined) lines[+t.dataset.lineAmount].amount = t.value;
        else if (t.dataset.nb && t.tagName === 'INPUT') f[t.dataset.nb] = t.value;
        else return;
        renderDerived();
      });
      el.addEventListener('change', (e) => {
        const t = e.target;
        if (t.dataset.nbPick !== undefined) {
          const s = form.suppliers.find(x => x.name === t.value);
          if (s) {
            f.supplier = s.name;
            f.pin = s.pin;
            if (form.categories.some(c => c.name === s.category)) { f.category = s.category; f.wht = 'auto'; f.whtReason = ''; }
          }
          return renderBody();
        }
        if (!t.dataset.nb || t.tagName !== 'SELECT' && t.type !== 'date') return;
        f[t.dataset.nb] = t.value;
        if (t.dataset.nb === 'category') { f.wht = 'auto'; f.whtReason = ''; }
        if (t.dataset.nb === 'budgetLine') f.overReason = '';
        renderBody();
      });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !el.hidden) close(); });
    }

    /** Everything the form shows that follows from what has been entered, and the first thing still wrong. */
    function derive() {
      const cat = form.categories.find(c => c.name === f.category) || form.categories[0];
      const whtDefault = cat.wht;
      const whtRate = f.wht === 'auto' ? whtDefault : +f.wht;
      const whtOverridden = f.wht !== 'auto' && whtRate !== whtDefault;
      const line = form.budgetLines.find(l => String(l.id) === f.budgetLine) || form.budgetLines[0];
      const parsed = lines.map(l => ({ desc: l.desc.trim(), amount: amountOf(l.amount) }));
      const coded = parsed.filter(l => l.amount > 0);
      const taxable = coded.reduce((a, l) => a + l.amount, 0);
      const vat = Math.round(taxable * form.vatRate);
      const wht = Math.round(taxable * whtRate / 100);
      const overBudget = taxable > line.remaining;
      const due = f.invoiceDate ? new Date(f.invoiceDate + 'T00:00:00') : null;
      if (due) due.setDate(due.getDate() + (+f.terms || 30));
      const supplier = f.supplier.trim().toLowerCase();
      const dupe = form.bills.find(b => b.supplier.toLowerCase() === supplier && (b.invoiceNo === f.invoiceNo.trim() || (taxable > 0 && b.taxable === taxable)));

      const err = !f.supplier.trim() ? 'Name the supplier as it appears on the invoice.'
        : !/^P0\d{8}[A-Z]$/.test(f.pin.trim().toUpperCase()) ? 'The KRA PIN looks wrong. It runs P0 then eight digits and a letter, e.g. P051182934C — without it the VAT and WHT cannot be filed.'
        : !f.invoiceNo.trim() ? "Enter the supplier's invoice number. It is the duplicate-payment check."
        : !f.invoiceDate ? 'Enter the invoice date.'
        : !coded.length ? 'Code at least one line with an amount.'
        : coded.some(l => !l.desc) ? 'Every coded line needs a description of what was supplied.'
        : whtOverridden && !f.whtReason.trim() ? 'Overriding the withholding rate needs a reason — the tax file has to explain it.'
        : overBudget && !f.overReason.trim() ? 'This bill takes the line over budget. Say why before it goes for approval, or split the coding.'
        : form.requireInvoice && docs && !docs.ids().length ? "Attach the supplier's invoice. A bill goes for approval only with the document it pays."
        : '';

      return {
        cat, whtDefault, whtRate, whtOverridden, line, taxable, vat, wht, gross: taxable + vat, net: taxable + vat - wht, overBudget, err,
        dueText: due && !isNaN(due) ? `${shortDate(due)} (${f.terms} days)` : '—',
        dupeText: dupe ? (dupe.invoiceNo === f.invoiceNo.trim()
          ? `${dupe.no} already carries invoice ${dupe.invoiceNo} from ${dupe.supplier}. The same invoice cannot be captured twice.`
          : `Careful: ${dupe.no} is already on file for ${dupe.supplier} at the same value. Check this is not the same invoice arriving twice.`) : '',
      };
    }

    function renderBody() {
      const d = derive();
      const option = (value, label, current) => `<option value="${esc(value)}" ${String(value) === String(current) ? 'selected' : ''}>${esc(label)}</option>`;
      el.querySelector('#nb-body').innerHTML = `
        <div class="ap-cols" style="display:grid;grid-template-columns:minmax(0,1fr) 220px;gap:14px;align-items:end;">
          <label class="ap-f"><span>Supplier</span><input data-nb="supplier" value="${esc(f.supplier)}" placeholder="Name exactly as it appears on the invoice"></label>
          <label class="ap-f"><span>Or pull from the ledger</span>
            <select data-nb-pick><option value="">Existing supplier…</option>${form.suppliers.map(s => `<option>${esc(s.name)}</option>`).join('')}</select>
          </label>
        </div>
        <div class="ap-cols" style="display:grid;grid-template-columns:200px minmax(0,1fr) minmax(0,1fr);gap:14px;">
          <label class="ap-f"><span>KRA PIN</span><input class="mono" data-nb="pin" value="${esc(f.pin)}" placeholder="P051182934C"></label>
          <label class="ap-f"><span>Spend category</span>
            <select data-nb="category">${form.categories.map(c => option(c.name, c.name, f.category)).join('')}</select>
          </label>
          <label class="ap-f"><span>Supplier invoice number</span><input class="mono" data-nb="invoiceNo" value="${esc(f.invoiceNo)}" placeholder="INV-2026-0871"></label>
        </div>
        <div class="ap-cols" style="display:grid;grid-template-columns:160px 160px minmax(0,1fr);gap:14px;align-items:end;">
          <label class="ap-f"><span>Invoice date</span><input type="date" class="mono" data-nb="invoiceDate" value="${esc(f.invoiceDate)}"></label>
          <label class="ap-f"><span>Payment terms</span>
            <select data-nb="terms">${form.terms.map(t => option(t, t + ' days', f.terms)).join('')}</select>
          </label>
          <div class="ap-note" style="padding-bottom:9px;">Falls due <strong id="nb-due" style="color:#16211E;">${esc(d.dueText)}</strong> — the date the ageing and the payment run both work from.</div>
        </div>
        <div style="height:1px;background:#EEEDE8;"></div>
        <div class="ap-cols" style="display:grid;grid-template-columns:minmax(0,1fr) 220px;gap:14px;">
          <label class="ap-f"><span>Budget line</span>
            <select data-nb="budgetLine">${form.budgetLines.map(l => option(l.id, `${l.code} · ${l.program} · ${l.grant}`, f.budgetLine)).join('')}</select>
          </label>
          <label class="ap-f"><span>Expected payment method</span>
            <select data-nb="method">${form.methods.map(m => option(m, m, f.method)).join('')}</select>
          </label>
        </div>
        <div class="ap-coded" id="nb-coded"></div>
        <div class="ap-lines">
          <div class="ap-line" style="background:#FAF9F6;font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:#8B948F;">
            <div style="padding:8px 16px;">What was supplied</div><div style="padding:8px 16px;text-align:end;">Amount before VAT</div><div></div>
          </div>
          ${lines.map((l, i) => `
            <div class="ap-line">
              <div><input data-line-desc="${i}" value="${esc(l.desc)}" placeholder="e.g. Residential training — 3 days, 48 participants"></div>
              <div><input class="amount" data-line-amount="${i}" value="${esc(l.amount)}" inputmode="numeric" placeholder="0"></div>
              <div>${lines.length > 1 ? `<button type="button" class="jd-remove" data-nb-remove="${i}" aria-label="Remove line">×</button>` : ''}</div>
            </div>`).join('')}
          <div style="padding:8px 10px;"><button type="button" class="jd-dashed" data-nb-add>+ Add line</button></div>
        </div>
        <div id="nb-over"></div>
        <div class="ap-cols" style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px;align-items:start;">
          <label class="ap-f"><span>Withholding tax</span>
            <select data-nb="wht">
              ${option('auto', `Policy default for ${f.category} — ${d.whtDefault}%`, f.wht)}
              ${option('0', '0% — no withholding', f.wht)}${option('3', '3% — goods, resident', f.wht)}
              ${option('5', '5% — professional and management fees', f.wht)}${option('10', '10% — rent and royalties', f.wht)}
            </select>
            <span class="ap-note" id="nb-wht-label" style="font-weight:400;"></span>
          </label>
          ${d.whtOverridden ? `
            <label class="ap-f warn"><span>Why the rate differs from policy</span>
              <input data-nb="whtReason" value="${esc(f.whtReason)}" placeholder="e.g. Supplier holds a valid exemption certificate, ref…">
            </label>` : ''}
        </div>
        <div class="ap-sum" id="nb-sum"></div>
        <div id="nb-dupe"></div>`;
      renderDerived();
    }

    /** The parts that change while typing: coding note, over-budget reason, totals, duplicate warning, status line. */
    function renderDerived() {
      const d = derive();
      const $ = (id) => el.querySelector('#' + id);
      $('nb-due').textContent = d.dueText;
      $('nb-coded').innerHTML = `Coded to <strong>${esc(d.line.fund)} · ${esc(d.line.program)} · ${esc(d.line.grant)}</strong>. ${esc(d.line.code)} ${esc(d.line.name)} — ${fmt(d.line.remaining)} uncommitted of ${fmt(d.line.annual)}.`;
      const over = $('nb-over');
      if (d.overBudget && !over.querySelector('input')) {
        over.innerHTML = `<div class="ap-over"><span id="nb-over-text"></span><input data-nb="overReason" value="${esc(f.overReason)}" placeholder="Why this is still the right coding — or split the bill across lines"></div>`;
      } else if (!d.overBudget) {
        over.innerHTML = '';
      }
      if (d.overBudget) {
        $('nb-over-text').textContent = `Coding ${fmt(d.taxable)} to ${d.line.code} exceeds the ${fmt(d.line.remaining)} left on that line after ${fmt(d.line.actual)} spent and ${fmt(d.line.onBills)} already sitting on unpaid bills.`;
      }
      $('nb-wht-label').textContent = `${d.whtRate}% withheld on ${d.taxable ? fmt(d.taxable) : '0'} — held back from the supplier and remitted to KRA by the 20th of the following month.`;
      $('nb-sum').innerHTML = `
        <div><span>Net of tax</span><span>${fmt(d.taxable)}</span></div>
        <div><span>VAT at 16%</span><span>${fmt(d.vat)}</span></div>
        <div><span>Invoice total</span><span style="font-weight:600;">${fmt(d.gross)}</span></div>
        <div><span>Withholding tax held</span><span>${fmt(d.wht)}</span></div>
        <div class="net"><span>Payable to supplier</span><span>${fmt(d.net)}</span></div>`;
      $('nb-dupe').innerHTML = d.dupeText && !d.err ? `<div class="ap-dupe">${esc(d.dupeText)}</div>` : '';
      $('nb-status').innerHTML = d.err
        ? `<span style="color:#A6412F;">${esc(d.err)}</span>`
        : `<span style="color:#8B948F;">Approval posts the cost, VAT and WHT to the ledger. Capture does not commit cash — that happens in the payment run.</span>`;
    }

    async function create() {
      const d = derive();
      if (d.err) {
        UI.toast(d.err);
        return;
      }
      if (docs.busy()) return UI.toast('Wait for the invoice to finish uploading.');
      if (saving) return;
      saving = true;
      el.querySelector('#nb-create').disabled = true;
      try {
        const res = await UI.postJSON('/api/payables', { ...f, lines, documents: docs.ids() });
        const bill = res.bill;
        close();
        UI.toast(`${bill.no} captured for ${bill.supplier} — ${fmt(bill.net)} payable${bill.wht ? `, WHT ${fmt(bill.wht)} to be held for KRA` : ', no withholding tax'}. It posts to the ledger once a second person approves it.`);
        state.status = 'All'; state.age = 'All'; state.fund = 'All funds'; state.q = ''; state.page = 1;
        app.querySelector('#ap-q').value = '';
        await refresh();
        open(bill.no);
      } catch (err) {
        el.querySelector('#nb-status').innerHTML = `<span style="color:#A6412F;">${esc(err.message)}</span>`;
      } finally {
        saving = false;
        el.querySelector('#nb-create').disabled = false;
      }
    }

    async function openModal() {
      if (!el) build();
      try {
        form = await UI.fetchJSON('/api/payables/form');
      } catch (err) {
        UI.toast('The bill form could not be loaded.');
        return;
      }
      f = blank();
      lines = [{ desc: '', amount: '' }];
      docs = UI.docPicker(el.querySelector('#nb-docs'), {
        label: "Supplier's invoice", required: form.requireInvoice, onChange: () => renderDerived(),
        hint: 'A scan or the PDF the supplier sent. The approver and the auditor see it with the bill.',
      });
      renderBody();
      el.hidden = false;
      el.querySelector('[data-nb="supplier"]').focus();
    }

    function close() {
      if (el) el.hidden = true;
    }

    return { open: openModal };
  })();

  shell();
  await refresh();

  const openNo = app.getAttribute('data-open');
  if (openNo) open(openNo);
})();
