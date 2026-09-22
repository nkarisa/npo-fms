/**
 * Funds (v5).
 *
 * The statement of changes in funds for the working year — opening, income,
 * expenditure, transfers, closing — one row a fund, with the fund drawer
 * (movement, utilisation, restriction terms, programmes, ledger accounts) and the
 * inter-fund transfer. A transfer is a journal: it is raised here and posted when
 * the approver the inter-fund transfer rule names approves it in Journals, so the
 * balances on this screen only ever show what the ledger holds.
 */
(async function () {
  const app = document.getElementById('app');
  const esc = UI.esc;
  const params = new URLSearchParams(location.search);
  const CLASSES = ['All', 'Unrestricted', 'Restricted', 'Endowment'];
  const state = { cls: CLASSES.includes(params.get('filter')) ? params.get('filter') : 'All', q: '' };
  let data = null;

  const pill = (cls) => `<span class="fd-pill ${esc(cls.toLowerCase())}">${esc(cls)}</span>`;

  async function refresh() {
    const p = new URLSearchParams({ class: state.cls, q: state.q });
    data = await UI.fetchJSON('/api/funds?' + p.toString());
    render();
  }

  // ---- The register ----

  function shell() {
    app.innerHTML = `
      <div class="page-head" style="margin-bottom:0;">
        <div>
          <h1 class="page-title" style="margin-top:0;">Funds</h1>
          <p class="page-blurb" style="max-width:660px;">Every shilling sits in a fund with its own restriction. Restricted balances can only be spent on the purpose the donor agreed, and unspent amounts are returnable at grant close.</p>
        </div>
        <div class="page-actions" style="margin-left:0;margin-inline-start:auto;">
          <button type="button" class="btn" id="fd-transfer">Fund transfer</button>
          <button type="button" class="btn btn-primary" id="fd-statement">Statement of funds</button>
        </div>
      </div>
      <div class="stat-grid" id="fd-stats" style="margin:18px 0 0;"></div>
      <div class="jr-filters">
        <div class="coa-seg" id="fd-tabs"></div>
        <label class="coa-search" style="flex:1 1 220px;min-width:190px;max-width:300px;width:auto;">⌕
          <input type="search" id="fd-q" placeholder="Fund, funder or grant reference">
        </label>
        <div class="jr-hint" id="fd-hint"></div>
      </div>
      <div class="coa-card">
        <div style="overflow-x:auto;"><div style="min-width:1400px;" id="fd-table"></div></div>
        <div class="coa-foot">
          <span id="fd-footer"></span>
          <span style="margin-inline-start:auto;">Transfers between funds require board minute reference · restricted funds cannot subsidise core costs</span>
        </div>
      </div>`;

    let searchTimer;
    app.querySelector('#fd-q').addEventListener('input', (e) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => { state.q = e.target.value; refresh(); }, 200);
    });
    app.querySelector('#fd-tabs').addEventListener('click', (e) => {
      const tab = e.target.closest('[data-cls]');
      if (!tab) return;
      state.cls = tab.dataset.cls;
      refresh();
    });
    app.querySelector('#fd-table').addEventListener('click', (e) => {
      const row = e.target.closest('[data-fund]');
      if (row) openFund(row.dataset.fund);
    });
    app.querySelector('#fd-table').addEventListener('keydown', (e) => {
      const row = e.target.closest('[data-fund]');
      if (row && e.key === 'Enter') openFund(row.dataset.fund);
    });
    app.querySelector('#fd-transfer').addEventListener('click', () => openTransfer());
    app.querySelector('#fd-statement').addEventListener('click', exportStatement);
  }

  function render() {
    if (!app.querySelector('#fd-table')) shell();

    app.querySelector('#fd-stats').replaceWith(Object.assign(UI.statGrid(data.stats), { id: 'fd-stats', style: 'margin:18px 0 0;' }));
    app.querySelector('#fd-tabs').innerHTML = data.tabs.map((t) => `
      <button type="button" class="coa-seg-btn ${t.key === state.cls ? 'on' : ''}" data-cls="${esc(t.key)}">${esc(t.label)}</button>`).join('');
    app.querySelector('#fd-hint').textContent = data.hint;
    app.querySelector('#fd-footer').textContent = data.footer;

    const head = ['Fund', 'Class', 'Funder', ['Opening', 'end'], ['Income', 'end'], ['Expenditure', 'end'], ['Transfers', 'end'],
      ['Closing', 'end'], 'Utilisation', ['Spend by', 'end']]
      .map((h) => Array.isArray(h) ? `<div style="text-align:end;">${esc(h[0])}</div>` : `<div>${esc(h)}</div>`).join('');

    const rows = data.rows.map((f) => `
      <div class="fd-grid fd-row" data-fund="${esc(f.code)}" tabindex="0">
        <div class="fd-who"><span>${esc(f.name)}</span><span>${esc(f.purpose)}</span></div>
        <div>${pill(f.cls)}</div>
        <div class="fd-plain">${esc(f.funder)}</div>
        <div class="fd-mono quiet">${esc(f.opening)}</div>
        <div class="fd-mono">${esc(f.income)}</div>
        <div class="fd-mono">${esc(f.spend)}</div>
        <div class="fd-mono quiet">${esc(f.transfers)}</div>
        <div class="fd-mono lead ${f.overdrawn ? 'over' : ''}">${esc(f.closing)}</div>
        <div class="fd-util">
          <span class="fd-track"><span style="width:${f.pct}%;background:${esc(f.barColour)};"></span></span>
          <span>${f.pct}%</span>
        </div>
        <div class="fd-spend ${f.expiringSoon ? 'soon' : ''}">${esc(f.spendBy)}</div>
      </div>`).join('');

    app.querySelector('#fd-table').innerHTML = `
      <div class="fd-grid coa-head">${head}</div>
      ${rows || '<div class="empty-state">No funds match this view.</div>'}
      <div class="fd-grid fd-total">
        <div class="fd-total-label">Total funds carried forward</div><div></div><div></div>
        <div class="fd-mono">${esc(data.totals.opening)}</div>
        <div class="fd-mono">${esc(data.totals.income)}</div>
        <div class="fd-mono">${esc(data.totals.spend)}</div>
        <div class="fd-mono">${esc(data.totals.transfers)}</div>
        <div class="fd-mono lead">${esc(data.totals.closing)}</div>
        <div></div><div></div>
      </div>`;
  }

  // ---- One fund ----

  let detail = null;

  async function openFund(code) {
    try {
      detail = await UI.fetchJSON('/api/funds/' + encodeURIComponent(code));
    } catch (err) {
      UI.toast(err.message);
      return;
    }
    const d = detail;
    const line = (label, value, cls) => `<div class="fd-move-line ${cls || ''}"><span>${esc(label)}</span><span>${esc(value)}</span></div>`;
    const term = (label, value, wide) => `<div class="fd-term ${wide ? 'wide' : ''}"><span>${esc(label)}</span><span>${esc(value)}</span></div>`;

    const open = d.openTransfers.length ? `
      <div>
        <div class="pg-section-label">Transfers not yet posted</div>
        ${d.openTransfers.map((t) => `
          <a class="fd-open" href="/journals/${encodeURIComponent(t.ref)}">
            <span>${esc(t.ref)} · ${esc(t.status)}</span>
            <span>${esc(t.from)} → ${esc(t.to)} · board minute ${esc(t.minute)}</span>
          </a>`).join('')}
      </div>` : '';

    UI.drawer(`${d.code} · ${d.name}`, `
      <div class="pg-head">
        <div style="display:flex;align-items:center;gap:9px;">${pill(d.cls)}<span class="fd-code">${esc(d.code)}</span></div>
        <b>${esc(d.name)}</b>
        <small>${esc(d.purpose)}</small>
      </div>
      <div class="pg-body">
        ${d.alert ? `<div class="fd-alert">${esc(d.alert)}</div>` : ''}
        <div>
          <div class="pg-section-label">Movement for the year${d.year ? ' · ' + esc(d.year) : ''}</div>
          <div class="fd-move">
            ${line('Opening balance', d.opening)}
            ${line('Income received', d.income)}
            ${line('Expenditure', d.spend === '—' ? '—' : '(' + d.spend + ')', 'out')}
            ${line('Transfers in / (out)', d.transfers)}
            ${line('Closing balance', d.closing, 'close')}
          </div>
        </div>
        <div>
          <div class="fd-util-head">
            <div class="pg-section-label" style="margin:0;">Utilisation</div>
            <span>${d.pct}% of available funds spent</span>
          </div>
          <span class="fd-track big"><span style="width:${d.pct}%;background:${esc(d.barColour)};"></span></span>
          <div class="pg-hint">${esc(d.remaining)} remaining against ${esc(d.available)} available.</div>
        </div>
        <div>
          <div class="pg-section-label">Restriction terms</div>
          <div class="fd-terms">
            ${term('Funder', d.funder)}${term('Grant reference', d.grant)}
            ${term('Agreement period', d.period)}${term('Spend by', d.spendBy)}
            ${term('Conditions', d.conditions, true)}
          </div>
        </div>
        ${d.programs.length ? `
        <div>
          <div class="pg-section-label">Programmes charged to this fund</div>
          <div class="pg-chips">${d.programs.map((p) => `<span>${esc(p)}</span>`).join('')}</div>
        </div>` : ''}
        <div>
          <div class="pg-section-label">Linked ledger accounts</div>
          ${d.accounts.length ? `<div class="fd-accounts">${d.accounts.map((a) => `
            <a href="/gl?account=${encodeURIComponent(a.code)}"><span>${esc(a.code)}</span><span>${esc(a.name)}</span><span>${esc(a.balance)}</span></a>`).join('')}</div>`
            : '<div class="pg-note">Nothing has been posted to this fund this year.</div>'}
        </div>
        ${open}
      </div>
      <div class="pg-foot">
        <button type="button" class="btn" data-do="transfer">Fund transfer</button>
        <button type="button" class="btn pg-close" data-do="close">Close</button>
        <a class="btn btn-primary" href="/donor-reports">Generate donor report</a>
      </div>`, { wide: true });

    document.querySelector('[data-do="close"]').addEventListener('click', UI.closeDrawer);
    document.querySelector('[data-do="transfer"]').addEventListener('click', () => { UI.closeDrawer(); openTransfer(d.code); });
  }

  // ---- The inter-fund transfer ----

  let form = null;

  function openTransfer(from) {
    const t = data.transfer;
    if (!t.canRaise) {
      UI.toast('The ' + t.role + ' cannot raise a transfer. It is a journal, so it is prepared by someone who prepares journals.');
      return;
    }
    const start = from && from !== t.to ? from : t.from;
    form = { from: start, to: start === t.to ? t.from : t.to, amount: '', minute: '', reason: '' };
    drawTransfer();
  }

  const amountOf = (v) => parseFloat(String(v || '').replace(/[^0-9.]/g, '')) || 0;
  const fundOf = (code) => data.transfer.funds.find((f) => f.code === code) || null;

  /** The same refusals the API gives, said while the form is filled in. */
  function blocked() {
    const from = fundOf(form.from);
    const to = fundOf(form.to);
    const amount = amountOf(form.amount);
    if (!from || !to) return 'Choose the fund the money leaves and the fund it goes to.';
    if (from.code === to.code) return 'Source and destination funds must differ.';
    if (from.restriction === 'restricted') {
      return from.name + ' is donor-restricted. Funds cannot be transferred out without written consent from ' + from.funder + ' — record the consent as a grant amendment first.';
    }
    if (from.restriction === 'endowment') return 'Endowment capital is permanently maintained. Only realised investment income may be released, through the General Fund.';
    if (amount > 0 && amount > from.transferable + 0.005) {
      return 'Transfer exceeds the available balance of ' + UI.fmtMoney(from.transferable) + ' in ' + from.name
        + (from.pending > 0.005 ? ', after ' + UI.fmtMoney(from.pending) + ' already awaiting approval' : '') + '.';
    }
    return '';
  }

  /** The check under the form: why the transfer cannot be made, or what it will do. */
  function check() {
    const block = blocked();
    const amount = amountOf(form.amount);
    if (block) return `<div class="fd-alert">${esc(block)}</div>`;
    if (!(amount > 0)) return '';
    const from = fundOf(form.from);
    const to = fundOf(form.to);
    return `<div class="pg-note ok">Permitted. ${esc(UI.fmtMoney(amount))} will move from ${esc(from.name)} to ${esc(to.name)}, leaving `
      + `${esc(UI.fmtMoney(from.transferable - amount))} available. It posts when the ${esc(data.transfer.approver)} approves it in Journals.</div>`;
  }

  function drawTransfer() {
    const options = (current) => data.transfer.funds.map((f) => `<option value="${esc(f.code)}" ${f.code === current ? 'selected' : ''}>${esc(f.name)}</option>`).join('');

    modal(`
      <div class="pg-form-pair even">
        <label class="as-field"><span>Transfer from</span><select data-f="from">${options(form.from)}</select></label>
        <label class="as-field"><span>Transfer to</span><select data-f="to">${options(form.to)}</select></label>
      </div>
      <div class="pg-form-pair even">
        <label class="as-field"><span>Amount (KES)</span>
          <input data-f="amount" value="${esc(form.amount)}" placeholder="0" inputmode="numeric" style="font-family:'IBM Plex Mono',monospace;text-align:end;"></label>
        <label class="as-field"><span>Board minute reference</span>
          <input data-f="minute" value="${esc(form.minute)}" placeholder="BM/2026/08/04" maxlength="40"></label>
      </div>
      <label class="as-field"><span>Reason for transfer</span>
        <textarea data-f="reason" rows="2" placeholder="Why this movement is permitted under the funding agreement">${esc(form.reason)}</textarea></label>
      <div id="fd-transfer-check">${check()}</div>`);

    // Only the check is redrawn as the form is filled in, so focus and typing are never interrupted.
    const el = document.getElementById('fd-transfer-modal');
    el.querySelectorAll('[data-f]').forEach((input) => input.addEventListener(input.tagName === 'SELECT' ? 'change' : 'input', () => {
      form[input.dataset.f] = input.value;
      el.querySelector('#fd-transfer-check').innerHTML = check();
    }));
    el.querySelector('[data-submit]').addEventListener('click', (e) => submitTransfer(e.target));
  }

  async function submitTransfer(button) {
    const block = blocked();
    if (block) { UI.toast(block); return; }
    if (!amountOf(form.amount)) { UI.toast('Enter an amount to transfer.'); return; }
    if (!form.minute.trim()) { UI.toast('A board minute reference is required for inter-fund transfers.'); return; }

    button.disabled = true;
    try {
      const result = await UI.postJSON('/api/funds/transfers', {
        from: form.from, to: form.to, amount: amountOf(form.amount), minute: form.minute.trim(), reason: form.reason.trim(),
      });
      closeModal();
      UI.toast(result.message);
      await refresh();
    } catch (err) {
      UI.toast(err.message);
      button.disabled = false;
    }
  }

  // ---- The statement of funds ----

  async function exportStatement() {
    try {
      const res = await fetch('/api/funds/statement');
      if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'The statement could not be produced.');
      UI.download(await res.blob(), UI.brand() + ' statement of funds.csv', res.headers.get('Content-Disposition'));
      UI.toast('Statement of funds for ' + (data.year || 'the year') + ' exported.');
    } catch (err) {
      UI.toast(err.message);
    }
  }

  // ---- The modal shell ----

  let modalEl = null;

  function modal(body) {
    closeModal();
    modalEl = document.createElement('div');
    modalEl.className = 'ap-modal';
    modalEl.id = 'fd-transfer-modal';
    modalEl.innerHTML = `
      <div class="ap-modal-scrim" data-close></div>
      <div class="ap-modal-box" role="dialog" aria-modal="true" aria-label="Move funds between balances" style="width:520px;">
        <div class="jd-head" style="padding:16px 20px;border-bottom-color:#E4E2DB;">
          <div style="display:flex;flex-direction:column;gap:3px;">
            <div class="jd-caps" style="letter-spacing:.1em;">Inter-fund transfer</div>
            <div style="font-size:16px;font-weight:600;letter-spacing:-.01em;">Move funds between balances</div>
          </div>
          <button type="button" class="rt-close" data-close aria-label="Close">×</button>
        </div>
        <div class="ap-modal-body">${body}</div>
        <div class="ap-modal-foot">
          <div class="pg-modal-note"></div>
          <button type="button" class="btn" data-close style="height:34px;padding:0 14px;">Cancel</button>
          <button type="button" class="btn btn-primary" data-submit style="height:34px;padding:0 18px;">Post transfer</button>
        </div>
      </div>`;
    document.body.appendChild(modalEl);
    modalEl.addEventListener('click', (e) => { if (e.target.closest('[data-close]')) closeModal(); });
  }

  function closeModal() {
    if (modalEl) modalEl.remove();
    modalEl = null;
  }

  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

  await refresh();
  if (params.get('fund')) await openFund(params.get('fund'));
})();
