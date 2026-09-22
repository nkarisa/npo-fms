/**
 * Grants and awards (v5).
 *
 * The award portfolio from proposal to close-out. Burn is read against elapsed
 * time, so an award behind schedule shows before the funder asks. The drawer
 * holds one award's budget against actual, disbursements, reporting calendar and
 * conditions; "Record award" walks through the signed agreement in six steps and
 * writes nothing until the last. Every rule the steps apply, the API applies again.
 */
(async function () {
  const app = document.getElementById('app');
  const esc = UI.esc;
  const params = new URLSearchParams(location.search);
  const STATUSES = ['All', 'Active', 'Closing', 'Pipeline', 'Suspended', 'Closed'];
  const state = { status: STATUSES.includes(params.get('status')) ? params.get('status') : 'All', q: '' };
  let data = null;

  const pill = (status) => `<span class="gr-pill ${esc(String(status).toLowerCase().replace(/\s+/g, '-'))}">${esc(status)}</span>`;
  const burnBar = (burn, elapsed, colour, big) => `
    <span class="gr-burn ${big ? 'big' : ''}">
      <span style="width:${burn}%;background:${esc(colour)};"></span>
      <i style="inset-inline-start:min(calc(${elapsed}% - 1px), calc(100% - 2px));"></i>
    </span>`;

  async function refresh() {
    const p = new URLSearchParams({ status: state.status, q: state.q });
    data = await UI.fetchJSON('/api/grants?' + p.toString());
    render();
  }

  // ---- The register ----

  function shell() {
    app.innerHTML = `
      <div class="page-head" style="margin-bottom:0;">
        <div>
          <h1 class="page-title" style="margin-top:0;">Grants and awards</h1>
          <p class="page-blurb" style="max-width:660px;">The award portfolio from proposal to close-out. Burn rate is read against elapsed time, so a grant that is behind schedule shows before the funder asks.</p>
        </div>
        <div class="page-actions" style="margin-left:0;margin-inline-start:auto;">
          <button type="button" class="btn" id="gr-calendar">Reporting calendar</button>
          <button type="button" class="btn btn-primary" id="gr-new">+ Record award</button>
        </div>
      </div>
      <div class="stat-grid" id="gr-stats" style="margin:18px 0 0;"></div>
      <div class="jr-filters">
        <div class="coa-seg" id="gr-tabs"></div>
        <label class="coa-search" style="flex:1 1 220px;min-width:190px;max-width:300px;width:auto;">⌕
          <input type="search" id="gr-q" placeholder="Award, funder or programme">
        </label>
        <div class="gr-legend">
          <span><span class="gr-legend-spent"></span>Spent</span>
          <span><span class="gr-legend-time"></span>Time elapsed</span>
        </div>
      </div>
      <div class="coa-card">
        <div style="overflow-x:auto;"><div style="min-width:1380px;" id="gr-table"></div></div>
        <div class="coa-foot">
          <span id="gr-footer"></span>
          <span style="margin-inline-start:auto;">Values stated in KES at the agreement rate · pipeline awards excluded from committed income</span>
        </div>
      </div>`;

    let searchTimer;
    app.querySelector('#gr-q').addEventListener('input', (e) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => { state.q = e.target.value; refresh(); }, 200);
    });
    app.querySelector('#gr-tabs').addEventListener('click', (e) => {
      const tab = e.target.closest('[data-status]');
      if (!tab) return;
      state.status = tab.dataset.status;
      refresh();
    });
    const table = app.querySelector('#gr-table');
    table.addEventListener('click', (e) => {
      const row = e.target.closest('[data-ref]');
      if (row) openAward(row.dataset.ref);
    });
    table.addEventListener('keydown', (e) => {
      const row = e.target.closest('[data-ref]');
      if (row && e.key === 'Enter') openAward(row.dataset.ref);
    });
    app.querySelector('#gr-calendar').addEventListener('click', openCalendar);
    app.querySelector('#gr-new').addEventListener('click', openWizard);
  }

  function render() {
    if (!app.querySelector('#gr-table')) shell();

    app.querySelector('#gr-stats').replaceWith(Object.assign(UI.statGrid(data.stats), { id: 'gr-stats', style: 'margin:18px 0 0;' }));
    app.querySelector('#gr-tabs').innerHTML = data.tabs.map((t) => `
      <button type="button" class="coa-seg-btn ${t.key === state.status ? 'on' : ''}" data-status="${esc(t.key)}">${esc(t.label)}</button>`).join('');
    app.querySelector('#gr-footer').textContent = data.footer;

    const head = ['Award', 'Funder', 'Period', ['Award value'], ['Received'], ['Spent'], 'Burn vs elapsed', 'Next report', 'Status']
      .map((h) => Array.isArray(h) ? `<div style="text-align:end;">${esc(h[0])}</div>` : `<div>${esc(h)}</div>`).join('');

    const rows = data.rows.map((g) => `
      <div class="gr-grid gr-row" data-ref="${esc(g.ref)}" tabindex="0">
        <div class="gr-who"><span>${esc(g.title)}</span><span>${esc(g.ref)} · ${esc(g.program)}</span></div>
        <div class="gr-plain">${esc(g.funder)}</div>
        <div class="gr-period">${esc(g.period)}</div>
        <div class="gr-mono lead">${esc(g.value)}</div>
        <div class="gr-mono">${esc(g.received)}</div>
        <div class="gr-mono">${esc(g.spent)}</div>
        <div class="gr-burn-cell">${burnBar(g.burnPct, g.elapsed, g.burnColour)}<span>${g.burnPct}%</span></div>
        <div class="gr-next ${g.reportDue ? 'due' : ''}">${esc(g.nextReport)}</div>
        <div>${pill(g.status)}</div>
      </div>`).join('');

    app.querySelector('#gr-table').innerHTML = `<div class="gr-grid coa-head">${head}</div>${rows || '<div class="empty-state">No awards match this view.</div>'}`;
  }

  // ---- One award ----

  async function openAward(ref) {
    let d;
    try {
      d = await UI.fetchJSON('/api/grants/' + ref.split('/').map(encodeURIComponent).join('/'));
    } catch (err) {
      UI.toast(err.message);
      return;
    }
    const card = (label, value, lead) => `<div class="gr-card"><span>${esc(label)}</span><span class="${lead ? 'lead' : ''}">${esc(value)}</span></div>`;
    const terms = [
      d.currencyNote ? ['Agreement currency', d.currencyNote] : null,
      ['Agreement period', d.period],
      ['Held in', d.fund + (d.fundCode ? ' · ' + d.fundCode : '')],
      d.indirectCap ? ['Indirect cost cap', d.indirectCap] : null,
      ['Records retained', d.retention],
    ].filter(Boolean);

    UI.drawer(`${d.ref} · ${d.title}`, `
      <div class="pg-head">
        <div style="display:flex;align-items:center;gap:9px;"><span class="fd-code" style="font-family:'IBM Plex Mono',monospace;">${esc(d.ref)}</span>${pill(d.status)}</div>
        <b>${esc(d.title)}</b>
        <small>${esc(d.funder)} · ${esc(d.program)} · managed by ${esc(d.manager)}</small>
      </div>
      <div class="pg-body">
        ${d.alert ? `<div class="fd-alert">${esc(d.alert)}</div>` : ''}
        <div class="gr-cards">${card('Award value', d.value)}${card('Received', d.received)}${card('Unspent commitment', d.unspent, true)}</div>
        <div>
          <div class="fd-util-head">
            <div class="pg-section-label" style="margin:0;">Burn against elapsed time</div>
            <span>${d.burnPct}% spent · ${d.elapsed}% of the period gone</span>
          </div>
          ${burnBar(d.burnPct, d.elapsed, d.burnColour, true)}
          <div class="pg-hint">${esc(d.burnNote)}</div>
        </div>
        <div>
          <div class="pg-section-label">Budget against actual</div>
          <div class="gr-budget">
            <div class="gr-budget-row head"><div>Code</div><div>Budget line</div><div>Budget</div><div>Actual</div><div>Variance</div></div>
            ${d.budget.map((b) => `
              <div class="gr-budget-row"><div>${esc(b.code)}</div><div>${esc(b.name)}</div><div>${esc(b.budget)}</div><div>${esc(b.actual)}</div>
                <div class="${b.over ? 'over' : ''}">${esc(b.variance)}</div></div>`).join('')}
            <div class="gr-budget-row total"><div></div><div>Total</div><div>${esc(d.budgetTotal)}</div><div>${esc(d.actualTotal)}</div><div>${esc(d.varianceTotal)}</div></div>
          </div>
        </div>
        <div>
          <div class="pg-section-label">Disbursement schedule</div>
          ${d.tranches.map((t) => `
            <div class="gr-line"><span class="gr-line-no">${esc(t.no)}</span><span>${esc(t.date)}</span><span class="gr-line-amt">${esc(t.amount)}</span>
              <span style="margin-inline-start:auto;">${pill(t.status)}</span></div>`).join('') || '<div class="pg-note">No disbursements scheduled.</div>'}
        </div>
        <div>
          <div class="pg-section-label">Reporting calendar</div>
          ${d.reports.map((r) => `
            <div class="gr-line"><span class="gr-line-name">${esc(r.name)}</span><span class="gr-line-muted">${esc(r.period)}</span>
              <span style="margin-inline-start:auto;display:flex;align-items:center;gap:10px;"><span class="gr-line-due">due ${esc(r.due)}</span>${pill(r.state)}</span></div>`).join('')
            || '<div class="pg-note">No reports scheduled.</div>'}
        </div>
        <div>
          <div class="pg-section-label">Agreement terms</div>
          <div class="fd-terms">${terms.map(([label, value]) => `<div class="fd-term"><span>${esc(label)}</span><span>${esc(value)}</span></div>`).join('')}</div>
        </div>
        <div>
          <div class="pg-section-label">Signed agreement</div>
          <div id="gr-docs"></div>
        </div>
        <div>
          <div class="pg-section-label">Compliance conditions</div>
          <div class="gr-conditions">${d.conditions.map((c) => `<div><span>·</span>${esc(c)}</div>`).join('') || '<div>None recorded.</div>'}</div>
        </div>
      </div>
      <div class="pg-foot">
        ${d.ledgerAccount ? `<a class="btn" href="/gl?account=${encodeURIComponent(d.ledgerAccount)}">View in ledger</a>` : ''}
        <button type="button" class="btn pg-close" data-do="close">Close</button>
        ${d.canReceipt ? `<a class="btn" href="/receivables?q=${encodeURIComponent(d.ref)}">Record disbursement</a>` : ''}
        ${d.status === 'Pipeline'
          ? '<button type="button" class="btn btn-primary" data-do="activate">Convert to award</button>'
          : `<a class="btn btn-primary" href="/donor-reports?q=${encodeURIComponent(d.ref)}">${esc(d.reportAction)}</a>`}
      </div>`, { wide: true });

    UI.docPanel(document.getElementById('gr-docs'), {
      kind: 'grant', ref: d.ref, docs: d.documents, canAdd: d.canAttach,
      empty: d.status === 'Pipeline' ? 'No agreement on file yet. Attach the signed copy before converting the award.'
        : 'No signed agreement on file. Awards recorded before documents were required may not have one — attach it.',
      label: 'Attach the signed agreement or a variation',
      onAdded: (documents) => { d.documents = documents; },
    });
    document.querySelector('[data-do="close"]').addEventListener('click', UI.closeDrawer);
    const activate = document.querySelector('[data-do="activate"]');
    if (activate) activate.addEventListener('click', () => convert(d, activate));
  }

  async function convert(d, button) {
    const ref = d.ref;
    if (d.requireAgreement && !d.documents.length) {
      UI.toast('Attach the signed agreement first — an award goes live only with the agreement it is held to.');
      document.getElementById('gr-docs').scrollIntoView({ block: 'center', behavior: 'smooth' });
      return;
    }
    button.disabled = true;
    try {
      const result = await UI.postJSON('/api/grants/' + ref.split('/').map(encodeURIComponent).join('/') + '/activate', {});
      UI.toast(result.message);
      await refresh();
      await openAward(ref);
    } catch (err) {
      UI.toast(err.message);
      button.disabled = false;
    }
  }

  // ---- The reporting calendar ----

  async function openCalendar() {
    let cal;
    try {
      cal = await UI.fetchJSON('/api/grants/calendar');
    } catch (err) {
      UI.toast(err.message);
      return;
    }
    UI.drawer('Reporting calendar', `
      <div class="pg-head">
        <b>Reports owed to donors</b>
        <small>${esc(cal.summary)}. Each is flagged ${esc(cal.warningDays)} days before it falls due.</small>
      </div>
      <div class="pg-body">
        ${cal.reports.map((r) => `
          <a class="gr-cal" href="/donor-reports?q=${encodeURIComponent(r.award)}">
            <span class="gr-cal-when ${r.state === 'Overdue' ? 'late' : r.state === 'Due' ? 'soon' : ''}">
              <b>${esc(r.due)}</b><small>${r.days < 0 ? Math.abs(r.days) + ' days late' : r.days === 0 ? 'today' : 'in ' + r.days + ' days'}</small>
            </span>
            <span class="gr-cal-what"><b>${esc(r.name)}</b><small>${esc(r.award)} · ${esc(r.funder)} · ${esc(r.period)}</small></span>
            ${pill(r.state)}
          </a>`).join('') || '<div class="pg-note">Nothing is owed to a donor.</div>'}
      </div>
      <div class="pg-foot"><button type="button" class="btn pg-close" data-do="close">Close</button></div>`, { wide: true });
    document.querySelector('[data-do="close"]').addEventListener('click', UI.closeDrawer);
  }

  // ---- Recording an award ----

  const STEPS = [
    ['Agreement', 'Who, what, how much, how long'],
    ['Fund', 'Where the money will sit'],
    ['Budget lines', 'What it may be spent on'],
    ['Disbursements', 'When the money arrives'],
    ['Reporting', 'What the donor expects back'],
    ['Conditions and review', 'The rules, then commit'],
  ];
  let opts = null;
  let wz = null;

  const num = (v) => parseFloat(String(v == null ? '' : v).replace(/[^0-9.]/g, '')) || 0;
  const fmt = (n) => UI.fmtMoney(Math.round(n)) === '—' ? '0' : UI.fmtMoney(Math.round(n));
  const iso = (d) => d.toISOString().slice(0, 10);
  const addMonths = (isoDate, m) => { const d = new Date(isoDate + 'T00:00:00Z'); d.setUTCMonth(d.getUTCMonth() + m); return iso(d); };
  const addDays = (isoDate, n) => { const d = new Date(isoDate + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return iso(d); };
  const dmy = (isoDate) => isoDate ? new Date(isoDate + 'T00:00:00Z').toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' }) : '—';

  async function openWizard() {
    if (!data.canRecord) {
      UI.toast('Only the Finance Manager records an award — recording one opens a fund and sets the budget it is held to.');
      return;
    }
    try {
      opts = await UI.fetchJSON('/api/grants/form');
    } catch (err) {
      UI.toast(err.message);
      return;
    }
    const lead = opts.programmes.includes('Election Observation') ? 'Election Observation' : opts.programmes[0];
    const firstAccount = (opts.accounts.find((a) => a.code === '5110') || opts.accounts[0] || {}).code || '';
    wz = {
      step: 0,
      funder: '', ref: '', title: '', program: lead, also: [],
      manager: opts.managers.includes('M. Otieno') ? 'M. Otieno' : opts.managers[0],
      currency: 'KES', rate: '1.00', value: '', start: '', end: '', status: 'Pipeline',
      fundMode: 'new', fundExisting: (opts.funds[0] || {}).code || '', fundName: '', fundCls: 'Restricted',
      capital: false, override: false, overrideReason: '', purpose: '', indirectCap: '8',
      budget: [{ code: firstAccount, amount: '' }],
      tranches: [{ no: 'Tranche 1', date: '', amount: '' }],
      reports: [{ name: 'Financial report Q1', from: '', to: '', due: '' }],
      conditions: ['Costs must be incurred within the agreement period; no retroactive charges.'],
      files: [],
    };
    closeModal();
    drawWizard();
  }

  const value = () => num(wz.value);
  const budgetTotal = () => wz.budget.reduce((t, l) => t + num(l.amount), 0);
  const trancheTotal = () => wz.tranches.reduce((t, l) => t + num(l.amount), 0);
  const indirect = () => wz.budget.filter((l) => String(l.code).startsWith(opts.indirectPrefix)).reduce((t, l) => t + num(l.amount), 0);
  const capAmount = () => Math.round(value() * num(wz.indirectCap) / 100);
  const suggestedFund = () => (wz.funder.trim() || 'New funder') + ' ' + wz.program + ' Fund';
  const ledgerDerived = () => wz.fundCls === 'Endowment' ? 'Endowment Fund' : wz.fundCls === 'Designated' ? 'General Fund' : wz.capital ? 'Capital Fund' : 'Grant Fund';
  const ledgerFinal = () => wz.override ? 'General Fund' : ledgerDerived();
  const fundResolved = () => wz.fundMode === 'new' ? (wz.fundName.trim() || suggestedFund()) : ((opts.funds.find((f) => f.code === wz.fundExisting) || {}).name || '');

  /** The step's first problem, in the same words the API refuses with. */
  function stepError(i) {
    if (i === 0) {
      if (!wz.funder.trim()) return 'Funder is required — an award cannot exist without one.';
      if (!wz.ref.trim()) return 'Award reference is required. It is the key the donor quotes in every query.';
      if (opts.refs.some((r) => r.toLowerCase() === wz.ref.trim().toLowerCase())) return 'Award reference ' + wz.ref.trim() + ' is already in the portfolio.';
      if (!wz.title.trim()) return 'Give the award a title.';
      if (value() <= 0) return 'Award value must be greater than zero.';
      if (wz.currency !== 'KES' && num(wz.rate) <= 0) return 'Enter the agreement rate: what 1 ' + wz.currency + ' is worth in KES.';
      if (!wz.start || !wz.end) return 'Enter the start and end dates of the agreement.';
      if (wz.end < wz.start) return 'The award ends before it starts.';
      if (wz.status === 'Active' && opts.requireAgreement && !wz.files.some((f) => f.id)) return 'Attach the signed grant agreement. An award goes live only with the agreement it is held to.';
      return '';
    }
    if (i === 1) {
      if (wz.fundMode === 'new' && !wz.purpose.trim()) return "State the fund's purpose — it is what defines eligible spending.";
      if (wz.fundMode === 'new' && wz.override && !wz.overrideReason.trim()) return 'Presenting restricted money in the General Fund overstates free reserves. Record why the agreement carries no restriction before continuing.';
      if (wz.fundMode === 'existing' && !wz.fundExisting) return 'Choose the fund the award is held in.';
      return '';
    }
    if (i === 2) {
      if (!wz.budget.length) return 'Add at least one budget line.';
      const codes = wz.budget.map((l) => l.code);
      const twice = codes.find((c, j) => codes.indexOf(c) !== j);
      if (twice) return twice + ' is budgeted twice. Put each account on one line.';
      if (wz.budget.some((l) => num(l.amount) <= 0)) return 'Every budget line needs an amount.';
      if (Math.abs(budgetTotal() - value()) > 0.005) return 'Budget lines total ' + fmt(budgetTotal()) + ' against an award value of ' + fmt(value()) + '. They must agree before the award can be recorded.';
      if (num(wz.indirectCap) > 0 && indirect() > capAmount()) return 'Indirect cost recovery of ' + fmt(indirect()) + ' exceeds the agreed cap of ' + num(wz.indirectCap) + '% (' + fmt(capAmount()) + ').';
      return '';
    }
    if (i === 3) {
      if (!wz.tranches.length) return 'Add at least one disbursement.';
      if (wz.tranches.some((t) => !t.date)) return 'Every disbursement needs an expected date.';
      if (wz.tranches.some((t) => num(t.amount) <= 0)) return 'Every disbursement needs an amount.';
      if (Math.abs(trancheTotal() - value()) > 0.005) return 'Disbursements total ' + fmt(trancheTotal()) + ' against an award value of ' + fmt(value()) + '. They must agree.';
      return '';
    }
    if (i === 4) {
      if (!wz.reports.length) return 'An award with no reporting calendar gets missed. Add at least one report.';
      if (wz.reports.some((r) => !r.name.trim() || !r.due)) return 'Every report needs a name and a due date.';
      if (wz.reports.some((r) => r.from && r.to && r.to < r.from)) return 'A report covers a period that ends before it starts.';
      return '';
    }
    if (!wz.conditions.filter((c) => c.trim()).length) return 'Record at least one condition from the signed agreement.';
    return '';
  }
  const firstError = () => STEPS.map((s, i) => stepError(i)).find((e) => e) || '';

  /** The dialog is built once; a step change redraws its parts in place, so it does not flash. */
  function drawWizard() {
    if (!modalEl) {
      modalEl = document.createElement('div');
      modalEl.className = 'ap-modal';
      modalEl.id = 'gr-wizard';
      modalEl.innerHTML = `
        <div class="ap-modal-scrim" data-close></div>
        <div class="ap-modal-box gr-wz" role="dialog" aria-modal="true" aria-label="Record award">
          <div class="jd-head" style="padding:16px 20px;border-bottom-color:#E4E2DB;">
            <div style="display:flex;flex-direction:column;gap:3px;">
              <div class="jd-caps" style="letter-spacing:.1em;">Record award · from signed agreement</div>
              <div style="font-size:16px;font-weight:600;letter-spacing:-.01em;" id="gr-wz-title"></div>
              <div style="font-size:11.5px;color:#7A857F;" id="gr-wz-sub"></div>
            </div>
            <div style="margin-inline-start:auto;display:flex;align-items:center;gap:12px;">
              <span class="gr-wz-counter" id="gr-wz-counter"></span>
              <button type="button" class="rt-close" data-close aria-label="Close">×</button>
            </div>
          </div>
          <div class="gr-wz-main">
            <nav class="gr-wz-steps" id="gr-wz-steps"></nav>
            <div class="gr-wz-body" id="gr-wz-body"></div>
          </div>
          <div class="ap-modal-foot" id="gr-wz-foot"></div>
        </div>`;
      document.body.appendChild(modalEl);
      modalEl.addEventListener('click', onWizardClick);
      modalEl.addEventListener('input', onWizardInput);
      modalEl.addEventListener('change', onWizardChange);
    }

    modalEl.querySelector('#gr-wz-title').textContent = STEPS[wz.step][0];
    modalEl.querySelector('#gr-wz-sub').textContent = STEPS[wz.step][1];
    modalEl.querySelector('#gr-wz-counter').textContent = 'Step ' + (wz.step + 1) + ' of 6';
    modalEl.querySelector('#gr-wz-steps').innerHTML = STEPS.map(([label, note], i) => `
      <button type="button" data-step="${i}" class="${i === wz.step ? 'on' : i < wz.step ? 'done' : ''}" ${i > wz.step ? 'disabled' : ''}>
        <span class="gr-wz-mark">${i < wz.step ? '✓' : i + 1}</span>
        <span><b>${esc(label)}</b><small>${esc(note)}</small></span>
      </button>`).join('') + '<div class="gr-wz-aside">Nothing is saved until the final step. Value, period and fund become locked fields once recorded.</div>';
    modalEl.querySelector('#gr-wz-foot').innerHTML = `
      <div class="pg-modal-note" id="gr-wz-note"></div>
      ${wz.step > 0 ? '<button type="button" class="btn" data-go="back" style="height:34px;padding:0 14px;">Back</button>' : ''}
      <button type="button" class="btn" data-close style="height:34px;padding:0 14px;">Cancel</button>
      ${wz.step < 5
        ? '<button type="button" class="btn btn-primary" data-go="next" style="height:34px;padding:0 18px;">Continue</button>'
        : '<button type="button" class="btn btn-primary" data-go="commit" style="height:34px;padding:0 18px;">Record award</button>'}`;
    const body = modalEl.querySelector('#gr-wz-body');
    body.innerHTML = stepBody();
    body.scrollTop = 0;
    mountAgreement();
    refreshDerived();
  }

  /** Redraws only the step's body — for a structural change: a row added or removed, a mode switched. */
  function redrawBody() {
    modalEl.querySelector('#gr-wz-body').innerHTML = stepBody();
    mountAgreement();
    refreshDerived();
  }

  /** The signed agreement, on the first step: required for an Active award, asked for on a pipeline one. */
  function mountAgreement() {
    const host = modalEl.querySelector('#gr-agreement');
    if (!host) return;
    const active = wz.status === 'Active';
    UI.docPicker(host, {
      label: 'Signed grant agreement', required: active && opts.requireAgreement, recommended: !active, files: wz.files,
      onChange: () => refreshDerived(),
      hint: active ? 'The signed copy, with its annexes. The donor audit starts from it.'
        : 'Attach the draft or proposal if you have it. The signed agreement is needed when the award is converted.',
    });
  }

  function field(label, input, extra) {
    return `<label class="as-field ${extra || ''}"><span>${esc(label)}</span>${input}</label>`;
  }
  const text = (key, placeholder, attrs) => `<input data-k="${key}" value="${esc(wz[key])}" placeholder="${esc(placeholder || '')}" ${attrs || ''}>`;
  const select = (key, options, current) => `<select data-k="${key}">${options.map((o) => {
    const [v, l] = Array.isArray(o) ? o : [o, o];
    return `<option value="${esc(v)}" ${v === current ? 'selected' : ''}>${esc(l)}</option>`;
  }).join('')}</select>`;

  function stepBody() {
    if (wz.step === 0) {
      return `
        <div class="pg-form-pair even">
          ${field('Funder', `<input data-k="funder" list="gr-funders" value="${esc(wz.funder)}" placeholder="e.g. Embassy of Sweden"><datalist id="gr-funders">${opts.funders.map((f) => `<option value="${esc(f)}">`).join('')}</datalist>`)}
          ${field('Award reference', text('ref', 'e.g. SIDA/CE-2027', 'maxlength="40"'))}
        </div>
        ${field('Award title', text('title', 'What the agreement funds, in one line'))}
        <div class="pg-form-pair even">
          ${field('Lead programme', select('program', opts.programmes, wz.program))}
          ${field('Grant manager', select('manager', opts.managers, wz.manager))}
        </div>
        <div>
          <div class="pg-section-label">Also funds</div>
          <div class="pg-modes">${opts.programmes.filter((p) => p !== wz.program).map((p) => `
            <button type="button" data-also="${esc(p)}" class="${wz.also.includes(p) ? 'on' : ''}">${esc(p)}</button>`).join('')}</div>
          <div class="pg-hint">${wz.also.length
            ? 'This award may be charged to ' + esc([wz.program].concat(wz.also).join(', ')) + '. Advances, requisitions and budget lines will offer all of them.'
            : 'Only ' + esc(wz.program) + ' may be charged to this award. Add a programme here when the workplan genuinely splits across two.'}</div>
        </div>
        <div class="gr-wz-three">
          ${field('Currency', select('currency', opts.currencies.map((c) => c.code), wz.currency))}
          ${field('Award value in KES', text('value', '0', 'inputmode="numeric" class="gr-num"'))}
          ${wz.currency !== 'KES' ? field('1 ' + wz.currency + ' in KES', text('rate', '', 'inputmode="decimal" class="gr-num"')) : '<div></div>'}
        </div>
        ${wz.currency !== 'KES' ? `<div class="pg-note">The agreement is denominated in ${esc(wz.currency)}. The award is held in KES at the agreement rate; the difference on each receipt posts as an exchange gain or loss. Check whether this donor treats exchange losses as an eligible cost — several do not.</div>` : ''}
        <div class="gr-wz-three">
          ${field('Start date', `<input type="date" data-k="start" value="${esc(wz.start)}">`)}
          ${field('End date', `<input type="date" data-k="end" value="${esc(wz.end)}">`)}
          ${field('Record as', select('status', ['Pipeline', 'Active'], wz.status))}
        </div>
        <div class="pg-note">Record as <strong>Pipeline</strong> while the agreement is unsigned — the budget is visible but nothing may be committed against it and no receivable is raised. Move it to <strong>Active</strong> only on signature.</div>
        <div id="gr-agreement"></div>`;
    }

    if (wz.step === 1) {
      const mode = (key, title, note) => `
        <button type="button" data-mode="${key}" class="gr-wz-choice ${wz.fundMode === key ? 'on' : ''}">
          <b>${esc(title)}</b><small>${esc(note)}</small>${wz.fundMode === key ? '<em>Selected</em>' : ''}
        </button>`;
      const existing = opts.funds.find((f) => f.code === wz.fundExisting);
      return `
        <div class="pg-form-pair even">
          ${mode('new', 'Open a new fund', "The normal case. This donor's money is ring-fenced and reported separately.")}
          ${mode('existing', 'Attach to an existing fund', 'For a follow-on phase, a basket the donor already contributes to, or genuinely unrestricted income.')}
        </div>
        ${wz.fundMode === 'new' ? `
          <label class="as-field"><span>Fund name</span><input data-k="fundName" value="${esc(wz.fundName)}" placeholder="${esc(suggestedFund())}">
            <small class="gr-wz-small">Leave blank to use <strong id="gr-fund-suggest">${esc(suggestedFund())}</strong>. It opens as ${esc(opts.nextFundCode)}.</small></label>
          <label class="as-field"><span>Fund class</span>${select('fundCls', ['Restricted', 'Designated', 'Endowment'], wz.fundCls)}
            <small class="gr-wz-small">Donor money with an agreement and a reporting calendar is restricted by definition. Unrestricted is not offered here — set it when creating a fund directly.</small></label>
          ${wz.fundCls === 'Restricted' ? `
            <button type="button" class="gr-wz-check ${wz.capital ? 'on' : ''}" data-toggle="capital">
              <span class="gr-wz-box">${wz.capital ? '✓' : ''}</span>
              <span><b>This award is for capital items</b><small>Vehicles, ICT equipment, leasehold improvements — anything that becomes a fixed asset rather than programme spend.</small></span>
            </button>` : ''}
          <div class="gr-wz-rollup">
            <div><span>Rolls up into</span><b>${esc(ledgerFinal())}</b></div>
            <div class="pg-hint" style="margin:0;">${esc(wz.fundCls === 'Endowment' ? 'Permanent capital is held in the Endowment Fund. Only realised investment income may ever be released.'
              : wz.fundCls === 'Designated' ? 'Designated money is legally unrestricted, so it presents within the General Fund. Release needs a board minute, not donor consent.'
              : wz.capital ? 'Capital awards roll into the Capital Fund — the money is tied up in assets and is not available for operations. Depreciation is charged against it.'
              : 'Donor-restricted programme money consolidates into the Grant Fund and appears as a separate restricted line in the fund balances.')}</div>
            ${ledgerDerived() !== 'General Fund' && !wz.override ? '<button type="button" class="gr-link" data-toggle="override">Present this in the General Fund instead</button>' : ''}
          </div>
          ${wz.override ? `
            <div class="pg-warn" style="margin:0;display:flex;flex-direction:column;gap:9px;">
              <div>Presenting restricted money in the General Fund overstates your free reserves — the fund statement will not show what is committed. Only do this if the agreement genuinely carries no spending restriction.</div>
              ${field('Reason — recorded on the audit trail', text('overrideReason', 'e.g. Agreement clause 4 states funds are for general institutional support'))}
              <button type="button" class="gr-link" data-toggle="override">Cancel the override</button>
            </div>` : ''}
          ${field('Purpose — what this money may be spent on', `<textarea data-k="purpose" rows="3" placeholder="Taken from the agreement's objective clause">${esc(wz.purpose)}</textarea>`)}
          <div class="pg-note">A <strong>Restricted</strong> fund can never go negative — overspending it means spending money you were not given, so the system blocks it rather than warning. Spend-by date will be set to the award end date, and the fund will appear on the statement of financial position from the first receipt.</div>`
        : `
          ${field('Fund', select('fundExisting', opts.funds.map((f) => [f.code, f.name]), wz.fundExisting))}
          ${existing && existing.restriction === 'unrestricted' ? `<div class="pg-warn" style="margin:0;">${esc(existing.name)} is unrestricted. Coding donor money here loses the spending restriction and the fund statement will not show what is committed — only do this if the agreement genuinely carries no restriction.</div>` : ''}`}`;
    }

    if (wz.step === 2) {
      return `
        <div class="gr-wz-bar">
          <div>Budget lines must total the award value of <strong>KES ${esc(fmt(value()))}</strong>.</div>
          <div><button type="button" class="btn" data-do="balance-budget">Balance to last line</button><button type="button" class="btn" data-do="add-budget">+ Add line</button></div>
        </div>
        <div class="gr-wz-table budget">
          <div class="gr-wz-tr head"><div>Account</div><div>Budget KES</div><div>Share</div><div></div></div>
          ${wz.budget.map((l, i) => `
            <div class="gr-wz-tr">
              <div><select data-row="budget" data-i="${i}" data-f="code">${opts.accounts.map((a) => `<option value="${esc(a.code)}" ${a.code === l.code ? 'selected' : ''}>${esc(a.code + ' · ' + a.name)}</option>`).join('')}</select></div>
              <div><input data-row="budget" data-i="${i}" data-f="amount" value="${esc(l.amount)}" inputmode="numeric" placeholder="0" class="gr-num"></div>
              <div class="gr-wz-pct" data-pct="${i}"></div>
              <div>${wz.budget.length > 1 ? `<button type="button" class="gr-x" data-remove="budget" data-i="${i}" aria-label="Remove line">×</button>` : ''}</div>
            </div>`).join('')}
          <div class="gr-wz-tr total"><div>Total</div><div id="gr-budget-total"></div><div></div><div></div></div>
        </div>
        <div id="gr-budget-check"></div>
        <div class="gr-wz-cap">
          ${field('Indirect cost cap %', text('indirectCap', '', 'inputmode="numeric" class="gr-num"'))}
          <div id="gr-cap-note" class="pg-hint" style="margin:0;"></div>
        </div>`;
    }

    if (wz.step === 3) {
      return `
        <div class="gr-wz-bar">
          <div>When the donor expects to pay. Each line is claimed from the donor under Receivables when it falls due.</div>
          <div><button type="button" class="btn" data-do="split-tranches">Split evenly</button><button type="button" class="btn" data-do="balance-tranches">Balance to last</button><button type="button" class="btn" data-do="add-tranche">+ Add</button></div>
        </div>
        <div class="gr-wz-table tranches">
          <div class="gr-wz-tr head"><div>Disbursement</div><div>Expected</div><div>Amount KES</div><div></div></div>
          ${wz.tranches.map((t, i) => `
            <div class="gr-wz-tr">
              <div><input data-row="tranches" data-i="${i}" data-f="no" value="${esc(t.no)}" placeholder="Tranche 1" maxlength="40"></div>
              <div><input type="date" data-row="tranches" data-i="${i}" data-f="date" value="${esc(t.date)}"></div>
              <div><input data-row="tranches" data-i="${i}" data-f="amount" value="${esc(t.amount)}" inputmode="numeric" placeholder="0" class="gr-num"></div>
              <div>${wz.tranches.length > 1 ? `<button type="button" class="gr-x" data-remove="tranches" data-i="${i}" aria-label="Remove disbursement">×</button>` : ''}</div>
            </div>`).join('')}
          <div class="gr-wz-tr total"><div>Total</div><div></div><div id="gr-tranche-total"></div><div></div></div>
        </div>
        <div id="gr-tranche-check"></div>`;
    }

    if (wz.step === 4) {
      return `
        <div class="gr-wz-bar">
          <div>Every date here is flagged on the grants page ${esc(opts.reportWarningDays)} days out. The presets date themselves from the agreement start.</div>
          <div>
            <button type="button" class="btn" data-preset="quarterly">+ Quarterly financial</button>
            <button type="button" class="btn" data-preset="narrative">+ Narrative</button>
            <button type="button" class="btn" data-preset="closeout">+ Close-out</button>
            <button type="button" class="btn" data-do="add-report">+ Blank</button>
          </div>
        </div>
        <div class="gr-wz-table reports">
          <div class="gr-wz-tr head"><div>Report</div><div>Covers from</div><div>to</div><div>Due</div><div></div></div>
          ${wz.reports.map((r, i) => `
            <div class="gr-wz-tr">
              <div><input data-row="reports" data-i="${i}" data-f="name" value="${esc(r.name)}" placeholder="Financial report Q1"></div>
              <div><input type="date" data-row="reports" data-i="${i}" data-f="from" value="${esc(r.from)}"></div>
              <div><input type="date" data-row="reports" data-i="${i}" data-f="to" value="${esc(r.to)}"></div>
              <div><input type="date" data-row="reports" data-i="${i}" data-f="due" value="${esc(r.due)}"></div>
              <div>${wz.reports.length > 1 ? `<button type="button" class="gr-x" data-remove="reports" data-i="${i}" aria-label="Remove report">×</button>` : ''}</div>
            </div>`).join('')}
        </div>
        <div class="pg-note">A missed report is the fastest way to lose a follow-on award, and the deadline is nearly always earlier than the finance team assumes — most agreements count days from period end, not from month end. A period left blank covers the agreement from its start to the due date.</div>`;
    }

    const conditions = wz.conditions.filter((c) => c.trim()).length;
    const firstTranche = wz.tranches[0] || {};
    const nextReport = wz.reports.map((r) => r.due).filter(Boolean).sort()[0];
    const review = [
      ['Award', (wz.ref.trim() || '—') + ' · ' + (wz.title.trim() || 'untitled')],
      ['Funder', wz.funder.trim() || '—'],
      ['Programme', wz.also.length ? wz.program + ' (lead) · also ' + wz.also.join(', ') : wz.program],
      ['Value', 'KES ' + fmt(value()) + (wz.currency !== 'KES' ? ' · ' + wz.currency + ' ' + fmt(value() / Math.max(0.0001, num(wz.rate))) : '')],
      ['Period', dmy(wz.start) + ' – ' + dmy(wz.end)],
      ['Fund', fundResolved() + (wz.fundMode === 'new' ? ' · new, ' + wz.fundCls.toLowerCase() + ', rolls into ' + ledgerFinal() : ' · existing')],
      ['Budget lines', wz.budget.length + ' lines · ' + fmt(budgetTotal())],
      ['Disbursements', wz.tranches.length + ' expected · first ' + dmy(firstTranche.date)],
      ['Reporting', wz.reports.length + ' reports · next ' + dmy(nextReport)],
      ['Conditions', conditions + ' recorded'],
      ['Status on save', wz.status],
    ];
    const effects = [
      wz.fundMode === 'new'
        ? ['Fund opened', fundResolved() + ' as ' + opts.nextFundCode + ' — ' + wz.fundCls.toLowerCase() + ', spend-by ' + dmy(wz.end) + ', rolling up into the ' + ledgerFinal() + '.' + (wz.override ? ' Override recorded: ' + wz.overrideReason.trim() : '')]
        : ['Fund attached', 'Award coded to the existing ' + fundResolved() + '. No new fund opened.'],
      ['Award budget recorded', wz.budget.length + ' lines totalling ' + fmt(budgetTotal()) + ' — what the drawer measures actuals against. The organisation budget the procurement check reads is changed through a budget revision under Budgets.'],
      ['Disbursements scheduled', wz.status === 'Pipeline'
        ? 'None claimable. A pipeline award raises no receivable until the agreement is signed and it is converted.'
        : wz.tranches.length + (wz.tranches.length === 1 ? ' tranche' : ' tranches') + ', the first ' + fmt(num(firstTranche.amount)) + ' expected ' + dmy(firstTranche.date) + '. Each is claimed under Receivables when due.'],
      ['Reporting deadlines set', wz.reports.length + (wz.reports.length === 1 ? ' report' : ' reports') + ' written to the reporting calendar; the grants page flags each one ' + opts.reportWarningDays + ' days out.'],
      ['Audit trail', 'Award set-up recorded against you. Value and period become locked fields — later changes need a recorded variation.'],
    ];
    return `
      <div>
        <div class="gr-wz-bar"><div class="pg-section-label" style="margin:0;">Conditions from the agreement</div><div><button type="button" class="btn" data-do="add-condition">+ Add condition</button></div></div>
        ${wz.conditions.map((c, i) => `
          <div class="gr-wz-cond">
            <input data-row="conditions" data-i="${i}" value="${esc(c)}" placeholder="e.g. Procurement above KES 1,000,000 requires three quotations">
            ${wz.conditions.length > 1 ? `<button type="button" class="gr-x" data-remove="conditions" data-i="${i}" aria-label="Remove condition">×</button>` : ''}
          </div>`).join('')}
      </div>
      <div class="pg-form-pair even" style="align-items:start;">
        <div>
          <div class="pg-section-label">Award summary</div>
          <div class="pg-facts">${review.map(([l, v]) => `<div class="pg-fact"><span>${esc(l)}</span><span style="font-family:inherit;">${esc(v)}</span></div>`).join('')}</div>
        </div>
        <div>
          <div class="pg-section-label">What recording this does</div>
          <div class="gr-wz-effects">${effects.map(([w, d]) => `<div><b>${esc(w)}</b><span>${esc(d)}</span></div>`).join('')}</div>
        </div>
      </div>`;
  }

  /** Totals, shares, checks and the footer note — redrawn as fields are typed in, without touching the fields. */
  function refreshDerived() {
    const body = modalEl.querySelector('#gr-wz-body');
    const set = (sel, html) => { const el = body.querySelector(sel); if (el) el.innerHTML = html; };
    if (wz.step === 2) {
      wz.budget.forEach((l, i) => set(`[data-pct="${i}"]`, value() > 0 ? Math.round(num(l.amount) / value() * 100) + '%' : '—'));
      set('#gr-budget-total', esc(fmt(budgetTotal())));
      const gap = value() - budgetTotal();
      set('#gr-budget-check', Math.abs(gap) < 0.005 && value() > 0
        ? `<div class="pg-note ok">Budget agrees with the award value. These lines become the award's budget for ${esc(wz.program)}, which the drawer measures actuals against.</div>`
        : gap < 0 ? `<div class="fd-alert">Budget lines exceed the award value by ${esc(fmt(-gap))}. Reduce a line or raise the award value.</div>`
          : `<div class="fd-alert">${esc(fmt(gap))} of the award is unallocated. Every shilling must sit on a line, or it cannot be spent.</div>`);
      const capped = num(wz.indirectCap) > 0;
      set('#gr-cap-note', !capped ? 'No indirect cost cap recorded.'
        : indirect() > capAmount()
          ? `<span style="color:#A6412F;">Indirect and support costs ${esc(fmt(indirect()))} of a permitted ${esc(fmt(capAmount()))} at ${num(wz.indirectCap)}% — over cap. Move the excess to a direct line or renegotiate the rate.</span>`
          : `Indirect and support costs ${esc(fmt(indirect()))} of a permitted ${esc(fmt(capAmount()))} at ${num(wz.indirectCap)}%. Measured against the administration and governance group, accounts ${esc(opts.indirectPrefix)}xx.`);
    }
    if (wz.step === 3) {
      set('#gr-tranche-total', esc(fmt(trancheTotal())));
      const gap = value() - trancheTotal();
      set('#gr-tranche-check', Math.abs(gap) < 0.005 && value() > 0
        ? '<div class="pg-note ok">Schedule agrees with the award value.</div>'
        : `<div class="fd-alert">Schedule is out by ${esc(fmt(Math.abs(gap)))} against the award value.</div>`);
    }
    if (wz.step === 1) {
      const s = body.querySelector('#gr-fund-suggest');
      if (s) s.textContent = suggestedFund();
    }
    const error = wz.step === 5 ? firstError() : stepError(wz.step);
    const note = modalEl.querySelector('#gr-wz-note');
    note.textContent = error || 'Nothing is written until you record the award.';
    note.classList.toggle('bad', !!error);
  }

  function onWizardInput(e) {
    const t = e.target;
    if (t.dataset.k) {
      wz[t.dataset.k] = t.value;
    } else if (t.dataset.row === 'conditions') {
      wz.conditions[+t.dataset.i] = t.value;
    } else if (t.dataset.row) {
      wz[t.dataset.row][+t.dataset.i][t.dataset.f] = t.value;
    } else {
      return;
    }
    refreshDerived();
  }

  /** Choices that change what the step shows redraw it. */
  function onWizardChange(e) {
    const k = e.target.dataset.k;
    if (k === 'currency') {
      const c = opts.currencies.find((x) => x.code === wz.currency);
      wz.rate = wz.currency === 'KES' ? '1.00' : String(c ? c.rate : wz.rate);
    }
    if (k === 'program') wz.also = wz.also.filter((p) => p !== wz.program);
    if (['currency', 'program', 'fundCls', 'fundExisting', 'status'].includes(k)) {
      if (k === 'fundCls' && wz.fundCls !== 'Restricted') { wz.capital = false; wz.override = false; }
      redrawBody();
    }
  }

  function onWizardClick(e) {
    const t = e.target.closest('button, [data-close]');
    if (!t) return;
    if (t.hasAttribute('data-close')) { closeModal(); return; }
    const d = t.dataset;

    if (d.step !== undefined) { if (+d.step <= wz.step) { wz.step = +d.step; drawWizard(); } return; }
    if (d.go === 'back') { wz.step = Math.max(0, wz.step - 1); drawWizard(); return; }
    if (d.go === 'next') {
      const error = stepError(wz.step);
      if (error) { UI.toast(error); return; }
      wz.step += 1;
      drawWizard();
      return;
    }
    if (d.go === 'commit') { commit(t); return; }

    if (d.also) { wz.also = wz.also.includes(d.also) ? wz.also.filter((p) => p !== d.also) : wz.also.concat([d.also]); redrawBody(); return; }
    if (d.mode) { wz.fundMode = d.mode; redrawBody(); return; }
    if (d.toggle === 'capital') { wz.capital = !wz.capital; redrawBody(); return; }
    if (d.toggle === 'override') { wz.override = !wz.override; wz.overrideReason = ''; redrawBody(); return; }
    if (d.remove) { wz[d.remove].splice(+d.i, 1); redrawBody(); return; }
    if (d.preset) { addPreset(d.preset); redrawBody(); return; }

    const last = (list) => list[list.length - 1];
    switch (d.do) {
      case 'add-budget': {
        const used = wz.budget.map((l) => l.code);
        wz.budget.push({ code: (opts.accounts.find((a) => !used.includes(a.code)) || opts.accounts[0]).code, amount: '' });
        break;
      }
      case 'balance-budget': last(wz.budget).amount = String(Math.max(0, num(last(wz.budget).amount) + value() - budgetTotal())); break;
      case 'add-tranche': wz.tranches.push({ no: 'Tranche ' + (wz.tranches.length + 1), date: '', amount: '' }); break;
      case 'balance-tranches': last(wz.tranches).amount = String(Math.max(0, num(last(wz.tranches).amount) + value() - trancheTotal())); break;
      case 'split-tranches': {
        const n = wz.tranches.length;
        if (!n || value() <= 0) return;
        const each = Math.round(value() / n / 1000) * 1000;
        wz.tranches.forEach((l, j) => { l.amount = String(j === n - 1 ? value() - each * (n - 1) : each); });
        break;
      }
      case 'add-report': wz.reports.push({ name: 'Financial report', from: '', to: '', due: '' }); break;
      case 'add-condition': wz.conditions.push(''); break;
      default: return;
    }
    redrawBody();
  }

  /** Report presets, dated from the agreement: each due 30 days after the period it covers (90 for close-out). */
  function addPreset(kind) {
    const start = wz.start;
    const period = (from, months) => {
      if (!start) return { from: '', to: '', due: '' };
      let to = addDays(addMonths(from, months), -1);
      if (wz.end && to > wz.end) to = wz.end;
      return { from, to, due: addDays(to, 30) };
    };
    if (kind === 'quarterly') {
      for (let q = 0; q < 4; q++) {
        const from = start ? addMonths(start, q * 3) : '';
        if (start && wz.end && from > wz.end) break;
        wz.reports.push({ name: 'Financial report Q' + (q + 1), ...period(from, 3) });
      }
    } else if (kind === 'narrative') {
      wz.reports.push({ name: 'Semi-annual narrative', ...period(start, 6) }, { name: 'Annual narrative', ...period(start, 12) });
    } else {
      wz.reports.push({ name: 'Audited close-out', from: start, to: wz.end, due: wz.end ? addDays(wz.end, 90) : '' });
    }
    // The blank first row the form opens with gives way to a preset.
    if (wz.reports.length > 1 && !wz.reports[0].due && !wz.reports[0].from) wz.reports.shift();
  }

  async function commit(button) {
    const error = firstError();
    if (error) { UI.toast(error); return; }
    if (wz.files.some((f) => !f.id && !f.error)) { UI.toast('Wait for the agreement to finish uploading.'); return; }
    button.disabled = true;
    try {
      const result = await UI.postJSON('/api/grants', {
        funder: wz.funder.trim(), ref: wz.ref.trim(), title: wz.title.trim(), program: wz.program, alsoPrograms: wz.also,
        manager: wz.manager, currency: wz.currency, rate: num(wz.rate), value: value(), start: wz.start, end: wz.end, status: wz.status,
        fundMode: wz.fundMode, fundExisting: wz.fundExisting, fundName: wz.fundName.trim(), fundCls: wz.fundCls,
        capital: wz.capital, ledgerOverride: wz.override, overrideReason: wz.overrideReason.trim(), purpose: wz.purpose.trim(),
        indirectCap: wz.indirectCap, budget: wz.budget, tranches: wz.tranches, reports: wz.reports, conditions: wz.conditions,
        documents: wz.files.filter((f) => f.id).map((f) => f.id),
      });
      closeModal();
      UI.toast(result.message);
      state.status = 'All';
      state.q = '';
      app.querySelector('#gr-q').value = '';
      await refresh();
      await openAward(result.ref);
    } catch (err) {
      UI.toast(err.message);
      button.disabled = false;
    }
  }

  // ---- The modal shell ----

  let modalEl = null;

  function closeModal() {
    if (modalEl) modalEl.remove();
    modalEl = null;
  }

  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

  await refresh();
  if (params.get('grant')) await openAward(params.get('grant'));
})();
