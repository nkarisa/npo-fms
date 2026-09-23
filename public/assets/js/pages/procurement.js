/**
 * Procurement (v5): requisition to purchase order to goods received to bill. The
 * headline stats and a view switcher — Requisitions (status tabs, search, ten a
 * page), Purchase orders, Goods received and Suppliers — the requisition drawer with
 * each step's action, the new requisition form with its lines, quotations and
 * budget check, and the supplier drawer that registers and edits suppliers. /procurement/<requisition> opens a requisition straight away. The API
 * enforces the budget check, the three-quote rule and segregation of duties.
 */
(async function () {
  const app = document.getElementById('app');
  const esc = UI.esc;
  const fmt = UI.fmtMoney;
  const state = { view: 'Requisitions', tab: 'Open', q: '', page: 1 };
  let data = null;

  const PILL = {
    Draft: 'grey', 'Awaiting approval': 'pending', Approved: 'approved', 'RFQ issued': 'scheduled', 'PO raised': 'teal',
    'Goods received': 'posted', Closed: 'grey', Rejected: 'reversed',
    'Awaiting delivery': 'teal', Received: 'posted',
    'Pre-qualified': 'posted', Expiring: 'pending', Lapsed: 'overdue', 'Not pre-qualified': 'grey', Blocked: 'overdue',
  };
  const pill = (status, label) => `<span class="jr-pill ${PILL[status] || 'grey'}">${esc(label || status)}</span>`;

  async function refresh() {
    const p = new URLSearchParams({ view: state.view, tab: state.tab, q: state.q, page: state.page });
    try {
      data = await UI.fetchJSON('/api/procurement?' + p.toString());
    } catch (err) {
      app.querySelector('#pq-table').innerHTML = `<div class="coa-empty">${esc(err.message)}</div>`;
      return;
    }
    if (data.page) state.page = data.page;
    render();
  }

  function shell() {
    app.innerHTML = `
      <div class="page-head" style="margin-bottom:0;">
        <div>
          <h1 class="page-title" style="margin-top:0;">Procurement</h1>
          <p class="page-blurb" style="max-width:680px;">Requisition to purchase order to goods received. Budget availability is checked before approval, three quotations are required above KES 500,000, and a goods received note raises the supplier bill.</p>
        </div>
        <div class="page-actions" style="margin-left:0;margin-inline-start:auto;">
          <button type="button" class="btn" id="pq-add-supplier" hidden>+ Add supplier</button>
          <button type="button" class="btn btn-primary" id="pq-new">+ New requisition</button>
        </div>
      </div>
      <div class="stat-grid" id="pq-stats" style="margin:18px 0 0;"></div>
      <div class="coa-seg" id="pq-views" style="margin-top:16px;align-self:flex-start;display:inline-flex;"></div>
      <div class="jr-filters" id="pq-filters">
        <div class="coa-seg" id="pq-tabs" style="flex-wrap:wrap;"></div>
        <label class="coa-search" style="flex:1 1 220px;min-width:190px;max-width:300px;width:auto;">⌕
          <input type="search" id="pq-q" placeholder="Requisition, item or requester">
        </label>
      </div>
      <div class="coa-card" id="pq-card">
        <div style="overflow-x:auto;"><div id="pq-table"></div></div>
        <div id="pq-pager"></div>
        <div class="coa-foot"><span id="pq-footer"></span></div>
      </div>`;

    app.querySelector('#pq-views').addEventListener('click', (e) => {
      const b = e.target.closest('[data-view]');
      if (!b) return;
      state.view = b.dataset.view;
      state.page = 1;
      refresh();
    });
    app.querySelector('#pq-tabs').addEventListener('click', (e) => {
      const b = e.target.closest('[data-tab]');
      if (!b) return;
      state.tab = b.dataset.tab;
      state.page = 1;
      refresh();
    });
    let searchTimer;
    app.querySelector('#pq-q').addEventListener('input', (e) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => { state.q = e.target.value; state.page = 1; refresh(); }, 200);
    });
    app.querySelector('#pq-pager').addEventListener('click', (e) => {
      const b = e.target.closest('[data-page]');
      if (!b || b.disabled) return;
      state.page = +b.dataset.page;
      refresh();
    });
    const table = app.querySelector('#pq-table');
    const activate = (target) => {
      const supplier = target.closest('[data-supplier]');
      if (supplier) return Supplier.open(+supplier.dataset.supplier);
      const row = target.closest('[data-no]');
      if (row) open(row.dataset.no);
    };
    table.addEventListener('click', (e) => activate(e.target));
    table.addEventListener('keydown', (e) => {
      if (e.key !== 'Enter' && e.key !== ' ') return;
      if (!e.target.closest('[data-supplier], [data-no]')) return;
      e.preventDefault();
      activate(e.target);
    });
    app.querySelector('#pq-new').addEventListener('click', () => NewRequisition.open());
    app.querySelector('#pq-add-supplier').addEventListener('click', () => Supplier.open(null));
  }

  function render() {
    app.querySelector('#pq-stats').innerHTML = data.stats.map(s => `
      <div class="stat">
        <div class="stat-label">${esc(s.label)}</div>
        <div class="stat-value">${esc(s.value)}</div>
        <div class="stat-note">${esc(s.note)}</div>
      </div>`).join('');
    app.querySelector('#pq-views').innerHTML = data.views.map(v => `
      <button type="button" class="coa-seg-btn ${v === state.view ? 'on' : ''}" data-view="${esc(v)}" style="padding:6px 14px;font-size:12px;">${esc(v)}</button>`).join('');

    const reqs = state.view === 'Requisitions';
    app.querySelector('#pq-filters').hidden = !reqs;
    app.querySelector('#pq-add-supplier').hidden = !(state.view === 'Suppliers' && data.can && data.can.register);
    app.querySelector('#pq-card').style.marginTop = reqs ? '' : '12px';
    if (reqs) {
      app.querySelector('#pq-tabs').innerHTML = data.tabs.map(t => `
        <button type="button" class="coa-seg-btn ${t === state.tab ? 'on' : ''}" data-tab="${esc(t)}">${esc(t)}</button>`).join('');
    }
    app.querySelector('#pq-footer').textContent = data.footer;
    app.querySelector('#pq-pager').innerHTML = reqs && data.pages > 1 ? pager() : '';

    const table = app.querySelector('#pq-table');
    const empty = (text) => data.rows.length ? '' : `<div class="coa-empty">${esc(text)}</div>`;
    if (reqs) {
      table.style.minWidth = '1500px';
      table.innerHTML = `
        <div class="pq-grid pq-reqs coa-head">
          <div>Requisition</div><div>Item</div><div>Requester</div><div>Programme</div><div>Grant / award</div><div>Fund</div>
          <div>Raised</div><div>Need by</div><div style="text-align:end;">Value</div><div style="text-align:end;">Budget left</div><div>Status</div>
        </div>
        ${data.rows.map(p => `
          <div class="pq-grid pq-reqs ap-row" data-no="${esc(p.no)}" tabindex="0">
            <div class="jr-ref">${esc(p.no)}</div>
            <div class="jr-narration">${esc(p.title)}</div>
            <div class="coa-cell">${esc(p.requester)}</div>
            <div class="coa-cell">${esc(p.program)}</div>
            <div class="coa-cell">${esc(p.grant)}</div>
            <div class="coa-cell">${esc(p.fund)}</div>
            <div class="jr-date" style="font-size:11px;">${esc(p.raised)}</div>
            <div class="jr-date" style="font-size:11px;">${esc(p.needBy)}</div>
            <div class="coa-amount" style="color:#16211E;">${fmt(p.amount)}</div>
            <div class="coa-amount" style="${p.overBudget ? 'color:#A6412F;font-weight:600;' : 'color:#6E7873;'}">${fmt(p.available)}</div>
            <div>
              ${pill(p.status)}
              ${p.approval && p.approval.of > 1 ? `<div class="jr-ladder" title="${esc(p.approval.note)}">${p.approval.signed}/${p.approval.of} · ${esc(p.approval.awaiting)}</div>` : ''}
            </div>
          </div>`).join('')}
        ${empty('No requisitions match this view.')}`;
    } else if (state.view === 'Purchase orders') {
      table.style.minWidth = '1210px';
      table.innerHTML = `
        <div class="pq-grid pq-pos coa-head">
          <div>Order</div><div>Supplier</div><div>Item</div><div>Requisition</div><div>Issued</div><div>Expected</div><div style="text-align:end;">Value</div><div>Three-way match</div>
        </div>
        ${data.rows.map(o => `
          <div class="pq-grid pq-pos ap-row" data-no="${esc(o.no)}" tabindex="0">
            <div class="jr-ref">${esc(o.po)}</div>
            <div class="coa-cell" style="color:#28352F;">${esc(o.supplier)}</div>
            <div class="jr-narration">${esc(o.title)}</div>
            <div class="jr-date" style="font-size:11px;">${esc(o.no)}</div>
            <div class="jr-date" style="font-size:11px;">${esc(o.issued)}</div>
            <div class="jr-date" style="font-size:11px;">${esc(o.expected)}</div>
            <div class="coa-amount" style="color:#16211E;">${fmt(o.value)}</div>
            <div style="display:flex;align-items:center;gap:8px;min-width:0;">${pill(o.state)}<span class="coa-cell" style="font-size:11px;color:#7A857F;">${esc(o.match)}</span></div>
          </div>`).join('')}
        ${empty('No purchase orders have been raised yet.')}`;
    } else if (state.view === 'Goods received') {
      table.style.minWidth = '1180px';
      table.innerHTML = `
        <div class="pq-grid pq-grns coa-head">
          <div>Note</div><div>Order</div><div>Supplier</div><div>Received</div><div>Date</div><div>Signed by</div><div style="text-align:end;">Value</div><div>Invoice</div>
        </div>
        ${data.rows.map(g => `
          <div class="pq-grid pq-grns ap-row" data-no="${esc(g.no)}" tabindex="0" style="height:46px;">
            <div class="jr-ref">${esc(g.grn)}</div>
            <div class="jr-date" style="font-size:11px;">${esc(g.po)}</div>
            <div class="coa-cell" style="color:#28352F;">${esc(g.supplier)}</div>
            <div style="display:flex;flex-direction:column;gap:1px;min-width:0;"><span class="jr-narration">${esc(g.title)}</span><span class="ap-sub">${esc(g.note)}</span></div>
            <div class="jr-date" style="font-size:11px;">${esc(g.when)}</div>
            <div class="coa-cell">${esc(g.receivedBy)}</div>
            <div class="coa-amount" style="color:#16211E;">${fmt(g.value)}</div>
            <div>${g.bill ? `<span class="pc-mono" style="font-size:11px;color:var(--calm-ink);">${esc(g.bill)}</span>` : '<span style="font-size:11px;color:#8B948F;">Not yet invoiced</span>'}</div>
          </div>`).join('')}
        ${empty('Nothing has been received against an order yet.')}`;
    } else {
      table.style.minWidth = '1170px';
      table.innerHTML = `
        <div class="pq-grid pq-sups coa-head">
          <div>Supplier</div><div>KRA PIN</div><div>Category</div><div>Pre-qualified to</div><div>Rating</div><div>Withholding</div><div style="text-align:end;">Spend YTD</div><div>Status</div>
        </div>
        ${data.rows.map(s => `
          <div class="pq-grid pq-sups ap-row" data-supplier="${s.id}" tabindex="0" style="height:40px;">
            <div class="coa-cell" style="font-size:12.5px;color:#16211E;">${esc(s.name)}</div>
            <div class="jr-date" style="font-size:11px;">${esc(s.pin)}</div>
            <div class="coa-cell">${esc(s.category)}</div>
            <div class="jr-date" style="font-size:11px;">${esc(s.prequalUntil)}</div>
            <div class="coa-cell" style="font-weight:600;">${esc(s.rating)}</div>
            <div class="coa-cell">${esc(s.wht)}</div>
            <div class="coa-amount">${fmt(s.spend)}</div>
            <div style="display:flex;align-items:center;gap:8px;">${pill(s.status)}<span style="font-size:11px;color:#8B948F;white-space:nowrap;">${s.openPos} open</span></div>
          </div>`).join('')}
        ${empty('No suppliers are on the register yet.')}`;
    }
  }

  function pager() {
    const p = data.page;
    const buttons = [];
    for (let k = 1; k <= data.pages; k++) buttons.push(`<button type="button" class="coa-page ${k === p ? 'on' : ''}" data-page="${k}">${k}</button>`);
    return `
      <div class="coa-pager">
        <span>Showing ${(p - 1) * data.pageSize + 1}–${Math.min(data.filtered, p * data.pageSize)} of ${data.filtered} requisitions</span>
        <div style="margin-inline-start:auto;display:flex;align-items:center;gap:4px;">
          <button type="button" class="coa-page" data-page="${p - 1}" ${p <= 1 ? 'disabled' : ''}>‹</button>
          ${buttons.join('')}
          <button type="button" class="coa-page" data-page="${p + 1}" ${p >= data.pages ? 'disabled' : ''}>›</button>
        </div>
      </div>`;
  }

  /** Opens a requisition in the drawer, keeping its address in the location bar while it is open. */
  function open(no) {
    history.replaceState(null, '', '/procurement/' + encodeURIComponent(no));
    Requisition.open(no);
  }

  // ---- Requisition drawer ----

  const Requisition = (() => {
    let el;
    let current = null; // { requisition, can }
    let billDocs = null; // the invoice picker while a bill is being raised
    let mode = null; // 'reject' | 'po' | 'receive' | 'bill'

    function build() {
      el = document.createElement('div');
      el.className = 'rt';
      el.hidden = true;
      el.innerHTML = `
        <div class="jd-backdrop" data-pq-close></div>
        <div class="rt-panel" style="width:540px;" role="dialog" aria-modal="true" aria-label="Requisition">
          <div class="rt-head" style="padding:16px 20px;" id="pqd-head"></div>
          <div class="rt-body pq-body" style="padding:18px 20px;gap:18px;" id="pqd-body"></div>
          <div class="rt-foot" style="padding:13px 20px;flex-wrap:wrap;background:#FBFAF7;" id="pqd-foot"></div>
        </div>`;
      document.body.appendChild(el);

      el.addEventListener('click', (e) => {
        if (e.target.closest('[data-pq-close]')) return close();
        const journal = e.target.closest('[data-journal]');
        if (journal) return UI.openJournal(journal.dataset.journal);
        const act = e.target.closest('[data-act]');
        if (!act) return;
        const r = current.requisition;
        const url = (step) => `/api/procurement/${encodeURIComponent(r.no)}/${step}`;
        const value = (id) => (el.querySelector('#' + id) || {}).value || '';
        switch (act.dataset.act) {
          case 'submit':
            return run(() => UI.postJSON(url('submit')), () => `${r.no} submitted for approval.`);
          case 'approve':
            return run(() => UI.postJSON(url('approve')), () => `${r.no} approved within the available budget.`);
          case 'rfq':
            return run(() => UI.postJSON(url('rfq')), () => `RFQ issued for ${r.no}.`);
          case 'reject': case 'po': case 'receive': case 'bill':
            mode = act.dataset.act;
            renderFoot();
            (el.querySelector('#pqd-foot input') || {}).focus?.();
            return;
          case 'cancel':
            mode = null;
            return renderFoot();
          case 'confirm-reject':
            return run(() => UI.postJSON(url('reject'), { reason: value('pqd-reason') }), () => `${r.no} rejected and returned to the requester.`);
          case 'confirm-po':
            return run(() => UI.postJSON(url('purchase-order'), { supplier: value('pqd-supplier'), waiver: value('pqd-waiver') }),
              (d) => `Purchase order ${d.requisition.po} raised with ${d.requisition.supplier} for ${r.no}; ${fmt(r.amount)} committed against ${r.code}.`);
          case 'confirm-receive':
            return run(() => UI.postJSON(url('receive'), { receivedBy: value('pqd-received-by'), note: value('pqd-note') }),
              (d) => `Goods received against ${r.no} on ${d.requisition.grn}; the commitment is released and the cost accrued.`);
          case 'confirm-bill':
            if (billDocs && billDocs.busy()) return UI.toast('Wait for the invoice to finish uploading.');
            return run(() => UI.postJSON(url('bill'), { invoiceNo: value('pqd-invoice'), documents: billDocs ? billDocs.ids() : [] }),
              (d) => `${d.bill.no} created in Payables from ${r.no}, awaiting approval.`);
        }
      });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !el.hidden && !document.querySelector('.jd:not([hidden])')) close(); });
    }

    async function load(no) {
      current = await UI.fetchJSON('/api/procurement/' + encodeURIComponent(no));
      mode = null;
      render();
    }

    async function run(request, message) {
      el.querySelectorAll('#pqd-foot button').forEach(b => { b.disabled = true; });
      try {
        const d = await request();
        UI.toast(message(d));
        await load(current.requisition.no);
        refresh();
      } catch (err) {
        UI.toast(err.message);
        el.querySelectorAll('#pqd-foot button').forEach(b => { b.disabled = false; });
      }
    }

    function render() {
      const r = current.requisition;
      el.querySelector('#pqd-head').innerHTML = `
        <div style="display:flex;flex-direction:column;gap:4px;min-width:0;">
          <div style="display:flex;align-items:center;gap:9px;"><span class="jr-ref" style="font-size:12px;">${esc(r.no)}</span>${pill(r.status)}</div>
          <div style="font-size:15.5px;font-weight:600;letter-spacing:-.01em;">${esc(r.title)}</div>
          <div style="font-size:11.5px;color:#7A857F;">${esc(r.requester)} · ${esc(r.program)} · need by ${esc(r.needBy)}</div>
        </div>
        <button type="button" class="rt-close" data-pq-close aria-label="Close">×</button>`;

      const row = (label, value) => `<div class="ar-kv"><span>${esc(label)}</span><span>${value}</span></div>`;
      el.querySelector('#pqd-body').innerHTML = `
        ${r.approval ? `<div class="jd-ladder"><span class="jd-caps">Approval</span><span>${esc(r.approval.note)}</span></div>` : ''}
        ${r.overBudget ? `<div class="pq-alert">
          <span class="pq-alert-mark">!</span>
          <span>The requisition exceeds what is left on budget line ${esc(r.code)} (${fmt(r.available)} available). A budget revision is needed before this can be approved.</span>
        </div>` : ''}
        ${r.needsQuotes ? `<div class="pq-alert warn">
          <span class="pq-alert-mark">!</span>
          <span>Three quotations, each with the supplier's document, are required above KES ${fmt(data ? data.threshold : 500000)}. ${r.quotesOnFile} ${r.quotesOnFile === 1 ? 'is' : 'are'} on file — attach the rest or record a single-source justification when the order is raised.</span>
        </div>` : ''}
        ${r.status === 'Rejected' && r.rejectedReason ? `<div class="ap-reject-note">Rejected — ${esc(r.rejectedReason)}</div>` : ''}
        <div class="ar-figures" style="grid-template-columns:repeat(4,minmax(0,1fr));">
          <div><span class="jd-caps">Value</span><span>${fmt(r.amount)}</span></div>
          <div><span class="jd-caps">Budget</span><span>${fmt(r.budget)}</span></div>
          <div><span class="jd-caps">Spent</span><span>${fmt(r.spent)}</span></div>
          <div><span class="jd-caps">Available</span><span style="font-weight:600;${r.overBudget ? 'color:#A6412F;' : ''}">${fmt(r.available)}</span></div>
        </div>
        <div class="ar-kvs">
          ${row('Budget line', `<span class="pc-mono">${esc(r.code)}</span> · ${esc(r.account)}`)}
          ${row('Grant / award', `${esc(r.grant)} · ${esc(r.fund)}`)}
          ${row('Supplier', esc(r.supplier || 'Not yet selected'))}
          ${r.po ? row('Order', `${esc(r.po)} · issued ${esc(r.poDate)} · expected ${esc(r.expected)}`) : ''}
          ${r.grn ? row('Received', `${esc(r.grn)} on ${esc(r.grnDate)}, ${esc(r.receivedBy)} — ${esc(r.grnNote)}${r.grnJournal ? ` · accrued as <button type="button" class="ap-link" data-journal="${esc(r.grnJournal)}">${esc(r.grnJournal)}</button>` : ''}`) : ''}
          ${r.bill ? row('Invoice', `<a class="ap-link" href="/payables/${encodeURIComponent(r.bill)}">${esc(r.bill)}</a> in payables · ${esc(r.billStatus)}`) : ''}
          ${r.singleSource ? row('Single source', esc(r.singleSource)) : ''}
          ${r.justification ? row('Justification', esc(r.justification)) : ''}
        </div>
        <div style="display:flex;flex-direction:column;gap:9px;">
          <div class="jd-caps">Requisition lines</div>
          <div style="border:1px solid #E4E2DB;border-radius:8px;overflow:hidden;">
            ${r.lines.map(l => `
              <div class="pq-line">
                <span style="font-size:12px;color:#28352F;">${esc(l.desc)}</span>
                <span class="coa-amount" style="font-size:11.5px;color:#6E7873;">${esc(l.qty)}</span>
                <span class="coa-amount" style="font-size:11.5px;color:#6E7873;">${fmt(l.unit)}</span>
                <span class="coa-amount" style="color:#16211E;">${fmt(l.amount)}</span>
              </div>`).join('')}
          </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:9px;">
          <div class="jd-caps">Quotations</div>
          ${r.quotes.length ? `<div style="font-size:11px;color:#8B948F;margin-top:-4px;">${esc(r.quoteDocs)}</div>` : '<div class="ar-empty">No quotations on file yet.</div>'}
          ${r.quotes.map(q => `
            <div class="pq-quote ${q.chosen ? 'chosen' : ''}">
              <span class="pq-radio ${q.chosen ? 'on' : ''}"></span>
              <span style="display:flex;flex-direction:column;gap:2px;min-width:0;">
                <span style="font-size:12.5px;color:#16211E;">${esc(q.supplier)}${q.chosen ? ' <span style="font-size:10.5px;color:var(--accent-ink);">· selected</span>' : ''}</span>
                ${q.note ? `<span style="font-size:11px;color:#7A857F;">${esc(q.note)}</span>` : ''}
                ${q.document
                  ? `<a class="ap-link" style="font-size:11px;" href="/api/procurement/${encodeURIComponent(r.no)}/documents/${q.document.id}">◫ ${esc(q.document.name)} · ${esc(q.document.size)}</a>`
                  : '<span style="font-size:11px;color:#9AA39E;">No document attached</span>'}
              </span>
              <span class="coa-amount" style="color:#16211E;">${fmt(q.amount)}</span>
            </div>`).join('')}
        </div>
        <div style="display:flex;flex-direction:column;gap:9px;">
          <div class="jd-caps">Audit trail</div>
          ${r.trail.map(t => `
            <div style="display:flex;gap:12px;font-size:12px;color:#3E4A44;">
              <span style="font-family:'IBM Plex Mono',monospace;font-size:11px;color:#9AA39E;width:58px;flex:0 0 58px;">${esc(t.when)}</span>
              <span style="line-height:1.5;">${esc(t.what)}</span>
            </div>`).join('')}
        </div>`;
      renderFoot();
    }

    function renderFoot() {
      const r = current.requisition;
      const can = current.can;
      const foot = el.querySelector('#pqd-foot');
      const cancel = '<button type="button" class="btn" data-act="cancel">Cancel</button>';
      if (mode === 'reject') {
        foot.innerHTML = `<input class="jd-reason" id="pqd-reason" placeholder="Why the requisition is going back to the requester">${cancel}
          <button type="button" class="btn jd-quiet" data-act="confirm-reject">Reject</button>`;
        return;
      }
      if (mode === 'po') {
        const chosen = r.quotes.find(q => q.chosen);
        foot.innerHTML = `
          <label class="ap-f" style="flex:1 1 200px;"><span>Order placed with</span>
            <select id="pqd-supplier">${r.quotes.length
              ? r.quotes.map(q => `<option value="${esc(q.supplier)}" ${q === chosen ? 'selected' : ''}>${esc(q.supplier)} · ${fmt(q.amount)}</option>`).join('')
              : `<option value="${esc(r.supplier)}">${esc(r.supplier || 'Name the supplier on the requisition first')}</option>`}</select>
          </label>
          ${r.needsQuotes ? `<label class="ap-f warn" style="flex:1 1 100%;"><span>Single-source justification — ${r.quotesOnFile} of 3 documented quotations on file</span>
            <input id="pqd-waiver" placeholder="e.g. Framework rates agreed under tender ELOG/T/2025/04"></label>` : ''}
          <div style="display:flex;gap:9px;margin-inline-start:auto;">${cancel}<button type="button" class="btn btn-primary" data-act="confirm-po">Raise purchase order</button></div>`;
        return;
      }
      if (mode === 'receive') {
        foot.innerHTML = `
          <label class="ap-f" style="flex:1 1 180px;"><span>Signed for by</span><input id="pqd-received-by" value="Store · A. Kariuki"></label>
          <label class="ap-f" style="flex:1 1 220px;"><span>Note</span><input id="pqd-note" placeholder="Received in full against the purchase order"></label>
          <div style="display:flex;gap:9px;margin-inline-start:auto;">${cancel}<button type="button" class="btn btn-primary" data-act="confirm-receive">Record goods received</button></div>`;
        return;
      }
      if (mode === 'bill') {
        foot.innerHTML = `
          <label class="ap-f" style="flex:1 1 200px;"><span>Supplier invoice number</span><input class="mono" id="pqd-invoice" placeholder="INV-2026-0871"></label>
          <div id="pqd-bill-docs" style="flex:1 1 100%;"></div>
          <span class="ap-note" style="flex:1 1 100%;">Matches ${esc(r.po)}, ${esc(r.grn)} and the invoice. The bill waits for approval in Payables; approving it clears the accrual.</span>
          <div style="display:flex;gap:9px;margin-inline-start:auto;">${cancel}<button type="button" class="btn btn-primary" data-act="confirm-bill">Raise supplier bill</button></div>`;
        billDocs = UI.docPicker(foot.querySelector('#pqd-bill-docs'), { label: "Supplier's invoice", required: true, hint: 'The third leg of the match. The bill does not go for approval without it.' });
        return;
      }
      foot.innerHTML = `
        ${can.submit ? '<button type="button" class="btn btn-primary" data-act="submit">Submit for approval</button>' : ''}
        ${can.approve ? '<button type="button" class="btn btn-primary" data-act="approve">Approve</button>' : ''}
        ${can.reject ? '<button type="button" class="btn jd-quiet" data-act="reject">Reject</button>' : ''}
        ${can.rfq ? '<button type="button" class="btn" data-act="rfq">Issue RFQ</button>' : ''}
        ${can.po ? '<button type="button" class="btn btn-primary" data-act="po">Raise purchase order</button>' : ''}
        ${can.receive ? '<button type="button" class="btn btn-primary" data-act="receive">Record goods received</button>' : ''}
        ${can.bill ? '<button type="button" class="btn btn-primary" data-act="bill">Raise supplier bill</button>' : ''}
        ${can.note ? `<span class="jd-sod">${esc(can.note)}</span>` : ''}
        <button type="button" class="btn" data-pq-close style="margin-inline-start:auto;">Close</button>`;
    }

    async function openDrawer(no) {
      if (!el) build();
      try {
        await load(no);
      } catch (err) {
        UI.toast(`Requisition ${no} could not be loaded.`);
        history.replaceState(null, '', '/procurement');
        return;
      }
      el.hidden = false;
    }

    function close() {
      if (el) el.hidden = true;
      history.replaceState(null, '', '/procurement');
    }

    return { open: openDrawer };
  })();

  // ---- New requisition ----

  const NewRequisition = (() => {
    let el;
    let form = null; // /api/procurement/form
    let f = null;
    let saving = false;

    const num = (v) => parseFloat(String(v || '').replace(/[^0-9.]/g, '')) || 0;
    const unique = (list) => list.filter((v, i, a) => a.indexOf(v) === i);

    function build() {
      el = document.createElement('div');
      el.className = 'rt';
      el.hidden = true;
      el.innerHTML = `
        <div class="jd-backdrop" data-nr-close></div>
        <div class="rt-panel" style="width:560px;" role="dialog" aria-modal="true" aria-label="New requisition">
          <div class="rt-head" style="padding:16px 20px;align-items:center;">
            <div style="display:flex;flex-direction:column;gap:3px;">
              <div class="jd-caps" style="letter-spacing:.1em;">New requisition</div>
              <div style="font-size:15.5px;font-weight:600;letter-spacing:-.01em;">Raise a purchase requisition</div>
            </div>
            <button type="button" class="rt-close" data-nr-close aria-label="Close">×</button>
          </div>
          <div class="rt-body pq-body" style="padding:18px 20px;gap:16px;" id="nr-body"></div>
          <div class="rt-foot" style="padding:13px 20px;background:#FBFAF7;">
            <span id="nr-status" style="font-size:12px;line-height:1.5;flex:1;min-width:0;"></span>
            <button type="button" class="btn" data-nr-close>Cancel</button>
            <button type="button" class="btn btn-primary" id="nr-create">Save as draft</button>
          </div>
        </div>`;
      document.body.appendChild(el);

      el.addEventListener('click', (e) => {
        if (e.target.closest('[data-nr-close]')) return close();
        if (e.target.closest('[data-nr-add-line]')) { f.lines.push({ desc: '', qty: '1', unit: '' }); return renderBody(); }
        const removeLine = e.target.closest('[data-nr-remove-line]');
        if (removeLine) { f.lines.splice(+removeLine.dataset.nrRemoveLine, 1); return renderBody(); }
        if (e.target.closest('[data-nr-add-quote]')) { f.quotes.push({ supplier: '', amount: '', note: '', chosen: !f.quotes.length, file: null }); return renderBody(); }
        const removeQuote = e.target.closest('[data-nr-remove-quote]');
        if (removeQuote) {
          f.quotes.splice(+removeQuote.dataset.nrRemoveQuote, 1);
          if (f.quotes.length && !f.quotes.some(q => q.chosen)) f.quotes[0].chosen = true;
          return renderBody();
        }
        const choose = e.target.closest('[data-nr-choose]');
        if (choose) { f.quotes.forEach((q, i) => { q.chosen = i === +choose.dataset.nrChoose; }); return renderBody(); }
        const clearFile = e.target.closest('[data-nr-clear-file]');
        if (clearFile) { f.quotes[+clearFile.dataset.nrClearFile].file = null; return renderBody(); }
        if (e.target.closest('#nr-create')) create();
      });
      el.addEventListener('input', (e) => {
        const t = e.target;
        if (t.dataset.nrLine !== undefined) f.lines[+t.dataset.nrLine][t.dataset.key] = t.value;
        else if (t.dataset.nrQuote !== undefined && t.type !== 'file') f.quotes[+t.dataset.nrQuote][t.dataset.key] = t.value;
        else if (t.dataset.nr && t.tagName !== 'SELECT') f[t.dataset.nr] = t.value;
        else return;
        renderDerived();
      });
      el.addEventListener('change', (e) => {
        const t = e.target;
        if (t.type === 'file' && t.dataset.nrQuote !== undefined) {
          f.quotes[+t.dataset.nrQuote].file = t.files[0] || null;
          return renderBody();
        }
        if (!t.dataset.nr || t.tagName !== 'SELECT') return;
        f[t.dataset.nr] = t.value;
        if (t.dataset.nr === 'award') { f.program = programmes()[0] || ''; f.budgetLine = String((lines()[0] || {}).id || ''); }
        if (t.dataset.nr === 'program') f.budgetLine = String((lines()[0] || {}).id || '');
        renderBody();
      });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !el.hidden) close(); });
    }

    const awards = () => unique(form.budgetLines.map(l => l.award));
    const programmes = () => unique(form.budgetLines.filter(l => l.award === f.award).map(l => l.program));
    const lines = () => form.budgetLines.filter(l => l.award === f.award && l.program === f.program);
    const line = () => form.budgetLines.find(l => String(l.id) === f.budgetLine) || null;

    function derive() {
      const total = f.lines.reduce((a, l) => a + num(l.qty) * num(l.unit), 0);
      const held = f.quotes.filter(q => q.supplier.trim()).length;
      const l = line();
      const err = !f.title.trim() ? 'Give the requisition a description.'
        : !l ? 'Choose the budget line the purchase is charged to.'
        : !f.lines.some(x => x.desc.trim() && num(x.unit) > 0) ? 'Add at least one requisition line with a quantity and unit price.'
        : f.quotes.some(q => q.supplier.trim() && !(num(q.amount) > 0)) ? 'Every quotation needs the amount quoted.'
        : '';
      return { total, held, line: l, over: l && total > l.available, err };
    }

    function renderBody() {
      const option = (value, label, cur) => `<option value="${esc(value)}" ${String(value) === String(cur) ? 'selected' : ''}>${esc(label)}</option>`;
      const award = form.budgetLines.find(l => l.award === f.award) || {};
      const unrestricted = f.award.startsWith('Unrestricted');
      el.querySelector('#nr-body').innerHTML = `
        <label class="ap-f"><span>What is being bought</span><input data-nr="title" value="${esc(f.title)}" placeholder="e.g. Observer field kits, 210 sets"></label>
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
          <label class="ap-f"><span>Grant / award</span>
            <select data-nr="award">${awards().map(a => option(a, a, f.award)).join('')}</select>
            <span class="ap-note" style="font-weight:400;">Charged to ${esc(award.fund || '')} · ${esc(award.funder || '')}</span>
          </label>
          <label class="ap-f"><span>Programme</span>
            <select data-nr="program">${programmes().map(p => option(p, p, f.program)).join('')}</select>
            <span class="ap-note" style="font-weight:400;">${unrestricted ? 'Unrestricted funds may be charged to any budgeted programme.' : 'Only programmes this award funds are listed.'}</span>
          </label>
        </div>
        <label class="ap-f"><span>Budget line</span>
          <select data-nr="budgetLine">${lines().map(l => option(l.id, `${l.code} · ${l.name}`, f.budgetLine)).join('')}</select>
        </label>
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
          <label class="ap-f"><span>Requester</span>
            <select data-nr="requester">${form.people.map(p => option(p.email, p.label, f.requester)).join('')}</select>
          </label>
          <label class="ap-f"><span>Needed by</span><input type="date" class="mono" data-nr="needBy" value="${esc(f.needBy)}"></label>
        </div>
        <label class="ap-f"><span>Preferred supplier</span>
          <input data-nr="supplier" value="${esc(f.supplier)}" list="nr-suppliers" placeholder="Left blank, the selected quotation is used">
          <datalist id="nr-suppliers">${form.suppliers.map(s => `<option value="${esc(s)}">`).join('')}</datalist>
        </label>
        <div class="pq-section">
          <div class="pq-section-head"><span class="jd-caps">Requisition lines</span><button type="button" class="rt-back" data-nr-add-line>Add line</button></div>
          <div class="pq-form-line jd-caps" style="font-size:9.5px;"><span>Description</span><span style="text-align:end;">Qty</span><span style="text-align:end;">Unit</span><span style="text-align:end;">Amount</span><span></span></div>
          ${f.lines.map((l, i) => `
            <div class="pq-form-line">
              <input data-nr-line="${i}" data-key="desc" value="${esc(l.desc)}" placeholder="Item or service">
              <input class="amount" data-nr-line="${i}" data-key="qty" value="${esc(l.qty)}" inputmode="numeric">
              <input class="amount" data-nr-line="${i}" data-key="unit" value="${esc(l.unit)}" inputmode="numeric" placeholder="0">
              <span class="coa-amount" data-nr-amount="${i}">${fmt(num(l.qty) * num(l.unit))}</span>
              <span>${f.lines.length > 1 ? `<button type="button" class="jd-remove" data-nr-remove-line="${i}" aria-label="Remove line">×</button>` : ''}</span>
            </div>`).join('')}
          <div style="display:flex;align-items:center;padding:8px 2px 0;border-top:1px solid #EEEDE8;">
            <span style="font-size:12px;color:#6E7873;">Requisition value</span>
            <span class="pc-mono" id="nr-total" style="margin-inline-start:auto;font-weight:600;"></span>
          </div>
        </div>
        <div class="pq-section">
          <div class="pq-section-head"><span class="jd-caps">Quotations</span><button type="button" class="rt-back" data-nr-add-quote>Add quotation</button></div>
          ${f.quotes.map((q, i) => `
            <div class="pq-form-quote">
              <button type="button" class="pq-radio ${q.chosen ? 'on' : ''}" data-nr-choose="${i}" title="Select this quotation" aria-label="Select this quotation"></button>
              <div style="display:flex;flex-direction:column;gap:6px;min-width:0;">
                <input data-nr-quote="${i}" data-key="supplier" value="${esc(q.supplier)}" list="nr-suppliers" placeholder="Supplier name">
                <input data-nr-quote="${i}" data-key="note" value="${esc(q.note)}" placeholder="Evaluation note, e.g. lowest compliant bid">
                <div style="display:flex;align-items:center;gap:8px;">
                  <label class="pq-file">⤓ ${q.file ? 'Replace file' : 'Attach quotation'}<input type="file" data-nr-quote="${i}" hidden></label>
                  ${q.file ? `<span class="pq-file-name">${esc(q.file.name)}<button type="button" class="jd-remove" data-nr-clear-file="${i}" aria-label="Remove file">×</button></span>` : ''}
                </div>
              </div>
              <input class="amount" data-nr-quote="${i}" data-key="amount" value="${esc(q.amount)}" inputmode="numeric" placeholder="0">
              <span>${f.quotes.length > 1 ? `<button type="button" class="jd-remove" data-nr-remove-quote="${i}" aria-label="Remove quotation">×</button>` : ''}</span>
            </div>`).join('')}
          <div id="nr-quotes-short"></div>
        </div>
        <label class="ap-f"><span>Justification</span>
          <textarea data-nr="justification" rows="2" class="ar-textarea" placeholder="Why this purchase is needed, or single-source reasoning">${esc(f.justification)}</textarea>
        </label>
        <div class="pq-check" id="nr-check"></div>`;
      renderDerived();
    }

    function renderDerived() {
      const d = derive();
      const $ = (id) => el.querySelector('#' + id);
      f.lines.forEach((l, i) => { const a = el.querySelector(`[data-nr-amount="${i}"]`); if (a) a.textContent = fmt(num(l.qty) * num(l.unit)); });
      $('nr-total').textContent = fmt(d.total);
      $('nr-quotes-short').innerHTML = d.total > form.threshold && d.held < 3
        ? `<div class="pq-alert warn" style="margin-top:4px;"><span>${d.held} quotation(s) on file. Three are required above KES ${fmt(form.threshold)} — the draft can be saved, but the purchase order will need the rest or a single-source justification.</span></div>`
        : '';
      $('nr-check').innerHTML = d.line ? `
        <div class="jd-caps">Budget check</div>
        <div style="font-size:12.5px;color:#16211E;">${esc(d.line.code)} · ${esc(d.line.name)} · ${esc(d.line.program)} · ${esc(d.line.award)}</div>
        <div style="font-size:12px;color:#6E7873;">Uncommitted balance <span class="pc-mono" style="color:#16211E;">${fmt(d.line.available)}</span> of ${fmt(d.line.budget)}</div>
        ${d.over ? '<div style="font-size:12px;color:#A6412F;line-height:1.5;">This exceeds the uncommitted balance. The requisition can be drafted, but approval will be blocked until a budget revision is posted.</div>' : ''}
        <div style="font-size:11.5px;color:#7A857F;">${d.total > form.threshold ? 'Three quotations will be required before a purchase order.' : 'Below the three-quotation threshold; one quotation is enough.'}</div>` : '';
      $('nr-status').innerHTML = d.err ? `<span style="color:#A6412F;">${esc(d.err)}</span>` : '<span style="color:#8B948F;">Saved as a draft. Submitting it sends it for approval against the budget line.</span>';
    }

    async function create() {
      const d = derive();
      if (d.err) return UI.toast(d.err);
      if (saving) return;
      saving = true;
      el.querySelector('#nr-create').disabled = true;
      try {
        const files = [];
        const quotes = f.quotes.map(q => {
          const out = { supplier: q.supplier, amount: q.amount, note: q.note, chosen: q.chosen, file: '' };
          if (q.file) { out.file = files.length; files.push(q.file); }
          return out;
        });
        const payload = { title: f.title, budgetLine: f.budgetLine, requester: f.requester, needBy: f.needBy, supplier: f.supplier, justification: f.justification, lines: f.lines, quotes };
        let res;
        if (files.length) {
          const body = new FormData();
          body.append('payload', JSON.stringify(payload));
          files.forEach(file => body.append('documents[]', file));
          const response = await fetch('/api/procurement', { method: 'POST', headers: { Accept: 'application/json' }, body });
          res = await response.json().catch(() => ({}));
          if (!response.ok) throw new Error(res.error || `Request failed: ${response.status}`);
        } else {
          res = await UI.postJSON('/api/procurement', payload);
        }
        const req = res.requisition;
        close();
        UI.toast(`${req.no} created as a draft against ${req.code}.`);
        state.view = 'Requisitions'; state.tab = 'Open'; state.q = ''; state.page = 1;
        app.querySelector('#pq-q').value = '';
        await refresh();
        open(req.no);
      } catch (err) {
        el.querySelector('#nr-status').innerHTML = `<span style="color:#A6412F;">${esc(err.message)}</span>`;
      } finally {
        saving = false;
        el.querySelector('#nr-create').disabled = false;
      }
    }

    async function openDrawer() {
      if (!el) build();
      try {
        form = await UI.fetchJSON('/api/procurement/form');
      } catch (err) {
        UI.toast('The requisition form could not be loaded.');
        return;
      }
      const first = form.budgetLines.find(l => l.code === '5140') || form.budgetLines[0] || {};
      f = {
        title: '', award: first.award || '', program: first.program || '', budgetLine: String(first.id || ''),
        requester: (form.people.find(p => p.email === form.me) || form.people[0] || {}).email || '',
        needBy: '', supplier: '', justification: '',
        lines: [{ desc: '', qty: '1', unit: '' }], quotes: [{ supplier: '', amount: '', note: '', chosen: true, file: null }],
      };
      renderBody();
      el.hidden = false;
      el.querySelector('[data-nr="title"]').focus();
    }

    function close() {
      if (el) el.hidden = true;
    }

    return { open: openDrawer };
  })();
  // ---- Supplier drawer ----

  const Supplier = (() => {
    let el;
    let opts = null; // categories, withholding, ratings, statuses, can
    let f = null;
    let editing = null; // the supplier as loaded, or null when registering
    let saving = false;

    function build() {
      el = document.createElement('div');
      el.className = 'rt';
      el.hidden = true;
      el.innerHTML = `
        <div class="jd-backdrop" data-sp-close></div>
        <div class="rt-panel" style="width:520px;" role="dialog" aria-modal="true" aria-label="Supplier">
          <div class="rt-head" style="padding:16px 20px;align-items:center;">
            <div style="display:flex;flex-direction:column;gap:3px;min-width:0;">
              <div class="jd-caps" style="letter-spacing:.1em;" id="sp-caps"></div>
              <div style="font-size:15.5px;font-weight:600;letter-spacing:-.01em;" id="sp-title"></div>
            </div>
            <button type="button" class="rt-close" data-sp-close aria-label="Close">×</button>
          </div>
          <div class="rt-body pq-body" style="padding:18px 20px;gap:16px;" id="sp-body"></div>
          <div class="rt-foot" style="padding:13px 20px;background:#FBFAF7;">
            <span id="sp-status" style="font-size:12px;line-height:1.5;flex:1;min-width:0;"></span>
            <button type="button" class="btn" data-sp-close>Cancel</button>
            <button type="button" class="btn btn-primary" id="sp-save"></button>
          </div>
        </div>`;
      document.body.appendChild(el);

      el.addEventListener('click', (e) => {
        if (e.target.closest('[data-sp-close]')) return close();
        if (e.target.closest('#sp-save')) save();
      });
      el.addEventListener('input', (e) => {
        const t = e.target;
        if (!t.dataset.sp) return;
        f[t.dataset.sp] = t.value;
        if (t.dataset.sp === 'status') renderBody();
        else renderDerived();
      });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && el && !el.hidden) close(); });
    }

    function renderBody() {
      const can = opts.can;
      const option = (value, label, cur) => `<option value="${esc(value)}" ${String(value) === String(cur) ? 'selected' : ''}>${esc(label)}</option>`;
      const qualifyLocked = !can.qualify;
      const prequalified = f.status === 'prequalified';
      const pinLocked = editing && editing.billed && editing.pin;
      el.querySelector('#sp-body').innerHTML = `
        <label class="ap-f"><span>Supplier name</span><input data-sp="name" value="${esc(f.name)}" maxlength="120" placeholder="As registered, e.g. Zuri Consulting Ltd"></label>
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
          <label class="ap-f"><span>KRA PIN</span>
            <input class="mono" data-sp="pin" value="${esc(f.pin)}" maxlength="11" placeholder="P051182934C" ${pinLocked ? 'readonly' : ''}>
            ${pinLocked ? '<span class="ap-note" style="font-weight:400;">Billed against — withholding has been filed under this PIN.</span>' : ''}
          </label>
          <label class="ap-f"><span>Category</span>
            <input data-sp="category" value="${esc(f.category)}" list="sp-categories" maxlength="60" placeholder="e.g. Professional fees">
            <datalist id="sp-categories">${opts.categories.map(c => `<option value="${esc(c)}">`).join('')}</datalist>
          </label>
        </div>
        <div class="pq-section">
          <div class="pq-section-head"><span class="jd-caps">Pre-qualification</span></div>
          <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;">
            <label class="ap-f"><span>Status</span>
              <select data-sp="status" ${qualifyLocked ? 'disabled' : ''}>${Object.entries(opts.statuses).map(([k, v]) => option(k, v, f.status)).join('')}</select>
            </label>
            <label class="ap-f"><span>Pre-qualified to</span><input type="date" class="mono" data-sp="prequalUntil" value="${esc(f.prequalUntil)}" ${qualifyLocked || !prequalified ? 'disabled' : ''}></label>
            <label class="ap-f"><span>Rating</span>
              <select data-sp="rating" ${qualifyLocked || !prequalified ? 'disabled' : ''}>${option('', '—', f.rating)}${opts.ratings.map(r => option(r, r, f.rating)).join('')}</select>
            </label>
          </div>
          <div class="ap-note" style="font-weight:400;margin-top:6px;">${qualifyLocked
            ? 'An approver pre-qualifies, renews, blocks or restores a supplier. You can register it and keep its details.'
            : f.status === 'blocked' ? 'A blocked supplier cannot be ordered from or billed.'
            : prequalified ? 'Purchase orders can be placed with a supplier only while its pre-qualification is current.'
            : 'Quotations can be taken, but no purchase order is placed until the supplier is pre-qualified.'}</div>
        </div>
        <div class="pq-section">
          <div class="pq-section-head"><span class="jd-caps">Withholding tax</span></div>
          <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,2fr);gap:12px;">
            <label class="ap-f"><span>Rate %</span><input class="amount" data-sp="whtRate" value="${esc(f.whtRate)}" inputmode="decimal" placeholder="Category default"></label>
            <label class="ap-f"><span>Applies to</span><input data-sp="whtBasis" value="${esc(f.whtBasis)}" maxlength="40" placeholder="e.g. on fees"></label>
          </div>
          <div class="ap-note" style="font-weight:400;margin-top:6px;" id="sp-wht-note"></div>
        </div>
        <label class="ap-f"><span>Payment details</span>
          <textarea data-sp="paymentDetails" rows="2" class="ar-textarea" placeholder="Bank, branch and account, or M-Pesa paybill and account">${esc(f.paymentDetails)}</textarea>
        </label>
        ${editing && editing.history.length ? `
          <div class="pq-section">
            <div class="pq-section-head"><span class="jd-caps">History</span></div>
            ${editing.history.map(h => `<div style="display:flex;gap:10px;font-size:12px;line-height:1.5;"><span class="jr-date" style="font-size:11px;flex:none;width:78px;">${esc(h.when)}</span><span style="color:#28352F;">${esc(h.what)}</span></div>`).join('')}
          </div>` : ''}`;
      renderDerived();
    }

    function renderDerived() {
      const rate = opts.withholding[f.category];
      el.querySelector('#sp-wht-note').textContent = f.whtRate.trim() !== ''
        ? 'This rate replaces the spend category\'s on every bill from this supplier.'
        : rate !== undefined ? `Left blank, bills use the ${f.category} rate of ${rate}%.` : 'Left blank, bills use the rate of the spend category they are coded to.';
      const err = !f.name.trim() ? 'Name the supplier.'
        : !f.category.trim() ? 'Choose the supplier\'s category.'
        : f.pin.trim() && !/^P0\d{8}[A-Z]$/i.test(f.pin.trim()) ? 'The KRA PIN runs P0, eight digits and a letter.'
        : '';
      el.querySelector('#sp-status').innerHTML = err
        ? `<span style="color:#A6412F;">${esc(err)}</span>`
        : `<span style="color:#8B948F;">${editing ? 'Changes are recorded in the supplier\'s history.' : 'The supplier is added to the register and offered on requisitions and bills.'}</span>`;
      return err;
    }

    async function save() {
      const err = renderDerived();
      if (err) return UI.toast(err);
      if (saving) return;
      saving = true;
      const btn = el.querySelector('#sp-save');
      btn.disabled = true;
      try {
        const url = editing ? `/api/procurement/suppliers/${editing.id}` : '/api/procurement/suppliers';
        const res = await UI.postJSON(url, f);
        close();
        UI.toast(editing ? `${res.supplier.name} updated on the register.` : `${res.supplier.name} added to the supplier register as ${res.supplier.label.toLowerCase()}.`);
        await refresh();
      } catch (e) {
        el.querySelector('#sp-status').innerHTML = `<span style="color:#A6412F;">${esc(e.message)}</span>`;
      } finally {
        saving = false;
        btn.disabled = false;
      }
    }

    async function openDrawer(id) {
      if (!el) build();
      try {
        const res = await UI.fetchJSON(id ? `/api/procurement/suppliers/${id}` : '/api/procurement/suppliers/form');
        opts = res;
        editing = res.supplier || null;
      } catch (err) {
        UI.toast('The supplier could not be loaded.');
        return;
      }
      const s = editing || {};
      f = {
        name: s.name || '', pin: s.pin || '', category: s.category || '', status: s.status || 'not_prequalified',
        prequalUntil: s.prequalUntil || '', rating: s.rating || '', whtRate: s.whtRate || '', whtBasis: s.whtBasis || '', paymentDetails: s.paymentDetails || '',
      };
      el.querySelector('#sp-caps').textContent = editing ? 'Supplier register' : 'New supplier';
      el.querySelector('#sp-title').textContent = editing ? editing.name : 'Register a supplier';
      const save = el.querySelector('#sp-save');
      save.textContent = editing ? 'Save changes' : 'Add supplier';
      save.hidden = !opts.can.register;
      renderBody();
      el.querySelector('#sp-body').querySelectorAll('input, select, textarea').forEach(i => { if (!opts.can.register) i.disabled = true; });
      el.hidden = false;
      if (opts.can.register) el.querySelector('[data-sp="name"]').focus();
    }

    function close() {
      if (el) el.hidden = true;
    }

    return { open: openDrawer };
  })();

  shell();
  await refresh();

  const openNo = app.getAttribute('data-open');
  if (openNo) open(openNo);
})();
