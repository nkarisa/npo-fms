(async function () {
  const app = document.getElementById('app');
  let state = { view: 'Requisitions', tab: 'Open', q: '' };

  async function load() {
    const p = new URLSearchParams({ view: state.view, tab: state.tab, q: state.q });
    return UI.fetchJSON('/api/procurement?' + p.toString());
  }

  function render(data) {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Accounting',
      title: 'Procurement',
      blurb: 'Requisition through to bill, with the budget, quotation and approval controls a donor audit expects.',
    });
    app.appendChild(UI.statGrid(data.stats));

    const card = document.createElement('div');
    card.className = 'card';

    card.appendChild(UI.tabs(
      data.viewOptions.map(v => ({ label: v })),
      state.view,
      (label) => { state.view = label; state.tab = 'Open'; refresh(); },
    ));

    if (state.view === 'Requisitions' || state.view === 'Suppliers') {
      const toolbar = document.createElement('div');
      toolbar.className = 'toolbar';
      const search = document.createElement('input');
      search.type = 'search';
      search.placeholder = state.view === 'Suppliers' ? 'Search supplier or PIN…' : 'Search requisition, requester or programme…';
      search.value = state.q;
      search.addEventListener('input', (e) => { state.q = e.target.value; refresh(); });
      toolbar.append(search);
      card.appendChild(toolbar);
    }

    if (state.view === 'Requisitions') {
      card.appendChild(UI.tabs(data.tabs, state.tab, (label) => { state.tab = label; refresh(); }));
    }

    const cols = columnsFor(state.view);
    if (data.rows.length) {
      card.appendChild(UI.table(cols, data.rows, state.view === 'Suppliers' ? null : (r) => openRequisition(r.no)));
    } else {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = 'Nothing here under the current filters.';
      card.appendChild(empty);
    }

    const footer = document.createElement('div');
    footer.className = 'card-footer';
    footer.textContent = state.view === 'Requisitions'
      ? `${data.rows.length} of ${data.total} requisitions · three quotations required above ${UI.fmtMoney(data.threshold)}`
      : `${data.rows.length} of ${data.total}`;
    card.appendChild(footer);
    app.appendChild(card);

    if (state.view === 'Requisitions') renderBudgetLines();
  }

  function columnsFor(view) {
    if (view === 'Purchase orders') {
      return [
        { label: 'PO', key: 'po' },
        { label: 'Raised', key: 'poDate' },
        { label: 'Requisition', key: 'no' },
        { label: 'Description', key: 'title' },
        { label: 'Supplier', key: 'supplier' },
        { label: 'Expected', key: 'expected' },
        { label: 'Status', render: (r) => UI.badge(r.status, r.billed ? 'calm' : r.received ? 'warn' : 'plain') },
        { label: 'Value', num: true, key: 'amount' },
      ];
    }
    if (view === 'Goods received') {
      return [
        { label: 'GRN', key: 'grn' },
        { label: 'Received', key: 'grnDate' },
        { label: 'PO', key: 'po' },
        { label: 'Description', key: 'title' },
        { label: 'Supplier', key: 'supplier' },
        { label: 'Taken by', key: 'receivedBy' },
        { label: 'Billed', render: (r) => (r.billed ? UI.badge(r.bill, 'calm') : UI.badge('Not billed', 'warn')) },
        { label: 'Value', num: true, key: 'amount' },
      ];
    }
    if (view === 'Suppliers') {
      return [
        { label: 'Supplier', key: 'name' },
        { label: 'KRA PIN', key: 'pin' },
        { label: 'Category', key: 'category' },
        { label: 'Pre-qualified to', key: 'prequalUntil' },
        { label: 'Rating', key: 'rating' },
        { label: 'Withholding', key: 'wht' },
        { label: 'Status', render: (r) => UI.badge(r.status, r.eligible ? 'calm' : r.status === 'Expiring' ? 'warn' : 'urgent') },
        { label: 'Spend', num: true, key: 'spendFmt' },
      ];
    }
    return [
      { label: 'Requisition', key: 'no' },
      { label: 'Description', render: (r) => `${UI.esc(r.title)}<div class="muted" style="font-size:11px;">${UI.esc(r.requester)}</div>` },
      { label: 'Programme', key: 'program' },
      { label: 'Account', key: 'code' },
      { label: 'Need by', key: 'needBy' },
      {
        label: 'Controls',
        // The two things that stop this reaching a PO, shown on the row itself.
        render: (r) => (r.overBudget ? UI.badge('Over budget', 'urgent') : '')
          + (r.needsQuotes ? ' ' + UI.badge(`${r.quotes} of 3 quotes`, 'warn') : ''),
      },
      { label: 'Status', render: (r) => UI.badge(r.status, statusTone(r.status)) },
      { label: 'Available', num: true, key: 'available' },
      { label: 'Value', num: true, key: 'amount' },
    ];
  }

  function statusTone(status) {
    if (status === 'Closed') return 'calm';
    if (status === 'Rejected') return 'urgent';
    if (status === 'Awaiting approval') return 'warn';
    return 'plain';
  }

  async function renderBudgetLines() {
    const data = await UI.fetchJSON('/api/procurement/budget-lines');
    const card = document.createElement('div');
    card.className = 'card';
    card.innerHTML = `<div class="card-head"><span class="card-title">Budget lines</span></div>`;
    card.appendChild(UI.table([
      { label: 'Account', key: 'code' },
      { label: 'Line', key: 'name' },
      { label: 'Consumed', render: (r) => UI.bar(r.pct, r.exhausted ? 'urgent' : r.pct > 80 ? 'warn' : 'calm') },
      { label: 'Budget', num: true, key: 'budget' },
      { label: 'Actual', num: true, key: 'spent' },
      { label: 'Committed', num: true, key: 'committed' },
      { label: 'Available', num: true, render: (r) => `<span style="${r.exhausted ? 'color:#A6412F;font-weight:600;' : ''}">${UI.esc(r.available)}</span>` },
    ], data.rows));
    const footer = document.createElement('div');
    footer.className = 'card-footer';
    footer.textContent = data.note;
    card.appendChild(footer);
    app.appendChild(card);
  }

  async function openRequisition(no) {
    const r = await UI.fetchJSON('/api/procurement/' + encodeURIComponent(no));

    const facts = [
      ['Requester', r.requester], ['Programme', r.program], ['Fund', r.fund],
      ['Account', r.code], ['Raised', r.raised], ['Need by', r.needBy],
      ['Status', r.status], ['Value', UI.fmtMoney(r.amount)],
      ['Budget', UI.fmtMoney(r.budget)], ['Actual', UI.fmtMoney(r.spent)],
      ['Committed', UI.fmtMoney(r.committed)], ['Available', UI.fmtMoney(r.available)],
    ];
    if (r.supplier) facts.push(['Supplier', r.supplier]);
    if (r.po) facts.push(['Purchase order', `${r.po} · ${r.poDate}`]);
    if (r.grn) facts.push(['Goods received', `${r.grn} · ${r.grnDate}`]);
    if (r.bill) facts.push(['Bill', r.bill]);

    const lines = r.lines.map(l => `<div class="detail-row"><span class="k">${UI.esc(l.desc)}<div class="muted" style="font-size:11px;">${l.qty} × ${UI.fmtMoney(l.unit)}</div></span><span class="v">${UI.fmtMoney(l.amount)}</span></div>`).join('');
    const quotes = r.quotes.length
      ? r.quotes.map(q => `<div class="detail-row"><span class="k">${UI.esc(q.supplier)} ${q.chosen ? UI.badge('Selected', 'calm') : ''}<div class="muted" style="font-size:11px;">${UI.esc(q.note || q.file || '')}</div></span><span class="v">${UI.fmtMoney(q.amount)}</span></div>`).join('')
      : '<div class="empty-state" style="padding:16px;">No quotations recorded.</div>';
    const trail = r.trail.map(t => `<div class="detail-row"><span class="k">${UI.esc(t.when)}</span><span class="v" style="font-weight:400;text-align:left;">${UI.esc(t.what)}</span></div>`).join('');

    const warnings = [
      r.overBudget ? `<div style="background:#FBEAE5;color:#A6412F;padding:10px 12px;border-radius:6px;font-size:11.5px;line-height:1.5;margin-bottom:8px;">Over available budget. ${UI.esc(r.title)} asks ${UI.fmtMoney(r.amount)} against ${UI.esc(r.code)}, which has ${UI.fmtMoney(r.available)} available. Approval is blocked until a revision or reallocation.</div>` : '',
      r.needsQuotes ? `<div style="background:#FBF1E1;color:#8A5B2E;padding:10px 12px;border-radius:6px;font-size:11.5px;line-height:1.5;margin-bottom:8px;">${r.quotes.length} of 3 quotations on file. A purchase order needs the missing quotations or a documented single-source waiver.</div>` : '',
    ].join('');

    UI.drawer(`${r.no} · ${r.title}`, `
      <div style="padding:14px 18px;">
        ${warnings}
        ${facts.map(([k, v]) => `<div class="detail-row"><span class="k">${UI.esc(k)}</span><span class="v">${UI.esc(v)}</span></div>`).join('')}
        <div style="margin-top:16px;font-weight:600;font-size:12.5px;">Lines</div>${lines}
        <div style="margin-top:16px;font-weight:600;font-size:12.5px;">Quotations</div>${quotes}
        <div style="margin-top:16px;font-weight:600;font-size:12.5px;">Audit trail</div>${trail}
      </div>
      ${actionsFor(r)}`);

    wireActions(r);
  }

  /** Only the one transition the requisition is actually at is offered. */
  function actionsFor(r) {
    const field = (id, ph, value) => `<input id="${id}" placeholder="${ph}" value="${value || ''}" style="width:100%;box-sizing:border-box;margin-bottom:8px;border:1px solid #DDDAD2;border-radius:6px;padding:7px 8px;font-size:12px;">`;
    const button = (id, label) => `<button id="${id}" style="border:1px solid #0F5C4A;background:#0F5C4A;color:#fff;border-radius:6px;padding:7px 14px;font-size:12px;font-weight:600;cursor:pointer;">${label}</button>`;
    let inner = '';

    if (r.canApprove) {
      inner = `<div class="muted" style="font-size:11px;margin-bottom:8px;">The requester cannot approve their own requisition.</div>`
        + field('pq-approver', 'Approver', 'W. Kamau') + button('pq-approve', 'Approve');
    } else if (r.canRaisePo) {
      inner = field('pq-supplier', 'Supplier', r.supplier === 'Not yet selected' ? '' : r.supplier)
        + (r.needsQuotes ? field('pq-waiver', 'Single-source waiver reference') : '')
        + button('pq-po', 'Raise purchase order');
    } else if (r.canReceive) {
      inner = field('pq-by', 'Received by', 'Store · A. Kariuki')
        + field('pq-note', 'Condition on receipt')
        + button('pq-receive', 'Post goods received note');
    } else {
      return '';
    }

    return `<div style="border-top:1px solid #E4E2DB;padding:14px 18px;background:#FBFAF7;">${inner}</div>`;
  }

  function wireActions(r) {
    const run = async (btnId, url, payload) => {
      const btn = document.getElementById(btnId);
      if (!btn) return;
      btn.addEventListener('click', async () => {
        btn.disabled = true;
        try {
          await UI.postJSON(url, payload());
          UI.closeDrawer();
          UI.toast('Done');
          refresh();
        } catch (err) {
          // The API's reason is the message worth showing.
          UI.toast(err.message);
          btn.disabled = false;
        }
      });
    };

    const base = '/api/procurement/' + encodeURIComponent(r.no);
    run('pq-approve', `${base}/approve`, () => ({ approver: document.getElementById('pq-approver').value }));
    run('pq-po', `${base}/purchase-order`, () => ({
      supplier: document.getElementById('pq-supplier').value,
      waiver: (document.getElementById('pq-waiver') || {}).value || '',
    }));
    run('pq-receive', `${base}/receive`, () => ({
      receivedBy: document.getElementById('pq-by').value,
      note: document.getElementById('pq-note').value,
    }));
  }

  async function refresh() {
    render(await load());
  }

  refresh();
})();
