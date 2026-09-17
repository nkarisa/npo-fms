/**
 * Payroll (v5).
 *
 * One run a month for the whole secretariat, moved a month at a time. The run
 * for the month on screen is calculated from the roster as it stood then, and
 * carried through prepared → approved → posted → remitted; what the ledger will
 * receive is shown before it is posted, so nothing is a surprise. Every figure,
 * rate and rule comes from the API — this file only draws what it is given.
 */
(async function () {
  const app = document.getElementById('app');
  const params = new URLSearchParams(location.search);
  const state = { period: params.get('period') || '', filter: 'All', q: '', page: 1 };
  let data = null;
  let searchTimer;

  async function load() {
    const p = new URLSearchParams({ filter: state.filter, q: state.q, page: state.page });
    if (state.period) p.set('period', state.period);
    return UI.fetchJSON('/api/payroll?' + p.toString());
  }

  async function refresh() {
    data = await load();
    state.period = data.period;
    state.page = data.page;
    render();
  }

  // ---- The page ----

  function render() {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: data.kicker,
      title: 'Payroll',
      blurb: 'One run a month for the whole secretariat. Pay, statutory deductions and the employer\'s own '
        + 'contributions are calculated here and charged to the funds and grants each post is worked against — '
        + 'the run is the subsidiary record behind 5210, 5220 and the statutory liabilities in 22xx.',
    });

    app.appendChild(toolbar());
    app.appendChild(UI.statGrid(data.stats));
    if (data.actions.blocked) app.appendChild(banner(data.actions.blocked));
    app.appendChild(register());

    const cols = document.createElement('div');
    cols.className = 'pr-cols';
    cols.append(remittances(), charges());
    app.appendChild(cols);
    app.appendChild(journal());
  }

  function toolbar() {
    const bar = document.createElement('div');
    bar.className = 'pr-bar';
    const a = data.actions;

    bar.innerHTML = `
      <div class="pr-step">
        <button type="button" data-step="-1" ${data.prevDisabled ? 'disabled' : ''}>←</button>
        <div class="pr-period"><b>${UI.esc(data.period)}</b><span>${UI.esc(data.periodNote)}</span></div>
        <button type="button" data-step="1" ${data.nextDisabled ? 'disabled' : ''}>→</button>
      </div>
      <div class="pr-actions">
        <button type="button" class="btn" data-roster="starter">Edit roster</button>
        ${a.canSubmit ? '<button type="button" class="btn btn-primary" data-run="submit">Send for approval</button>' : ''}
        ${a.canApprove ? '<button type="button" class="btn btn-primary" data-run="approve">Approve run</button>' : ''}
        ${a.canPost ? `<button type="button" class="btn btn-primary" data-run="post">${UI.esc(a.postLabel)}</button>` : ''}
        ${a.posted ? '<span class="pr-posted">Posted ✓</span>' : ''}
      </div>`;

    bar.querySelectorAll('[data-step]').forEach((b) => b.addEventListener('click', () => step(+b.dataset.step)));
    bar.querySelector('[data-roster]').addEventListener('click', () => openEditor('starter'));
    bar.querySelectorAll('[data-run]').forEach((b) => b.addEventListener('click', () => runAction(b, b.dataset.run)));
    return bar;
  }

  function step(delta) {
    const at = data.periodOptions.indexOf(data.period) + delta;
    if (at < 0 || at >= data.periodOptions.length) return;
    state.period = data.periodOptions[at];
    state.page = 1;
    refresh();
  }

  function banner(text) {
    const div = document.createElement('div');
    div.className = 'card';
    div.style.cssText = 'background:#FBF1E1;border-color:#EEE2CB;';
    div.innerHTML = `<div style="padding:12px 16px;color:#8A5B2E;font-size:12px;line-height:1.55;">${UI.esc(text)}</div>`;
    return div;
  }

  // ---- The register for the month ----

  function register() {
    const card = document.createElement('div');
    card.className = 'card';

    const toolbar = document.createElement('div');
    toolbar.className = 'toolbar';
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Search staff number, name, role or grade…';
    search.value = state.q;
    search.addEventListener('input', (e) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => { state.q = e.target.value; state.page = 1; refresh(); }, 200);
    });
    toolbar.appendChild(search);
    const hint = document.createElement('span');
    hint.className = 'muted';
    hint.style.cssText = 'margin-inline-start:auto;font-size:11px;';
    hint.textContent = data.hint;
    toolbar.appendChild(hint);
    card.appendChild(toolbar);

    card.appendChild(UI.tabs(
      data.tabs.map((t) => t.label),
      (data.tabs.find((t) => t.key === data.filter) || {}).label,
      (label) => {
        state.filter = (data.tabs.find((t) => t.label === label) || {}).key || 'All';
        state.page = 1;
        refresh();
      },
    ));

    const head = document.createElement('div');
    head.className = 'pr-grid coa-head';
    head.innerHTML = ['Staff no', 'Name and post', 'Charged to', 'Gross', 'PAYE', 'NSSF, SHIF, levy', 'Other', 'Net pay']
      .map((h, i) => `<div style="padding:9px 14px;${i >= 3 ? 'text-align:end;' : ''}">${UI.esc(h)}</div>`).join('');
    card.appendChild(head);

    if (data.rows.length) {
      data.rows.forEach((r) => {
        const row = document.createElement('div');
        row.className = 'pr-grid pr-row';
        row.innerHTML = `
          <div class="pr-no">${UI.esc(r.no)}</div>
          <div><span class="pr-name">${UI.esc(r.name)}</span><span class="pr-sub">${UI.esc(r.sub)}</span></div>
          <div class="pr-alloc">${UI.esc(r.alloc)}</div>
          <div class="pr-mono gross" style="text-align:end;">${UI.esc(r.gross)}</div>
          <div class="pr-mono" style="text-align:end;">${UI.esc(r.paye)}</div>
          <div class="pr-mono" style="text-align:end;">${UI.esc(r.stat)}</div>
          <div class="pr-mono" style="text-align:end;">${UI.esc(r.other)}</div>
          <div class="pr-mono strong" style="text-align:end;">${UI.esc(r.net)}</div>`;
        row.addEventListener('click', () => openPayslip(r.no));
        card.appendChild(row);
      });
      if (data.pages > 1) card.appendChild(pager());
    } else {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = 'No one on the run matches that filter.';
      card.appendChild(empty);
    }

    const footer = document.createElement('div');
    footer.className = 'card-footer';
    footer.textContent = data.footer;
    card.appendChild(footer);
    return card;
  }

  function pager() {
    const div = document.createElement('div');
    div.className = 'coa-pager';
    const from = (data.page - 1) * data.pageSize + 1;
    const buttons = [];
    for (let k = 1; k <= data.pages; k++) {
      buttons.push(`<button type="button" class="coa-page ${k === data.page ? 'on' : ''}" data-page="${k}">${k}</button>`);
    }
    div.innerHTML = `
      <span>Showing ${from}–${Math.min(data.filtered, data.page * data.pageSize)} of ${data.filtered} staff</span>
      <div style="margin-inline-start:auto;display:flex;align-items:center;gap:4px;">
        <button type="button" class="coa-page" data-page="${data.page - 1}" ${data.page <= 1 ? 'disabled' : ''}>‹</button>
        ${buttons.join('')}
        <button type="button" class="coa-page" data-page="${data.page + 1}" ${data.page >= data.pages ? 'disabled' : ''}>›</button>
      </div>`;
    div.querySelectorAll('[data-page]').forEach((b) => b.addEventListener('click', () => {
      state.page = +b.dataset.page;
      refresh();
    }));
    return div;
  }

  // ---- What the run owes, charges and posts ----

  function remittances() {
    const r = data.remit;
    const card = document.createElement('div');
    card.className = 'card';
    card.style.margin = '0';
    card.innerHTML = `
      <div class="card-head">
        <span class="card-title">Statutory remittances</span>
        <span class="muted" style="margin-inline-start:auto;font-size:11px;">${UI.esc(r.total)} due by ${UI.esc(r.due)}</span>
      </div>
      ${r.rows.map((x) => `
        <div class="pr-remit">
          <div><span class="pr-remit-name">${UI.esc(x.name)}</span><span class="pr-remit-basis">${UI.esc(x.basis)}</span></div>
          <span><span class="pr-remit-amount">${UI.esc(x.amount)}</span><span class="pr-remit-due">${UI.esc(x.due)}</span></span>
        </div>`).join('')}
      <div class="card-footer" style="display:flex;align-items:center;gap:10px;">
        <span>${r.done ? 'Remitted on ' + UI.esc(r.journal || '') : 'Payroll raises the 22xx liabilities; remitting them is what clears them.'}</span>
        <button type="button" class="btn ${r.can ? 'btn-primary' : ''}" data-remit
                style="margin-inline-start:auto;" ${r.can ? '' : 'disabled'}>${UI.esc(r.label)}</button>
      </div>`;
    card.querySelector('[data-remit]').addEventListener('click', (e) => runAction(e.target, 'remit'));
    return card;
  }

  function charges() {
    const card = document.createElement('div');
    card.className = 'card';
    card.style.margin = '0';
    card.innerHTML = `
      <div class="card-head"><span class="card-title">Where the cost is charged</span></div>
      ${data.allocRows.map((a) => `
        <div class="pr-charge">
          <div class="pr-charge-head">
            <span>${UI.esc(a.label)}</span><b>${UI.esc(a.amount)}</b><span>${a.pct}%</span>
          </div>
          <span>${UI.esc(a.meta)}</span>
          ${UI.bar(a.pct, 'calm')}
        </div>`).join('')}`;
    return card;
  }

  function journal() {
    const j = data.journal;
    const card = document.createElement('div');
    card.className = 'card';
    card.innerHTML = `
      <div class="card-head">
        <span class="card-title">${UI.esc(j.title)}</span>
        <span class="muted" style="margin-inline-start:auto;font-size:11px;">${UI.esc(j.check)}</span>
      </div>
      ${j.lines.map((l) => `
        <div class="pr-jl">
          <b class="${l.side === 'Dr' ? 'dr' : 'cr'}">${l.side}</b>
          <div><span class="pr-jl-account">${UI.esc(l.account)}</span><span class="pr-jl-memo">${UI.esc(l.memo)}</span></div>
          <span>${UI.esc(l.amount)}</span>
        </div>`).join('')}
      <div class="pr-note ${data.actions.posted ? 'done' : 'open'}">${UI.esc(data.actions.note)}</div>`;
    return card;
  }

  // ---- Moving the run on ----

  async function runAction(button, action) {
    button.disabled = true;
    try {
      const result = await UI.postJSON('/api/payroll/' + action, { period: data.period });
      UI.toast(result.message);
      await refresh();
    } catch (err) {
      UI.toast(err.message);
      button.disabled = false;
    }
  }

  // ---- The payslip ----

  async function openPayslip(staffNo) {
    let slip;
    try {
      slip = await UI.fetchJSON('/api/payroll/payslip/' + encodeURIComponent(staffNo) + '?period=' + encodeURIComponent(data.period));
    } catch (err) {
      UI.toast(err.message);
      return;
    }

    const lines = (list) => list.map((d) => `
      <div class="pr-ps-line">
        <div><span>${UI.esc(d.label)}</span>${d.note ? `<span class="pr-ps-note">${UI.esc(d.note)}</span>` : ''}</div>
        <span>${UI.esc(d.value)}</span>
      </div>`).join('');

    UI.drawer(`${slip.no} · ${slip.name}`, `
      <div class="pr-ps">
        <div style="padding:12px 16px;border-bottom:1px solid #E4E2DB;">
          <div style="font-size:11.5px;color:#7A857F;">${UI.esc(slip.role)}</div>
          <div class="muted" style="font-size:10.5px;margin-top:3px;">${UI.esc(slip.meta)} · ${UI.esc(slip.period)}</div>
        </div>
        ${slip.note ? `<div style="padding:9px 16px;background:#FBF0E4;border-bottom:1px solid #F0DFC8;font-size:11.5px;color:#8A5B2E;">${UI.esc(slip.note)}</div>` : ''}

        <div class="card-head"><span class="card-title">Earnings</span></div>
        ${lines(slip.earnings)}
        <div class="pr-ps-line total"><div><span>Gross pay</span></div><span>${UI.esc(slip.gross)}</span></div>

        <div class="card-head"><span class="card-title">Deductions</span></div>
        ${lines(slip.deductions)}
        <div class="pr-ps-line"><div><span>Total deductions</span></div><span>${UI.esc(slip.deducted)}</span></div>
        <div class="pr-ps-line net">
          <div><span>Net pay</span><span class="pr-ps-note">${UI.esc(slip.bank)}</span></div>
          <span>${UI.esc(slip.net)}</span>
        </div>

        <div class="card-head">
          <span class="card-title">Employer's own cost</span>
          <span class="muted" style="margin-inline-start:auto;font-size:10.5px;">not deducted from pay</span>
        </div>
        ${lines(slip.employer)}
        <div class="pr-ps-line total"><div><span>Total cost of this post</span></div><span>${UI.esc(slip.cost)}</span></div>

        <div class="card-head"><span class="card-title">Charged to</span></div>
        ${slip.alloc.map((a) => `
          <div class="pr-ps-line">
            <div><span>${UI.esc(a.label)}</span><span class="pr-ps-note">${UI.esc(a.meta)}</span></div>
            <span class="muted" style="margin-inline-start:auto;font-size:10.5px;">${UI.esc(a.pct)}</span>
            <span style="margin-inline-start:0;width:90px;text-align:end;">${UI.esc(a.amount)}</span>
          </div>`).join('')}
      </div>`, { wide: true });
  }

  // ---- The staff editor ----
  //
  // Four things move a roster: an appointment, a pay award, a re-allocation
  // across grants, and someone leaving. The last three carry the run they take
  // effect from, so an earlier run still recomputes to what it paid.

  const MODES = {
    starter: { label: 'New starter', submit: 'Add to roster', url: '/api/payroll/staff',
      note: 'The starter first appears in the run for the month they join.' },
    pay: { label: 'Salary change', submit: 'Apply pay award', url: '/api/payroll/staff/pay',
      note: 'The award applies from the effective run onward; earlier runs are untouched.' },
    alloc: { label: 'Re-allocation', submit: 'Apply re-allocation', url: '/api/payroll/staff/allocation',
      note: 'The new split applies from the effective run onward, including employer contributions.' },
    leaver: { label: 'Leaver', submit: 'Record leaver', url: '/api/payroll/staff/leaver',
      note: 'Paid pro rata for the days worked in the leaving month, then off the roster.' },
  };

  let form;

  /** A new starter is appointed on the middle of the scale until told otherwise. */
  function defaultGrade(grades) {
    return (grades[Math.floor(grades.length / 2)] || grades[0] || {}).code || '';
  }

  function openEditor(mode, staffNo) {
    const e = data.editor;
    const who = e.staff.find((s) => s.no === staffNo) || e.staff[0] || {};

    form = {
      mode,
      staffNo: who.no || '',
      from: data.period,
      name: '', role: '', sacco: '',
      bankName: '', bankAccount: '', kra: '', nssfNo: '',
      joined: firstOfMonth(data.period), lastDay: firstOfMonth(data.period),
      // A starter is a blank form; the other three open on what the person is
      // paid and charged to now, so a change is made to real figures.
      grade: mode === 'starter' ? defaultGrade(e.grades) : (who.grade || defaultGrade(e.grades)),
      basic: mode === 'pay' ? String(who.basic || '') : '',
      ben: mode === 'pay' ? Object.assign({}, who.ben) : {},
      alloc: mode === 'alloc' && (who.alloc || []).length
        ? who.alloc.map((a) => ({ grant: a.grant, program: a.program, pct: String(a.pct) }))
        : [{ grant: 'Unassigned', program: e.programmes[0] || '', pct: '100' }],
    };
    drawEditor();
  }

  function firstOfMonth(period) {
    const [mon, year] = period.split(' ');
    const m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'].indexOf(mon) + 1;
    return `${year}-${String(m).padStart(2, '0')}-01`;
  }

  function drawEditor() {
    const e = data.editor;
    const m = MODES[form.mode];
    const needsStaff = form.mode !== 'starter';
    const needsFrom = form.mode === 'pay' || form.mode === 'alloc';
    const needsPay = form.mode === 'starter' || form.mode === 'pay';
    const needsAlloc = form.mode === 'starter' || form.mode === 'alloc';
    const pct = form.alloc.reduce((t, a) => t + (parseFloat(a.pct) || 0), 0);
    const grade = e.grades.find((g) => g.code === form.grade);

    const options = (list, current, value = (o) => o, label = (o) => o) => list
      .map((o) => `<option value="${UI.esc(value(o))}" ${value(o) === current ? 'selected' : ''}>${UI.esc(label(o))}</option>`).join('');
    const field = (key, label, attrs = '') => `
      <label class="as-field"><span>${UI.esc(label)}</span>
        <input data-f="${key}" value="${UI.esc(form[key])}" ${attrs}></label>`;

    UI.drawer('Staff editor', `
      <div style="padding:12px 18px;border-bottom:1px solid #EEEDE8;background:#FBFAF7;">
        <div class="tabs" style="margin:0;">
          ${Object.entries(MODES).map(([key, mode]) => `
            <button type="button" class="tab ${key === form.mode ? 'active' : ''}" data-mode="${key}">${UI.esc(mode.label)}</button>`).join('')}
        </div>
      </div>
      <div class="pr-ed">
        <div class="pr-ed-note">${UI.esc(m.note)}</div>

        ${needsStaff ? `
          <label class="as-field"><span>Staff member</span>
            <select data-f="staffNo">${options(e.staff, form.staffNo, (o) => o.no, (o) => o.label)}</select></label>` : ''}

        ${needsFrom ? `
          <label class="as-field"><span>Effective from run</span>
            <select data-f="from">${options(e.periods, form.from)}</select></label>` : ''}

        ${form.mode === 'starter' ? `
          <div class="pr-ed-pair">
            ${field('name', 'Full name', 'placeholder="e.g. Amina Yusuf"')}
            <label class="as-field"><span>Grade</span>
              <select data-f="grade">${options(e.grades, form.grade, (o) => o.code, (o) => o.label)}</select></label>
          </div>
          <div class="pr-ed-pair">
            ${field('role', 'Post', 'placeholder="e.g. Field Coordinator"')}
            ${field('joined', 'Joined', 'type="date"')}
          </div>
          <div class="pr-ed-pair">
            <label class="as-field"><span>Bank</span>
              <input data-f="bankName" value="${UI.esc(form.bankName)}" list="pr-banks" placeholder="e.g. KCB">
              <datalist id="pr-banks">${e.banks.map((b) => `<option value="${UI.esc(b)}"></option>`).join('')}</datalist></label>
            ${field('bankAccount', 'Bank account number', 'inputmode="numeric" placeholder="e.g. 1104882037"')}
          </div>
          <div class="pr-ed-pair">
            ${field('kra', 'KRA PIN', 'placeholder="A000 0000 00X"')}
            ${field('nssfNo', 'NSSF number', 'placeholder="0000 0000"')}
          </div>
          <div class="pr-ed-pair">
            ${field('sacco', 'Sacco deduction', 'inputmode="numeric" placeholder="0"')}
            <span></span>
          </div>` : ''}

        ${form.mode === 'leaver' ? field('lastDay', 'Last day', 'type="date"') : ''}

        ${needsPay ? `
          ${grade ? `<div class="muted" style="font-size:11px;line-height:1.5;">Grade ${UI.esc(grade.code)} awards ${
            e.benefits.map((b) => `${b.name.toLowerCase()} ${b.basis === 'pct' ? (grade.ben[b.key] || 0) + '% of basic' : UI.fmtMoney(grade.ben[b.key] || 0)}`).join(', ')
          }. Leave a benefit blank to take the grade's own figure — set in Settings → Payroll.</div>` : ''}
          <div class="pr-ed-bens">
            ${field('basic', 'Basic', 'inputmode="numeric" style="text-align:end;"')}
            ${e.benefits.map((b) => `
              <label class="as-field"><span>${UI.esc(b.name)}${b.taxable ? '' : ' (non-taxable)'}</span>
                <input data-ben="${UI.esc(b.key)}" value="${UI.esc(form.ben[b.key] ?? '')}" inputmode="numeric" style="text-align:end;"></label>`).join('')}
          </div>` : ''}

        ${needsAlloc ? `
          <div style="display:flex;flex-direction:column;gap:8px;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:#8B948F;">Where the cost is charged</span>
              <button type="button" class="btn" data-alloc-add style="margin-inline-start:auto;padding:4px 10px;font-size:11.5px;">Add split</button>
            </div>
            ${form.alloc.map((a, i) => `
              <div class="pr-ed-alloc">
                <select data-alloc="${i}" data-k="grant">${options(e.grants, a.grant)}</select>
                <select data-alloc="${i}" data-k="program">${options(e.programmes, a.program)}</select>
                <input data-alloc="${i}" data-k="pct" value="${UI.esc(a.pct)}" inputmode="numeric" style="text-align:end;">
                ${form.alloc.length > 1 ? `<button type="button" data-alloc-remove="${i}">×</button>` : '<span></span>'}
              </div>`).join('')}
            <div class="pr-ed-total">
              <span>Total allocated</span>
              <b class="${pct === 100 ? 'ok' : 'bad'}">${pct}%${pct === 100 ? '' : ' — must be 100%'}</b>
            </div>
          </div>` : ''}
      </div>
      <div class="pr-ed-foot">
        <button type="button" class="btn" data-cancel>Cancel</button>
        <button type="button" class="btn btn-primary" data-submit>${UI.esc(m.submit)}</button>
      </div>`);

    bindEditor();
  }

  function bindEditor() {
    const panel = document.querySelector('.rd-body');
    const redraw = () => drawEditor();

    panel.querySelectorAll('[data-mode]').forEach((b) => b.addEventListener('click', () => {
      openEditor(b.dataset.mode, form.staffNo);
    }));
    panel.querySelectorAll('[data-f]').forEach((el) => el.addEventListener('change', () => {
      // Picking a different person reloads the form on their own figures.
      if (el.dataset.f === 'staffNo') { openEditor(form.mode, el.value); return; }
      form[el.dataset.f] = el.value;
      // The grade decides what the benefits default to, so the form is redrawn.
      if (el.dataset.f === 'grade') { form.ben = {}; redraw(); }
    }));
    panel.querySelectorAll('[data-ben]').forEach((el) => el.addEventListener('change', () => {
      form.ben[el.dataset.ben] = el.value;
    }));
    panel.querySelectorAll('[data-alloc]').forEach((el) => el.addEventListener('change', () => {
      form.alloc[+el.dataset.alloc][el.dataset.k] = el.value;
      if (el.dataset.k === 'pct') redraw();
    }));
    const add = panel.querySelector('[data-alloc-add]');
    if (add) add.addEventListener('click', () => {
      form.alloc.push({ grant: 'Unassigned', program: data.editor.programmes[0] || '', pct: '0' });
      redraw();
    });
    panel.querySelectorAll('[data-alloc-remove]').forEach((b) => b.addEventListener('click', () => {
      form.alloc.splice(+b.dataset.allocRemove, 1);
      redraw();
    }));
    panel.querySelector('[data-cancel]').addEventListener('click', () => UI.closeDrawer());
    panel.querySelector('[data-submit]').addEventListener('click', (e) => submitEditor(e.target));
  }

  async function submitEditor(button) {
    const number = (v) => (v === '' || v === undefined || v === null ? '' : parseFloat(String(v).replace(/[^0-9.]/g, '')) || 0);
    const ben = {};
    Object.keys(form.ben).forEach((k) => { if (form.ben[k] !== '') ben[k] = number(form.ben[k]); });
    const alloc = form.alloc.map((a) => ({ grant: a.grant, program: a.program, pct: number(a.pct) }));

    const body = {
      starter: () => ({
        name: form.name, role: form.role, grade: form.grade, basic: number(form.basic), joined: form.joined,
        bankName: form.bankName, bankAccount: form.bankAccount, kra: form.kra, nssfNo: form.nssfNo,
        sacco: number(form.sacco), ben, alloc,
      }),
      pay: () => ({ staffNo: form.staffNo, from: form.from, basic: number(form.basic), ben }),
      alloc: () => ({ staffNo: form.staffNo, from: form.from, alloc }),
      leaver: () => ({ staffNo: form.staffNo, lastDay: form.lastDay }),
    }[form.mode]();

    button.disabled = true;
    try {
      const result = await UI.postJSON(MODES[form.mode].url, body);
      UI.closeDrawer();
      UI.toast(result.message);
      await refresh();
    } catch (err) {
      UI.toast(err.message);
      button.disabled = false;
    }
  }

  refresh();
})();
