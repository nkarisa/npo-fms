/**
 * Settings (v5): organisation, ledger, segments, currencies, approvals, bank
 * statements, payroll, language and translation, users and the audit log.
 *
 * The sections edit one draft, saved together with "Save changes" (or thrown away
 * with "Discard"), so nothing reaches the ledger half-configured and every saved
 * change lands in the audit log. Bank statement formats save as they are made.
 * /settings?section=Currencies opens a section straight away. Only the Finance
 * Manager can save; the API applies every rule again.
 */
(async function () {
  const app = document.getElementById('app');
  const esc = UI.esc;
  const params = new URLSearchParams(location.search);
  const view = {
    section: params.get('section') || 'Organisation',
    userQuery: '', userPage: 0, auditQuery: '', auditPage: 0,
    curForm: { code: '', name: '', rate: '' },
    benForm: { name: '', basis: 'flat', taxable: true },
    gradeForm: { grade: '', band: '', ben: {} },
    newBenefits: 0,
  };
  const USERS_PER_PAGE = 8;
  const AUDIT_PER_PAGE = 10;
  let data = null;
  let draft = null;
  let i18n = null;
  let me = null;

  const plural = (n, one, many) => n + ' ' + (n === 1 ? one : many);
  const num = (v) => parseFloat(String(v).replace(/[^0-9.]/g, '')) || 0;
  const fmt = (n) => Number(n).toLocaleString('en-US');
  const setCookie = (name, value) => { document.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=${60 * 60 * 24 * 365}; SameSite=Lax`; };

  /** The editable part of the settings, in the shape the API saves. */
  function toDraft(d) {
    return structuredClone({
      organisation: d.organisation,
      ledger: d.ledger,
      toggles: Object.fromEntries(d.toggles.map(t => [t.key, t.on])),
      segments: Object.fromEntries(d.segments.map(s => [s.key, s.required])),
      currencies: d.currencies.map(c => ({ code: c.code, name: c.name, rate: c.rate, active: c.active })),
      approvals: Object.fromEntries(d.approvals.map(a => [a.key, { threshold: a.threshold, approver: a.approver }])),
      payroll: {
        benefits: d.benefits.map(b => ({ key: b.key, name: b.name, basis: b.basis, taxable: b.taxable, active: b.active })),
        grades: d.grades.map(g => ({ grade: g.grade, band: g.band, ben: { ...g.ben }, active: g.active })),
      },
      users: Object.fromEntries(d.users.map(u => [u.email, u.role])),
      language: { formatsLocked: d.language.formatsLocked },
    });
  }

  const dirty = () => data && JSON.stringify(draft) !== JSON.stringify(toDraft(data));

  async function load() {
    try {
      data = await UI.fetchJSON('/api/settings');
    } catch (err) {
      app.innerHTML = `<div class="coa-empty">${esc(err.message)}</div>`;
      return;
    }
    draft = toDraft(data);
    if (!data.sections.some(s => s.key === view.section)) view.section = data.sections[0].key;
    shell();
    render();
  }

  function shell() {
    app.innerHTML = `
      <div class="page-head" style="margin-bottom:0;">
        <div>
          <h1 class="page-title" style="margin-top:0;">Settings</h1>
          <p class="page-blurb" style="max-width:660px;">Organisation, ledger and control settings. Changes to approval thresholds and segment rules take effect on the next posting and are recorded in the audit log.</p>
        </div>
        <div class="page-actions" style="margin-left:0;margin-inline-start:auto;">
          <button type="button" class="btn" id="st-discard" hidden>Discard</button>
          <button type="button" class="btn btn-primary" id="st-save"></button>
        </div>
      </div>
      <div class="st-readonly" id="st-readonly" hidden>You can look through the settings. Only the Finance Manager can change them — switch user in the account menu to make a change.</div>
      <div class="st-wrap">
        <nav class="st-nav" id="st-nav" aria-label="Settings sections"></nav>
        <div class="st-main" id="st-main"></div>
      </div>`;

    app.querySelector('#st-save').addEventListener('click', save);
    app.querySelector('#st-discard').addEventListener('click', () => {
      draft = toDraft(data);
      view.curForm = { code: '', name: '', rate: '' };
      view.benForm = { name: '', basis: 'flat', taxable: true };
      view.gradeForm = { grade: '', band: '', ben: {} };
      render();
      UI.toast('Unsaved changes discarded.');
    });
    app.querySelector('#st-nav').addEventListener('click', (e) => {
      const b = e.target.closest('button[data-section]');
      if (!b) return;
      view.section = b.dataset.section;
      history.replaceState(null, '', '/settings?section=' + encodeURIComponent(view.section));
      render();
    });

    const main = app.querySelector('#st-main');
    main.addEventListener('input', (e) => { if (bind(e.target, false)) renderHead(); });
    main.addEventListener('change', (e) => { if (bind(e.target, true)) { renderHead(); renderSection(); } });
    main.addEventListener('click', onAction);
    window.addEventListener('beforeunload', (e) => { if (dirty()) { e.preventDefault(); e.returnValue = ''; } });
  }

  /** Writes an edited field into the draft (or a form). Returns whether anything changed. */
  function bind(el, committed) {
    const path = el.dataset.bind;
    if (!path) return false;
    const value = el.type === 'checkbox' ? el.checked : el.dataset.num !== undefined ? num(el.value) : el.value;
    if (el.type === 'checkbox' && !committed) return false;
    // A user is keyed by email, which has dots of its own.
    const parts = path.startsWith('users.') ? ['users', path.slice(6)] : path.split('.');
    let target = path.startsWith('form.') ? view : draft;
    if (path.startsWith('form.')) parts.shift();
    for (const p of parts.slice(0, -1)) target = target[p] ??= {};
    target[parts[parts.length - 1]] = value;
    if (path.startsWith('view.')) return false;
    return true;
  }

  function render() {
    const nav = app.querySelector('#st-nav');
    nav.innerHTML = data.sections.map(s => `
      <button type="button" data-section="${esc(s.key)}" class="${s.key === view.section ? 'on' : ''}"><span class="st-nav-icon">${esc(s.icon)}</span>${esc(s.label)}</button>`).join('');
    renderHead();
    renderSection();
  }

  function renderHead() {
    const changed = dirty();
    const save = app.querySelector('#st-save');
    save.textContent = changed ? 'Save changes' : 'Saved';
    save.hidden = !data.canManage;
    app.querySelector('#st-discard').hidden = !changed;
    app.querySelector('#st-readonly').hidden = data.canManage;
  }

  function renderSection() {
    const main = app.querySelector('#st-main');
    const renderers = {
      Organisation: organisation, Ledger: ledger, Segments: segments, Currencies: currencies, Approvals: approvals,
      'Bank statements': bankStatements, Payroll: payroll, 'Language and translation': language, Users: users, 'Audit log': audit,
    };
    (renderers[view.section] || organisation)(main);
    if (!data.canManage && view.section !== 'Bank statements' && view.section !== 'Language and translation') {
      main.querySelectorAll('input:not([data-free]), select:not([data-free]), textarea').forEach(el => { el.disabled = true; });
      main.querySelectorAll('[data-manage]').forEach(el => { el.disabled = true; });
    }
  }

  async function save() {
    if (!dirty()) {
      UI.toast('No changes to save.');
      return;
    }
    const button = app.querySelector('#st-save');
    button.disabled = true;
    try {
      const res = await UI.postJSON('/api/settings', draft);
      data = res;
      draft = toDraft(data);
      render();
      UI.toast(res.message);
    } catch (err) {
      UI.toast(err.message);
    } finally {
      button.disabled = false;
    }
  }

  // ------------------------------------------------------------------
  // Building blocks
  // ------------------------------------------------------------------

  const head = (title, note, extra) => `
    <div class="st-head">
      <div><div class="st-kicker">${esc(title)}</div>${note ? `<div class="st-note">${esc(note)}</div>` : ''}</div>
      ${extra || ''}
    </div>`;
  const field = (label, bindPath, value, attrs) => `
    <label class="st-field">${esc(label)}<input data-bind="${bindPath}" value="${esc(value ?? '')}" ${attrs || ''}></label>`;
  const select = (label, bindPath, value, options) => `
    <label class="st-field">${esc(label)}<select data-bind="${bindPath}">${options.map(o => {
      const v = typeof o === 'string' ? o : o.value;
      const t = typeof o === 'string' ? o : o.text;
      return `<option value="${esc(v)}" ${v === value ? 'selected' : ''}>${esc(t)}</option>`;
    }).join('')}</select></label>`;
  const toggleBtn = (on, act, id) => `<button type="button" class="st-toggle ${on ? '' : 'go'}" data-act="${act}" data-id="${esc(id)}" data-manage>${on ? 'Disable' : 'Enable'}</button>`;
  const warn = (text) => text ? `<div class="st-warn">${esc(text)}</div>` : '';
  const rule = '<div class="st-rule"></div>';
  const pager = (key, total, perPage, page, noun) => {
    const pages = Math.ceil(total / perPage);
    if (pages <= 1) return '';
    const from = page * perPage + 1;
    const to = Math.min(total, (page + 1) * perPage);
    return `<div class="st-pager"><span>${from}–${to} of ${total} ${noun}</span>
      <div><button type="button" data-act="page" data-key="${key}" data-id="${page - 1}" ${page === 0 ? 'disabled' : ''}>‹</button>
      ${Array.from({ length: pages }, (_, i) => `<button type="button" data-act="page" data-key="${key}" data-id="${i}" class="${i === page ? 'on' : ''}">${i + 1}</button>`).join('')}
      <button type="button" data-act="page" data-key="${key}" data-id="${page + 1}" ${page >= pages - 1 ? 'disabled' : ''}>›</button></div></div>`;
  };

  // ------------------------------------------------------------------
  // Sections
  // ------------------------------------------------------------------

  function organisation(main) {
    const o = draft.organisation;
    main.innerHTML = `
      <div class="st-body">
        <div class="st-block">
          <div class="st-kicker">Organisation</div>
          <div class="st-grid">
            ${field('Registered name', 'organisation.registeredName', o.registeredName)}
            ${field('Short name', 'organisation.shortName', o.shortName)}
            ${field('KRA PIN', 'organisation.taxPin', o.taxPin, 'class="mono"')}
            ${field('NGO Board registration', 'organisation.ngoReg', o.ngoReg, 'class="mono"')}
          </div>
        </div>
        ${rule}
        <div class="st-block">
          <div class="st-kicker">Entities</div>
          <div class="st-table"><div style="min-width:480px;">
            <div class="st-tr st-th" style="grid-template-columns:minmax(0,1fr) 140px 108px 92px;"><div>Entity</div><div>Type</div><div>Currency</div><div>Status</div></div>
            ${data.entities.map(e => `
              <div class="st-tr" style="grid-template-columns:minmax(0,1fr) 140px 108px 92px;">
                <div class="st-ellipsis">${esc(e.name)}</div><div class="st-muted">${esc(e.type)}</div><div class="st-muted mono">${esc(e.currency)}</div>
                <div>${e.status === 'Live' ? '<span class="st-live">● Live</span>' : '<span class="st-dormant">○ Dormant</span>'}</div>
              </div>`).join('')}
          </div></div>
          <div class="st-note">Consolidation eliminates inter-entity balances automatically. Regional offices post into the shared master chart of accounts.</div>
        </div>
      </div>`;
  }

  function ledger(main) {
    const l = draft.ledger;
    const opt = data.ledgerOptions;
    main.innerHTML = `
      <div class="st-body">
        <div class="st-block">
          <div class="st-kicker">Reporting basis</div>
          <div class="st-grid">
            ${select('Framework', 'ledger.framework', l.framework, opt.frameworks)}
            ${select('Functional currency', 'ledger.currency', l.currency, opt.currencies)}
            ${select('Financial year end', 'ledger.yearEnd', l.yearEnd, opt.yearEnds)}
            ${select('Account code length', 'ledger.codeLength', l.codeLength, opt.codeLengths)}
          </div>
        </div>
        ${rule}
        <div class="st-block">
          <div class="st-kicker">Posting controls</div>
          <div>
            ${data.toggles.map(t => `
              <label class="st-check">
                <input type="checkbox" data-bind="toggles.${esc(t.key)}" ${draft.toggles[t.key] ? 'checked' : ''}>
                <span><span class="st-check-label">${esc(t.label)}</span><span class="st-check-note">${esc(t.note)}</span></span>
              </label>`).join('')}
          </div>
        </div>
        ${rule}
        <div class="st-block">
          <div class="st-kicker">Open periods</div>
          <div class="st-chips">${data.periods.map(p => `<span class="st-chip ${p.open ? 'open' : ''}">${esc(p.label)} · ${p.open ? 'open' : 'closed'}</span>`).join('')}</div>
          <div class="st-note">Periods are closed and reopened from <a href="/period-close">Period close</a>, with the management review and authorisation.</div>
        </div>
      </div>`;
  }

  function segments(main) {
    const open = data.segments.filter(s => !draft.segments[s.key] && ['grant', 'restriction', 'fund'].includes(s.key));
    main.innerHTML = `
      <div class="st-body wide">
        ${head('Accounting segments', 'Which dimensions every posting must carry. Marking a segment required blocks any journal or bill that leaves it blank.')}
        <div class="st-table"><div style="min-width:620px;">
          <div class="st-tr st-th" style="grid-template-columns:minmax(150px,1fr) 92px 108px 96px 128px;"><div>Segment</div><div class="end">Values</div><div>Required</div><div>On reports</div><div>Applies to</div></div>
          ${data.segments.map(s => `
            <div class="st-tr" style="grid-template-columns:minmax(150px,1fr) 92px 108px 96px 128px;">
              <div class="st-stack"><span>${esc(s.name)}</span><span class="st-sub st-ellipsis">${esc(s.example)}</span></div>
              <div class="end mono st-muted">${s.count}</div>
              <div><label class="st-inline"><input type="checkbox" data-bind="segments.${esc(s.key)}" ${draft.segments[s.key] ? 'checked' : ''}>${draft.segments[s.key] ? 'Mandatory' : 'Optional'}</label></div>
              <div>${s.reported ? '<span class="st-live">✓ Shown</span>' : '<span class="st-sub">Internal</span>'}</div>
              <div class="st-muted st-ellipsis">${esc(s.applies)}</div>
            </div>`).join('')}
        </div></div>
        ${warn(open.length ? `The ${open.map(s => s.name.toLowerCase()).join(' and ')} segment is optional. Postings without it cannot be traced to a donor, and donor reports will not reconcile to the ledger.` : '')}
      </div>`;
  }

  function currencies(main) {
    const base = draft.ledger.currency;
    const held = Object.fromEntries(data.currencies.map(c => [c.code, c]));
    const f = view.curForm;
    main.innerHTML = `
      <div class="st-body wide">
        ${head('Currencies', 'Which currencies awards and donor claims may be denominated in. The reporting currency cannot be disabled, and neither can one an open claim is already stated in — disabling only removes a currency from future forms, it never restates what is posted.')}
        <div class="st-table"><div style="min-width:660px;">
          <div class="st-tr st-th" style="grid-template-columns:76px minmax(140px,1fr) 140px 168px 104px;"><div>Code</div><div>Currency</div><div class="end">Indicative rate</div><div>Used by</div><div class="end">Status</div></div>
          ${draft.currencies.map((c, i) => {
            const h = held[c.code];
            const isBase = c.code === base;
            const usage = isBase ? 'Ledger, payroll and every statement'
              : !h ? 'New — not used yet'
              : h.claims || h.awards ? [h.claims ? plural(h.claims, 'open claim', 'open claims') : '', h.awards ? plural(h.awards, 'award', 'awards') : ''].filter(Boolean).join(' · ') : 'Not used yet';
            return `
            <div class="st-tr" style="grid-template-columns:76px minmax(140px,1fr) 140px 168px 104px;min-height:44px;">
              <div class="mono st-strong">${esc(c.code)}</div>
              <div class="st-stack"><span>${esc(c.name)}</span><span class="st-sub">${isBase ? 'Everything is reported in this currency' : c.active ? 'Available on awards and donor claims' : 'Hidden from new awards and claims'}</span></div>
              <div class="end">${isBase ? '<span class="st-sub">reporting currency</span>' : `<input class="st-cell mono end" data-bind="currencies.${i}.rate" value="${esc(c.rate)}" inputmode="decimal" aria-label="${esc(c.code)} rate">`}</div>
              <div class="st-muted st-ellipsis">${esc(usage)}</div>
              <div class="end">${isBase ? '<span class="st-pill-base">Base</span>' : toggleBtn(c.active, 'cur-toggle', i)}</div>
            </div>`;
          }).join('')}
        </div></div>
        <div class="st-addbox">
          <span class="st-addtitle">Add a currency</span>
          <div class="st-addrow">
            <label class="st-field" style="flex:0 0 88px;">Code<input data-bind="form.curForm.code" value="${esc(f.code)}" placeholder="SEK" maxlength="3" class="mono upper"></label>
            <label class="st-field" style="flex:1 1 200px;min-width:160px;">Currency name<input data-bind="form.curForm.name" value="${esc(f.name)}" placeholder="Swedish Krona"></label>
            <label class="st-field" style="flex:0 0 130px;">Rate to ${esc(base)}<input data-bind="form.curForm.rate" value="${esc(f.rate)}" placeholder="12.40" class="mono end" inputmode="decimal"></label>
            <button type="button" class="btn btn-primary" data-act="cur-add" data-manage>Add currency</button>
          </div>
          <span class="st-sub">The rate is indicative only — it seeds the agreement rate on a new award and the claim rate on a donor invoice, and can be overridden on either.</span>
        </div>
      </div>`;
  }

  function approvals(main) {
    main.innerHTML = `
      <div class="st-body wide">
        ${head('Approval thresholds', 'Bands are read from the bottom up: a transaction takes the highest band its value reaches. Every band above nil enforces the two-person rule.')}
        <div class="st-table"><div style="min-width:640px;">
          <div class="st-tr st-th" style="grid-template-columns:minmax(140px,1fr) 152px 190px 160px;"><div>Transaction type</div><div class="end">Threshold (KES)</div><div>Approver</div><div>Above threshold</div></div>
          ${data.approvals.map(a => {
            const d = draft.approvals[a.key];
            const nil = num(d.threshold) === 0;
            const roles = data.approverRoles.includes(d.approver) ? data.approverRoles : [d.approver, ...data.approverRoles];
            return `
            <div class="st-tr" style="grid-template-columns:minmax(140px,1fr) 152px 190px 160px;">
              <div class="st-ellipsis">${esc(a.label)}</div>
              <div><input class="st-cell mono end" data-num data-bind="approvals.${esc(a.key)}.threshold" value="${esc(nil ? 'nil — always' : fmt(num(d.threshold)))}" aria-label="${esc(a.label)} threshold"></div>
              <div><select class="st-cell" data-bind="approvals.${esc(a.key)}.approver" aria-label="${esc(a.label)} approver">${roles.map(r => `<option ${r === d.approver ? 'selected' : ''}>${esc(r)}</option>`).join('')}</select></div>
              <div class="st-muted st-ellipsis">${nil ? 'Every transaction' : 'Above: ' + esc(a.escalation)}</div>
            </div>`;
          }).join('')}
        </div></div>
        <div class="st-infobox">
          <div class="st-kicker">Segregation of duties</div>
          ${data.sodRules.map(r => `<div class="st-bullet"><span>·</span>${esc(r)}</div>`).join('')}
        </div>
      </div>`;
  }

  function bankStatements(main) {
    main.innerHTML = `<div class="st-body wide">${head('Bank statements', 'How each bank\'s CSV statement maps onto the reconciliation, and which format each account uses. Changes in this section are saved as you make them.')}<div id="st-formats"></div></div>`;
    StatementFormats.mount(main.querySelector('#st-formats'));
  }

  function payroll(main) {
    const benefits = draft.payroll.benefits;
    const live = benefits.filter(b => b.active);
    const held = Object.fromEntries(data.benefits.map(b => [b.key, b]));
    const grades = Object.fromEntries(data.grades.map(g => [g.grade, g]));
    const colLabel = (b) => b.name + (b.basis === 'pct' ? ' (% basic)' : ' (KES)');
    const gradeCols = `72px minmax(140px,1fr) ${live.map(() => '130px').join(' ')} 90px 104px`;
    const f = view.gradeForm;
    const bf = view.benForm;

    main.innerHTML = `
      <div class="st-body wide">
        ${head('Benefits', 'What the organisation pays on top of basic. Each benefit becomes a column on the grade scale below, a line on every payslip, and part of gross pay — mark one non-taxable and it is excluded from taxable pay but still paid.')}
        <div class="st-table"><div style="min-width:620px;">
          <div class="st-tr st-th" style="grid-template-columns:minmax(160px,1fr) 150px 118px 96px 104px;"><div>Benefit</div><div>Basis</div><div>Taxable</div><div class="end">Paid to</div><div class="end">Status</div></div>
          ${benefits.map((b, i) => `
            <div class="st-tr ${b.active ? '' : 'off'}" style="grid-template-columns:minmax(160px,1fr) 150px 118px 96px 104px;">
              <div><input class="st-cell" data-bind="payroll.benefits.${i}.name" value="${esc(b.name)}" aria-label="Benefit name"></div>
              <div><select class="st-cell boxed" data-bind="payroll.benefits.${i}.basis"><option value="pct" ${b.basis === 'pct' ? 'selected' : ''}>% of basic</option><option value="flat" ${b.basis === 'flat' ? 'selected' : ''}>Flat KES</option></select></div>
              <div><label class="st-inline"><input type="checkbox" data-bind="payroll.benefits.${i}.taxable" ${b.taxable ? 'checked' : ''}>${b.taxable ? 'Taxable' : 'Exempt'}</label></div>
              <div class="end mono st-muted">${held[b.key] && held[b.key].paidTo ? held[b.key].paidTo : '—'}</div>
              <div class="end">${toggleBtn(b.active, 'ben-toggle', i)}</div>
            </div>`).join('')}
        </div></div>
        <div class="st-addbox">
          <span class="st-addtitle">Add a benefit</span>
          <div class="st-addrow">
            <label class="st-field" style="flex:1 1 200px;min-width:160px;">Name on the payslip<input data-bind="form.benForm.name" value="${esc(bf.name)}" placeholder="Airtime allowance"></label>
            <label class="st-field" style="flex:0 0 150px;">Basis<select data-bind="form.benForm.basis"><option value="flat" ${bf.basis === 'flat' ? 'selected' : ''}>Flat KES</option><option value="pct" ${bf.basis === 'pct' ? 'selected' : ''}>% of basic</option></select></label>
            <label class="st-inline" style="height:32px;"><input type="checkbox" data-bind="form.benForm.taxable" ${bf.taxable ? 'checked' : ''}>Taxable</label>
            <button type="button" class="btn btn-primary" data-act="ben-add" data-manage>Add benefit</button>
          </div>
        </div>

        ${head('Grades and benefits', 'What each benefit is worth at each grade. A new starter takes these automatically from the grade they are appointed to; existing staff keep the figures on their record until a salary change is applied, which then picks up any benefit added since.')}
        <div class="st-table"><div style="min-width:${400 + live.length * 130}px;">
          <div class="st-tr st-th" style="grid-template-columns:${gradeCols};"><div>Grade</div><div>Band</div>${live.map(b => `<div class="end">${esc(colLabel(b))}</div>`).join('')}<div class="end">On grade</div><div class="end">Status</div></div>
          ${draft.payroll.grades.map((g, i) => `
            <div class="st-tr ${g.active ? '' : 'off'}" style="grid-template-columns:${gradeCols};">
              <div class="mono st-grade">${esc(g.grade)}</div>
              <div><input class="st-cell" data-bind="payroll.grades.${i}.band" value="${esc(g.band)}" aria-label="${esc(g.grade)} band"></div>
              ${live.map(b => `<div><input class="st-cell mono end" data-num data-bind="payroll.grades.${i}.ben.${esc(b.key)}" value="${esc(g.ben[b.key] ?? '')}" inputmode="decimal" aria-label="${esc(g.grade)} ${esc(b.name)}"></div>`).join('')}
              <div class="end mono st-muted">${grades[g.grade] && grades[g.grade].staff ? grades[g.grade].staff : '—'}</div>
              <div class="end">${toggleBtn(g.active, 'grade-toggle', i)}</div>
            </div>`).join('')}
        </div></div>
        <div class="st-addbox">
          <span class="st-addtitle">Add a grade</span>
          <div class="st-addrow">
            <label class="st-field" style="flex:0 0 80px;">Grade<input data-bind="form.gradeForm.grade" value="${esc(f.grade)}" placeholder="G8" maxlength="5" class="mono upper"></label>
            <label class="st-field" style="flex:1 1 200px;min-width:150px;">Band<input data-bind="form.gradeForm.band" value="${esc(f.band)}" placeholder="Intern"></label>
            ${live.map(b => `<label class="st-field" style="flex:0 0 132px;">${esc(colLabel(b))}<input data-bind="form.gradeForm.ben.${esc(b.key)}" value="${esc(f.ben[b.key] ?? '')}" class="mono end" inputmode="decimal"></label>`).join('')}
            <button type="button" class="btn btn-primary" data-act="grade-add" data-manage>Add grade</button>
          </div>
        </div>
        <div class="st-infobox">
          <div class="st-kicker">How this is applied</div>
          <div class="st-bullet"><span>·</span>House allowance is calculated on basic pay and is fully taxable.</div>
          <div class="st-bullet"><span>·</span>Transport is a flat monthly figure per grade and forms part of gross pay for PAYE, NSSF, SHIF and the housing levy.</div>
          <div class="st-bullet"><span>·</span>Changing a grade here does not restate posted payroll runs — apply a salary change with an effective run instead.</div>
          <div class="st-bullet"><span>·</span>A disabled grade is withdrawn from new appointments only. Staff already on it keep their terms, so a grade in use cannot be disabled.</div>
        </div>
      </div>`;
  }

  function users(main) {
    const q = view.userQuery.trim().toLowerCase();
    const list = data.users.filter(u => !q || [u.name, u.email, draft.users[u.email], u.entities].join(' ').toLowerCase().includes(q));
    const pages = Math.max(1, Math.ceil(list.length / USERS_PER_PAGE));
    view.userPage = Math.min(view.userPage, pages - 1);
    const shown = list.slice(view.userPage * USERS_PER_PAGE, (view.userPage + 1) * USERS_PER_PAGE);
    const count = (s) => data.users.filter(u => u.status === s).length;
    const holders = (role) => data.users.filter(u => u.status === 'Active' && draft.users[u.email] === role);
    const fm = holders('Finance Manager').length;
    const ed = holders('Executive Director').length;
    const warning = fm === 0 ? 'No active Finance Manager. Journal and bill approvals above their thresholds cannot be actioned until one is assigned.'
      : ed === 0 ? 'No active Executive Director. Payment runs, sub-grants and inter-fund transfers will queue without an approver.'
      : fm > 2 ? `${fm} users hold the Finance Manager role. Auditors normally expect this to be limited to one or two.` : '';

    main.innerHTML = `
      <div class="st-body wide">
        <div class="st-head">
          <div><div class="st-kicker">Users and roles</div><div class="st-note">${count('Active')} active users · ${count('Invited')} invited · ${count('Suspended')} suspended</div></div>
          <label class="st-search">⌕<input data-free data-query="user" value="${esc(view.userQuery)}" placeholder="Name, email or role"></label>
          <button type="button" class="btn" data-act="invite" data-manage>Invite user</button>
        </div>
        <div class="st-table"><div style="min-width:700px;">
          <div class="st-tr st-th" style="grid-template-columns:minmax(170px,1fr) 178px 150px 120px 96px;"><div>User</div><div>Role</div><div>Entity access</div><div>Last active</div><div>Status</div></div>
          ${shown.map(u => `
            <div class="st-tr" style="grid-template-columns:minmax(170px,1fr) 178px 150px 120px 96px;min-height:44px;">
              <div class="st-user"><span class="st-avatar">${esc(u.initials)}</span><span class="st-stack"><span class="st-ellipsis">${esc(u.name)}</span><span class="st-sub st-ellipsis">${esc(u.email)}</span></span></div>
              <div><select class="st-cell" data-bind="users.${esc(u.email)}" aria-label="Role for ${esc(u.name)}">${data.roles.map(r => `<option ${r === draft.users[u.email] ? 'selected' : ''}>${esc(r)}</option>`).join('')}</select></div>
              <div class="st-ellipsis">${esc(u.entities)}</div>
              <div class="st-muted">${esc(u.lastActive)}</div>
              <div>${u.status === 'Active' ? '<span class="st-live">● Active</span>' : u.status === 'Invited' ? '<span class="st-invited">◐ Invited</span>' : '<span class="st-dormant">○ Suspended</span>'}</div>
            </div>`).join('') || '<div class="coa-empty">No users match.</div>'}
          ${pager('user', list.length, USERS_PER_PAGE, view.userPage, 'users')}
        </div></div>
        ${warn(warning)}
      </div>`;
    wireQuery(main, 'user');
  }

  function audit(main) {
    const q = view.auditQuery.trim().toLowerCase();
    const list = data.audit.filter(a => !q || [a.when, a.who, a.what, a.area].join(' ').toLowerCase().includes(q));
    const pages = Math.max(1, Math.ceil(list.length / AUDIT_PER_PAGE));
    view.auditPage = Math.min(view.auditPage, pages - 1);
    const shown = list.slice(view.auditPage * AUDIT_PER_PAGE, (view.auditPage + 1) * AUDIT_PER_PAGE);

    main.innerHTML = `
      <div class="st-body wide">
        <div class="st-head">
          <div><div class="st-kicker">Audit log</div><div class="st-note">Every configuration and posting-control change, retained for seven years. The log cannot be edited or cleared from within the application.</div></div>
          <label class="st-search">⌕<input data-free data-query="audit" value="${esc(view.auditQuery)}" placeholder="User, change or area"></label>
        </div>
        <div class="st-table"><div style="min-width:620px;">
          <div class="st-tr st-th" style="grid-template-columns:118px 132px minmax(220px,1fr) 150px;"><div>When</div><div>User</div><div>Change</div><div>Area</div></div>
          ${shown.map(a => `
            <div class="st-tr" style="grid-template-columns:118px 132px minmax(220px,1fr) 150px;min-height:38px;">
              <div class="mono st-muted" style="font-size:11px;">${esc(a.when)}</div>
              <div class="st-ellipsis">${esc(a.who)}</div>
              <div style="padding-block:6px;">${esc(a.what)}</div>
              <div class="st-muted" style="font-size:11px;">${esc(a.area)}</div>
            </div>`).join('') || '<div class="coa-empty">No changes match.</div>'}
          ${pager('audit', list.length, AUDIT_PER_PAGE, view.auditPage, 'entries')}
        </div></div>
      </div>`;
    wireQuery(main, 'audit');
  }

  /** Search boxes filter as you type without losing focus. */
  function wireQuery(main, key) {
    const input = main.querySelector(`input[data-query="${key}"]`);
    input.disabled = false;
    input.addEventListener('input', () => {
      view[key + 'Query'] = input.value;
      view[key + 'Page'] = 0;
      const pos = input.selectionStart;
      renderSection();
      const again = app.querySelector(`input[data-query="${key}"]`);
      again.focus();
      again.setSelectionRange(pos, pos);
    });
  }

  // ---- Language and translation ----

  async function language(main) {
    if (!i18n) {
      main.innerHTML = '<div class="coa-empty">Loading languages…</div>';
      try {
        [i18n, me] = await Promise.all([UI.fetchJSON('/api/i18n'), me ? Promise.resolve(me) : UI.fetchJSON('/api/me')]);
      } catch (err) {
        main.innerHTML = `<div class="coa-empty">${esc(err.message)}</div>`;
        return;
      }
      if (view.section !== 'Language and translation') return;
    }
    const current = i18n.locales.find(l => l.current) || i18n.locales[0];
    const isSource = current.source;
    const canDecide = me && me.me.canApprove;
    const tone = (status) => ({ Published: 'posted', Source: 'approved', 'In review': 'pending', Approved: 'posted', Declined: 'reversed', 'With reviewer': 'pending' }[status] || 'draft');
    const covColour = (pct) => pct >= 95 ? '#2C6B58' : pct >= 80 ? '#8A6A2E' : '#A45B3E';
    const open = i18n.requests.filter(q => q.open).length;
    const locked = draft.language.formatsLocked;

    main.innerHTML = `
      <div class="st-body wider">
        ${head('Interface languages', i18n.note)}
        <div class="st-table"><div style="min-width:760px;">
          <div class="st-tr st-th" style="grid-template-columns:minmax(180px,1fr) 96px 150px minmax(150px,1fr) 104px 116px;"><div>Language</div><div>Direction</div><div>Coverage</div><div>Reviewer of record</div><div>Status</div><div></div></div>
          ${i18n.locales.map(l => `
            <div class="st-tr ${l.current ? 'current' : ''}" style="grid-template-columns:minmax(180px,1fr) 96px 150px minmax(150px,1fr) 104px 116px;min-height:46px;">
              <div class="st-stack"><span class="st-strong">${esc(l.label)}</span><span class="st-sub">${esc(l.native)} · ${esc(l.code)}</span></div>
              <div class="mono st-muted" style="font-size:11px;">${esc(l.dirLabel)}</div>
              <div class="st-stack" style="gap:4px;"><span class="mono" style="font-size:11.5px;">${l.coverage}%${l.untranslated ? ` · ${l.untranslated} untranslated` : ''}</span><span class="st-bar"><span style="width:${l.coverage}%;background:${covColour(l.coverage)};"></span></span></div>
              <div class="st-muted st-ellipsis">${esc(l.reviewer || '—')}</div>
              <div><span class="jr-pill ${tone(l.status)}">${esc(l.status)}</span></div>
              <div>${l.current ? '<span class="st-live" style="font-weight:600;">✓ Viewing</span>' : `<button type="button" class="btn st-small" data-act="lang-preview" data-id="${esc(l.code)}">Preview</button>`}</div>
            </div>`).join('')}
        </div></div>

        ${rule}
        <div class="st-block">
          <div class="st-kicker">Fallback behaviour</div>
          <div class="st-note">What a user sees when a string has no approved translation in ${esc(current.native)}.</div>
          ${i18n.fallbacks.map(o => `
            <label class="st-check ${o.on ? 'on' : ''}">
              <input type="radio" name="st-fallback" data-free data-act-change="fallback" value="${esc(o.key)}" ${o.on ? 'checked' : ''}>
              <span><span class="st-check-label">${esc(o.label)}</span><span class="st-check-note">${esc(o.note)}</span><span class="st-check-example mono">${esc(o.example)}</span></span>
            </label>`).join('')}
          <div class="st-amber">${esc(isSource ? 'English (UK) is the source language. Every string here is the wording other languages are translated from.' : `${current.native} is ${current.coverage}% translated by ${current.reviewer}. The strings below are still waiting on an approved translation and currently fall back to English:`)}</div>
          ${isSource ? '' : `<div class="st-chips">${i18n.missing.slice(0, 10).map(s => `<button type="button" class="st-raise mono" data-act="raise" data-id="${esc(s)}" title="Raise this label with the reviewer">${esc(s)}<span>＋</span></button>`).join('')}</div>`}
        </div>

        ${rule}
        <div class="st-block">
          <div class="st-head" style="margin:0;">
            <div><div class="st-kicker">Raised for review</div><div class="st-note">${esc(canDecide ? 'Raised wording sits here until the reviewer of record decides. Approving publishes it immediately and writes to the audit log — a translation change alters what a funder reads.' : 'Raised wording sits here until the reviewer of record decides. You can raise items; publishing is theirs.')}</div></div>
            <span class="st-sub" style="white-space:nowrap;">${open ? plural(open, 'item open', 'items open') + ' · ' : ''}${i18n.requests.length} raised in total</span>
          </div>
          <div class="st-table"><div style="min-width:744px;">
            <div class="st-tr st-th" style="grid-template-columns:minmax(140px,1fr) 96px minmax(180px,1.2fr) 140px 156px;"><div>String</div><div>Language</div><div>Raised</div><div>By</div><div>Status</div></div>
            ${i18n.requests.map(q => `
              <div class="st-tr" style="grid-template-columns:minmax(140px,1fr) 96px minmax(180px,1.2fr) 140px 156px;min-height:46px;">
                <div class="st-stack"><span class="mono">${esc(q.str)}</span><span class="st-sub">${esc(q.area)}</span></div>
                <div class="st-muted">${esc(q.lang)}</div>
                <div class="st-stack"><span>${esc(q.what)}</span><span class="st-sub">${esc(q.kind)}</span></div>
                <div class="st-stack"><span class="st-ellipsis">${esc(q.who)}</span><span class="st-sub mono">${esc(q.when)}</span></div>
                <div class="st-stack" style="gap:5px;align-items:flex-start;padding-block:6px;">
                  <span class="jr-pill ${tone(q.status)}">${esc(q.status)}</span>
                  ${q.open && canDecide ? `<span style="display:flex;gap:5px;"><button type="button" class="btn btn-primary st-small" data-act="rq-approve" data-id="${esc(q.id)}">Approve</button><button type="button" class="btn st-small" data-act="rq-decline" data-id="${esc(q.id)}">Decline</button></span>` : ''}
                  ${q.open && !canDecide ? '<span class="st-sub">Reviewer decides</span>' : ''}
                </div>
              </div>`).join('') || '<div class="coa-empty" style="padding:26px 14px;">Nothing raised. Use the raise button on an untranslated label to send its wording to the reviewer.</div>'}
          </div></div>
        </div>

        ${rule}
        <div class="st-block">
          <div class="st-kicker">Numbers, dates and currency</div>
          <label class="st-check">
            <input type="checkbox" data-bind="language.formatsLocked" ${locked ? 'checked' : ''}>
            <span><span class="st-check-label">Hold numbers, dates and currency in the organisation's reporting locale (en-KE · KES)</span><span class="st-check-note">Recommended. Finance staff, auditors and funders read the same figure the same way in every language, so a report cannot be misread as a different amount.</span></span>
          </label>
          <div class="st-table"><div style="min-width:490px;">
            <div class="st-tr st-th" style="grid-template-columns:minmax(0,1.1fr) minmax(0,1fr) minmax(0,1fr);"><div>Value</div><div>As posted (en-KE · KES)</div><div>${esc(isSource ? 'In the browsing locale' : `In ${current.native} (${current.code})`)}</div></div>
            ${i18n.formats.rows.map(r => `
              <div class="st-tr" style="grid-template-columns:minmax(0,1.1fr) minmax(0,1fr) minmax(0,1fr);">
                <div class="st-muted">${esc(r.label)}</div>
                <div class="mono ${locked ? 'st-strong' : 'st-faint'}">${esc(r.reporting)}</div>
                <div class="mono ${locked ? 'st-faint' : 'st-strong'}">${esc(r.localised)}</div>
              </div>`).join('')}
          </div></div>
          <div class="st-note">${esc(locked ? 'Held. Amounts, dates and percentages render exactly as posted, in every language, on screen and in every export.' : 'Not held. The same posted amount will appear in different notations to different users — a reconciliation risk on any figure a funder queries.')}</div>
        </div>

        ${rule}
        <div class="st-block">
          <div class="st-kicker">Locked terminology</div>
          <div class="st-note">Regulated terms carry one approved translation each. Translators cannot edit these in the catalogue — an unlock is a named request, and the change is versioned with the financial statements.</div>
          <div class="st-table"><div style="min-width:660px;">
            <div class="st-tr st-th" style="grid-template-columns:minmax(150px,1fr) minmax(170px,1fr) minmax(220px,1.3fr) 120px;"><div>Term (source)</div><div>${esc(isSource ? 'Approved translations' : 'Approved in ' + current.native)}</div><div>Why it is locked</div><div>Unlock</div></div>
            ${i18n.locked.map(t => `
              <div class="st-tr" style="grid-template-columns:minmax(150px,1fr) minmax(170px,1fr) minmax(220px,1.3fr) 120px;min-height:42px;">
                <div class="st-strong" style="font-weight:400;">${esc(t.term)}</div>
                <div>${esc(t.translated)}</div>
                <div class="st-muted" style="padding-block:6px;">${esc(t.note)}</div>
                <div class="st-stack" style="gap:3px;"><span class="st-sub" style="color:#8B7F6E;">⌷ ${esc(t.unlock)}</span><button type="button" class="br-link" data-act="unlock" data-id="${esc(t.term)}" style="text-align:start;">Request unlock</button></div>
              </div>`).join('')}
          </div></div>
        </div>

        ${rule}
        <div class="st-block">
          <div class="st-kicker">Coverage by area</div>
          <div class="st-areas">${i18n.coverage.map(a => `
            <div class="st-area">
              <div style="display:flex;align-items:center;gap:8px;"><span class="st-strong" style="font-size:12px;">${esc(a.area)}</span><span class="mono" style="margin-inline-start:auto;font-size:11.5px;color:${covColour(a.coverage)};">${a.coverage}%</span></div>
              <span class="st-bar"><span style="width:${a.coverage}%;background:${covColour(a.coverage)};"></span></span>
              <span class="st-sub">${esc(a.note)}</span>
            </div>`).join('')}</div>
        </div>
      </div>`;

    if (!data.canManage) main.querySelector('input[data-bind="language.formatsLocked"]').disabled = true;
    main.querySelectorAll('input[data-act-change="fallback"]').forEach(r => r.addEventListener('change', () => {
      if (dirty()) {
        UI.toast('Save or discard your changes before switching how untranslated text is shown.');
        r.checked = false;
        return;
      }
      setCookie('elog_i18n_fallback', r.value);
      location.reload();
    }));
  }

  /** A suggestion for an untranslated label, or a named request to unlock a regulated term. */
  function openRaise(str, mode) {
    const current = i18n.locales.find(l => l.current);
    if (!current || current.source) {
      UI.toast('Switch the interface to the language the wording is for, then raise it from there.');
      return;
    }
    const unlock = mode === 'unlock';
    UI.drawer(unlock ? 'Request unlock' : 'Raise wording', `
      <div class="bu" id="st-raise">
        <p class="bu-intro">${unlock
          ? `“${esc(str)}” is locked terminology. The approved wording stays in place; the request goes to the unlock authority and any change is versioned with the financial statements.`
          : `“${esc(str)}” has no approved ${esc(current.native)} translation. Your suggestion goes to ${esc(current.reviewer)}, reviewer of record — the label is unchanged until they approve.`}</p>
        <label class="bu-field"><span>${unlock ? 'Why it should change' : 'The wording your team uses'}</span><textarea id="st-raise-text" rows="3"></textarea></label>
        <label class="bu-field"><span>Raised on behalf of <em>optional</em></span><input id="st-raise-who" maxlength="120"></label>
        <div class="bu-actions"><button type="button" class="btn" id="st-raise-cancel">Cancel</button><button type="button" class="btn btn-primary" id="st-raise-send">${unlock ? 'Send request' : 'Raise with reviewer'}</button></div>
      </div>`);
    const box = document.getElementById('st-raise');
    box.querySelector('#st-raise-cancel').addEventListener('click', UI.closeDrawer);
    box.querySelector('#st-raise-send').addEventListener('click', async () => {
      try {
        const res = await UI.postJSON('/api/i18n/requests', {
          str, locale: current.code, mode: unlock ? 'unlock' : 'suggest',
          text: box.querySelector('#st-raise-text').value, who: box.querySelector('#st-raise-who').value,
        });
        UI.closeDrawer();
        UI.toast(res.note);
        i18n = null;
        renderSection();
      } catch (err) {
        UI.toast(err.message);
      }
    });
  }

  // ------------------------------------------------------------------
  // Actions
  // ------------------------------------------------------------------

  async function onAction(e) {
    const btn = e.target.closest('[data-act]');
    if (!btn || btn.disabled || btn.dataset.actChange) return;
    const act = btn.dataset.act;
    const id = btn.dataset.id;

    if (act === 'page') {
      view[btn.dataset.key + 'Page'] = Number(id);
      renderSection();
      return;
    }
    if (act === 'lang-preview') {
      if (dirty()) {
        UI.toast('Save or discard your changes before previewing another language.');
        return;
      }
      setCookie('elog_locale', id);
      location.reload();
      return;
    }
    if (act === 'raise' || act === 'unlock') {
      openRaise(id, act);
      return;
    }
    if (act === 'rq-approve' || act === 'rq-decline') {
      try {
        const res = await UI.postJSON(`/api/i18n/requests/${encodeURIComponent(id)}/${act === 'rq-approve' ? 'approve' : 'decline'}`);
        UI.toast(res.note);
        i18n = null;
        renderSection();
      } catch (err) {
        UI.toast(err.message);
      }
      return;
    }
    if (!data.canManage) return;

    if (act === 'cur-toggle') {
      const c = draft.currencies[Number(id)];
      const h = data.currencies.find(x => x.code === c.code);
      if (c.active && h && h.claims) {
        UI.toast(`${c.code} is stated on ${plural(h.claims, 'open donor claim', 'open donor claims')} — settle or write those off before it can be disabled.`);
        return;
      }
      c.active = !c.active;
      UI.toast(c.code + (c.active ? ' enabled — it will be offered on new awards and donor claims once saved.' : ' disabled — it will no longer appear on new awards or claims once saved. Nothing already posted changes.'));
    }
    if (act === 'cur-add') {
      const f = view.curForm;
      const code = f.code.trim().toUpperCase();
      const rate = num(f.rate);
      if (!/^[A-Z]{3}$/.test(code)) return UI.toast('Use the three-letter ISO code — SEK, NOK, CHF.');
      if (draft.currencies.some(c => c.code === code)) return UI.toast(code + ' is already on the list.');
      if (!f.name.trim()) return UI.toast('Name the currency so it reads properly on donor claims.');
      if (!rate) return UI.toast(`Enter an indicative rate to ${draft.ledger.currency}.`);
      draft.currencies.push({ code, name: f.name.trim(), rate: rate.toFixed(2), active: true });
      view.curForm = { code: '', name: '', rate: '' };
      UI.toast(`${code} added at ${rate.toFixed(2)} — save to offer it on new awards and donor claims.`);
    }
    if (act === 'ben-toggle') {
      const b = draft.payroll.benefits[Number(id)];
      const h = data.benefits.find(x => x.key === b.key);
      if (b.active && h && h.paidTo) {
        UI.toast(`${b.name} is paid to ${plural(h.paidTo, 'member of staff', 'members of staff')} — clear it on their records before withdrawing it.`);
        return;
      }
      b.active = !b.active;
    }
    if (act === 'ben-add') {
      const f = view.benForm;
      const name = f.name.trim();
      if (!name) return UI.toast('Name the benefit as it should read on a payslip.');
      if (draft.payroll.benefits.some(b => b.name.toLowerCase() === name.toLowerCase())) return UI.toast(name + ' is already on the list.');
      draft.payroll.benefits.push({ key: 'new-' + (++view.newBenefits), name, basis: f.basis, taxable: !!f.taxable, active: true });
      view.benForm = { name: '', basis: 'flat', taxable: true };
      UI.toast(name + ' added — set the amount per grade below, then save.');
    }
    if (act === 'grade-toggle') {
      const g = draft.payroll.grades[Number(id)];
      const h = data.grades.find(x => x.grade === g.grade);
      if (g.active && h && h.staff) {
        UI.toast(`${g.grade} is held by ${plural(h.staff, 'member of staff', 'members of staff')} — move them to another grade before it can be withdrawn.`);
        return;
      }
      g.active = !g.active;
    }
    if (act === 'grade-add') {
      const f = view.gradeForm;
      const code = f.grade.trim().toUpperCase();
      if (!code) return UI.toast('Give the grade a code — G8, or whatever the scale uses next.');
      if (draft.payroll.grades.some(g => g.grade === code)) return UI.toast(code + ' already exists on the scale.');
      if (!f.band.trim()) return UI.toast('Name the band so it reads on payslips and the roster.');
      const ben = {};
      for (const b of draft.payroll.benefits.filter(x => x.active)) {
        ben[b.key] = num(f.ben[b.key]);
        if (b.basis === 'pct' && ben[b.key] > 100) return UI.toast(b.name + ' is a percentage of basic pay — enter something up to 100.');
      }
      draft.payroll.grades.push({ grade: code, band: f.band.trim(), ben, active: true });
      view.gradeForm = { grade: '', band: '', ben: {} };
      UI.toast(`${code} · ${f.band.trim()} added to the scale — save to offer it on new appointments.`);
    }
    if (act === 'invite') {
      openInvite();
      return;
    }
    renderHead();
    renderSection();
  }

  function openInvite() {
    UI.drawer('Invite user', `
      <div class="bu" id="st-invite">
        <p class="bu-intro">The invitation is sent by email and expires after seven days. The person appears as Invited until they accept, and cannot act until then.</p>
        <label class="bu-field"><span>Full name</span><input id="iv-name" maxlength="120" autocomplete="off"></label>
        <label class="bu-field"><span>Email</span><input id="iv-email" type="email" maxlength="190" autocomplete="off"></label>
        <label class="bu-field"><span>Role</span><select id="iv-role">${data.roles.map(r => `<option>${esc(r)}</option>`).join('')}</select></label>
        <div class="bu-field"><span>Entity access</span>
          <label class="st-inline"><input type="checkbox" id="iv-all" checked>All entities</label>
          <div id="iv-entities" class="st-invite-entities" hidden>${data.entityOptions.map(e => `<label class="st-inline"><input type="checkbox" value="${esc(e.code)}">${esc(e.name)}</label>`).join('')}</div>
        </div>
        <div class="bu-actions"><button type="button" class="btn" id="iv-cancel">Cancel</button><button type="button" class="btn btn-primary" id="iv-send">Send invitation</button></div>
      </div>`);
    const box = document.getElementById('st-invite');
    box.querySelector('#iv-all').addEventListener('change', (e) => { box.querySelector('#iv-entities').hidden = e.target.checked; });
    box.querySelector('#iv-cancel').addEventListener('click', UI.closeDrawer);
    box.querySelector('#iv-send').addEventListener('click', async () => {
      const all = box.querySelector('#iv-all').checked;
      try {
        const res = await UI.postJSON('/api/settings/invite', {
          name: box.querySelector('#iv-name').value, email: box.querySelector('#iv-email').value, role: box.querySelector('#iv-role').value,
          entities: all ? 'all' : [...box.querySelectorAll('#iv-entities input:checked')].map(i => i.value),
        });
        UI.closeDrawer();
        // Keep unsaved edits; take the new user list from the server.
        const pending = draft;
        data = res;
        draft = toDraft(data);
        Object.assign(draft, { ...pending, users: { ...draft.users, ...pending.users } });
        render();
        UI.toast(res.message);
      } catch (err) {
        UI.toast(err.message);
      }
    });
  }

  await load();
})();

/**
 * Settings → Bank statements: the CSV format each bank's statements come in, and
 * which format each cash account uses. A format maps the bank's columns onto the
 * lines of the reconciliation's bank statement; loading a sample file shows the
 * lines exactly as they will be read. Changes are for approvers; anyone can look.
 */
const StatementFormats = (() => {
  const esc = UI.esc;
  let root = null;
  let data = null;
  const ed = { format: null, file: null, sample: null, timer: null, seq: 0 };

  const BLANK = {
    id: null, name: '', builtin: false, delimiter: 'comma', dateColumn: '', dateFormat: 'dd/mm/yyyy', referenceColumn: '',
    descriptionColumns: [''], amountLayout: 'split', amountColumn: '', debitColumn: '', creditColumn: '', indicatorColumn: '',
    creditIndicator: 'CR', balanceColumn: '', decimalMark: '.', statusColumn: '', statusValue: '', rules: [],
  };

  async function mount(container) {
    root = container;
    root.innerHTML = '<div class="coa-empty">Loading statement formats…</div>';
    try {
      data = await UI.fetchJSON('/api/statement-formats');
    } catch (err) {
      root.innerHTML = `<div class="coa-empty">${esc(err.message)}</div>`;
      return;
    }
    render();
  }

  function render() {
    if (!root || !root.isConnected) return;
    const opts = (selected) => `<option value="">No format — statements cannot be uploaded</option>`
      + data.formats.map(f => `<option value="${f.id}" ${f.id === selected ? 'selected' : ''}>${esc(f.name)}</option>`).join('');

    root.innerHTML = `
      <div class="sf-cards">
        ${data.canManage ? '' : '<p class="bu-intro">Only an approver can change statement formats or the format an account uses.</p>'}
        <div class="sf-section">Accounts</div>
        <div class="coa-card">
          ${data.accounts.map(a => `
            <div class="sf-row sf-account">
              <div><div class="sf-name">${esc(a.code)} · ${esc(a.name)}</div><div class="sf-sub">${a.kind === 'mobile_money' ? 'Mobile money' : 'Bank'} · ${esc(a.currency)}</div></div>
              <select data-assign="${esc(a.code)}" ${data.canManage ? '' : 'disabled'} aria-label="Statement format for ${esc(a.short)}">${opts(a.formatId)}</select>
            </div>`).join('')}
        </div>
        <div class="sf-section" style="display:flex;align-items:center;gap:8px;">Formats
          ${data.canManage ? '<button type="button" class="btn btn-primary" data-new style="margin-inline-start:auto;text-transform:none;letter-spacing:0;">+ New format</button>' : ''}
        </div>
        <div class="coa-card">
          ${data.formats.map(f => `
            <div class="sf-row">
              <div>
                <div class="sf-name">${esc(f.name)} ${f.builtin ? '<span class="jr-pill teal">Built in</span>' : ''}</div>
                <div class="sf-sub">${esc(f.summary)}${f.balanceColumn ? ' · running balance checked' : ''}${f.rules.length ? ` · ${f.rules.length} ${f.rules.length === 1 ? 'rule' : 'rules'}` : ''}</div>
                <div class="sf-sub">${f.accounts.length ? 'Used by ' + f.accounts.map(esc).join(', ') : 'Not used by any account'}</div>
              </div>
              <div class="sf-btns">
                <button type="button" class="btn" data-edit="${f.id}">${f.builtin || !data.canManage ? 'View' : 'Edit'}</button>
                ${data.canManage ? `<button type="button" class="btn" data-copy="${f.id}">Duplicate</button>` : ''}
                ${data.canManage && !f.builtin ? `<button type="button" class="btn" data-delete="${f.id}" ${f.accounts.length ? 'disabled title="In use"' : ''}>Delete</button>` : ''}
              </div>
            </div>`).join('') || '<div class="coa-empty">No statement formats yet.</div>'}
        </div>
      </div>`;

    root.onchange = async (e) => {
      const pick = e.target.closest('select[data-assign]');
      if (!pick) return;
      try {
        const res = await UI.postJSON('/api/statement-formats/assign', { account: pick.dataset.assign, format: pick.value || null });
        Object.assign(data, { formats: res.formats, accounts: res.accounts });
        UI.toast(res.message);
      } catch (err) {
        UI.toast(err.message);
      }
      render();
    };
    root.onclick = async (e) => {
      const btn = e.target.closest('button');
      if (!btn) return;
      const find = (id) => data.formats.find(f => f.id === Number(id));
      if (btn.dataset.new !== undefined) openEditor({ ...BLANK, descriptionColumns: [''], rules: [] });
      if (btn.dataset.edit) openEditor(structuredClone(find(btn.dataset.edit)));
      if (btn.dataset.copy) {
        const f = structuredClone(find(btn.dataset.copy));
        openEditor({ ...f, id: null, builtin: false, accounts: [], name: f.name.replace(/ \(CSV\)$/, '') + ' — copy' });
      }
      if (btn.dataset.delete) {
        const f = find(btn.dataset.delete);
        if (!confirm(`Delete the statement format ${f.name}?`)) return;
        try {
          const res = await UI.postJSON(`/api/statement-formats/${f.id}/delete`);
          Object.assign(data, { formats: res.formats, accounts: res.accounts });
          UI.toast(res.message);
          render();
        } catch (err) {
          UI.toast(err.message);
        }
      }
    };
  }

  // ------------------------------------------------------------------
  // Editor
  // ------------------------------------------------------------------

  function openEditor(format) {
    ed.format = format;
    ed.sample = null;
    ed.file = null;
    const locked = format.builtin || !data.canManage;
    const o = data.options;
    const field = (label, key, hint) => `<label class="bu-field"><span>${esc(label)}${hint ? ` <em>${esc(hint)}</em>` : ''}</span><input data-k="${key}" list="sf-cols" value="${esc(format[key] || '')}" autocomplete="off"></label>`;
    const dateFormats = o.dateFormats.includes(format.dateFormat) || !format.dateFormat ? o.dateFormats : [format.dateFormat, ...o.dateFormats];

    UI.drawer(format.id ? format.name : 'New statement format', `
      <div class="bu" id="sf-ed">
        ${format.builtin ? '<p class="bu-status">This format is built in and the same for every organisation. Duplicate it to make a version of your own.</p>' : ''}
        <label class="bu-field"><span>Name</span><input data-k="name" value="${esc(format.name)}" maxlength="80" placeholder="e.g. Co-operative Bank internet banking (CSV)"></label>
        <label class="bu-field"><span>Sample statement <em>optional — shows the lines as they will be read</em></span><input type="file" id="sf-file" accept=".csv,.txt,text/csv"></label>
        <div id="sf-headers"></div>
        <datalist id="sf-cols"></datalist>
        <div class="bu-grid">
          <label class="bu-field"><span>Separator</span><select data-k="delimiter">${o.delimiters.map(d => `<option value="${d.value}" ${d.value === format.delimiter ? 'selected' : ''}>${esc(d.text)}</option>`).join('')}</select></label>
          <label class="bu-field"><span>Decimal mark</span><select data-k="decimalMark"><option value="." ${format.decimalMark === '.' ? 'selected' : ''}>1,234.56</option><option value="," ${format.decimalMark === ',' ? 'selected' : ''}>1.234,56</option></select></label>
        </div>
        <div class="sf-section">Columns</div>
        <div class="bu-grid">
          ${field('Date', 'dateColumn')}
          <label class="bu-field"><span>Dates written as</span><select data-k="dateFormat">${dateFormats.map(d => `<option ${d === format.dateFormat ? 'selected' : ''}>${esc(d)}</option>`).join('')}</select></label>
          ${field('Reference', 'referenceColumn', 'optional')}
          <label class="bu-field"><span>Description</span><input data-desc="0" list="sf-cols" value="${esc(format.descriptionColumns[0] || '')}" autocomplete="off"></label>
          <label class="bu-field"><span>Add to the description <em>optional</em></span><input data-desc="1" list="sf-cols" value="${esc(format.descriptionColumns[1] || '')}" autocomplete="off"></label>
          ${field('Running balance', 'balanceColumn', 'optional · checked line by line')}
        </div>
        <label class="bu-field"><span>Amounts</span><select data-k="amountLayout">${o.layouts.map(l => `<option value="${l.value}" ${l.value === format.amountLayout ? 'selected' : ''}>${esc(l.text)}</option>`).join('')}</select></label>
        <div class="bu-grid" id="sf-amounts"></div>
        <div class="sf-section">Only load rows where <em style="text-transform:none;letter-spacing:0;">optional</em></div>
        <div class="bu-grid">
          ${field('Status column', 'statusColumn')}
          <label class="bu-field"><span>Has the value</span><input data-k="statusValue" value="${esc(format.statusValue || '')}" placeholder="e.g. Completed" autocomplete="off"></label>
        </div>
        <div class="sf-section">The bank's own entries</div>
        <p class="bu-intro" style="margin-top:-6px;">A line whose description or reference contains the text is marked for journalising instead of matching. The first rule that fits wins.</p>
        <div id="sf-rules" style="display:flex;flex-direction:column;gap:6px;"></div>
        <div><button type="button" class="btn" id="sf-add-rule">+ Add rule</button></div>
        <div class="bu-actions">
          <button type="button" class="btn" id="sf-cancel">${locked ? 'Close' : 'Cancel'}</button>
          ${locked ? '' : '<button type="button" class="btn btn-primary" id="sf-save">Save format</button>'}
        </div>
        <div id="sf-preview"></div>
      </div>`, { wide: true });

    const box = document.getElementById('sf-ed');
    renderAmounts();
    renderRules();
    box.querySelectorAll('input, select').forEach(el => { if (el.id !== 'sf-file' && locked) el.disabled = true; });
    box.addEventListener('input', (e) => { collect(e.target); schedulePreview(); });
    box.addEventListener('change', (e) => {
      if (e.target.id === 'sf-file') {
        ed.file = e.target.files[0] || null;
        schedulePreview(true);
        return;
      }
      collect(e.target);
      if (e.target.dataset.k === 'amountLayout') renderAmounts();
      schedulePreview(true);
    });
    box.addEventListener('click', async (e) => {
      if (e.target.id === 'sf-cancel') UI.closeDrawer();
      if (e.target.id === 'sf-add-rule') {
        ed.format.rules.push({ match: '', entry: o.entries[0].value });
        renderRules();
      }
      const remove = e.target.closest('[data-rule-remove]');
      if (remove) {
        ed.format.rules.splice(Number(remove.dataset.ruleRemove), 1);
        renderRules();
        schedulePreview(true);
      }
      if (e.target.id === 'sf-save') save(e.target);
    });
  }

  /** Copies an edited field back onto the format. */
  function collect(el) {
    const f = ed.format;
    if (el.dataset.k) f[el.dataset.k] = el.value;
    if (el.dataset.desc !== undefined) {
      f.descriptionColumns[Number(el.dataset.desc)] = el.value;
    }
    if (el.dataset.rule !== undefined) f.rules[Number(el.dataset.rule)][el.dataset.part] = el.value;
  }

  function renderAmounts() {
    const f = ed.format;
    const locked = f.builtin || !data.canManage;
    const input = (label, key, placeholder) => `<label class="bu-field"><span>${esc(label)}</span><input data-k="${key}" list="sf-cols" value="${esc(f[key] || '')}" ${placeholder ? `placeholder="${esc(placeholder)}"` : ''} autocomplete="off" ${locked ? 'disabled' : ''}></label>`;
    document.getElementById('sf-amounts').innerHTML = f.amountLayout === 'split'
      ? input('Money out column', 'debitColumn') + input('Money in column', 'creditColumn')
      : f.amountLayout === 'indicator'
        ? input('Amount column', 'amountColumn') + input('Debit/credit column', 'indicatorColumn') + input('Money in is marked', 'creditIndicator', 'CR')
        : input('Amount column', 'amountColumn');
  }

  function renderRules() {
    const f = ed.format;
    const locked = f.builtin || !data.canManage;
    document.getElementById('sf-rules').innerHTML = f.rules.map((r, i) => `
      <div class="sf-rule">
        <input class="jd-reason" data-rule="${i}" data-part="match" value="${esc(r.match)}" placeholder="Text, e.g. CHG" ${locked ? 'disabled' : ''} aria-label="Text the line contains">
        <select class="jd-reason" data-rule="${i}" data-part="entry" ${locked ? 'disabled' : ''} aria-label="Kind of entry">${data.options.entries.map(k => `<option value="${k.value}" ${k.value === r.entry ? 'selected' : ''}>${esc(k.text)}</option>`).join('')}</select>
        ${locked ? '<span></span>' : `<button type="button" data-rule-remove="${i}" aria-label="Remove rule">×</button>`}
      </div>`).join('') || '<span class="sf-sub">No rules — every line is matched to the cash book.</span>';
  }

  function payload() {
    return { ...ed.format, descriptionColumns: ed.format.descriptionColumns.filter(Boolean) };
  }

  function schedulePreview(now) {
    clearTimeout(ed.timer);
    if (!ed.file) return;
    ed.timer = setTimeout(preview, now ? 0 : 450);
  }

  async function preview() {
    const seq = ++ed.seq;
    const form = new FormData();
    form.append('file', ed.file);
    form.append('payload', JSON.stringify(payload()));
    try {
      const res = await fetch('/api/statement-formats/sample', { method: 'POST', headers: { Accept: 'application/json' }, body: form });
      const body = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(body.error || `Request failed: ${res.status}`);
      if (seq !== ed.seq) return;
      ed.sample = body.sample;
      renderPreview();
    } catch (err) {
      UI.toast(err.message);
    }
  }

  function renderPreview() {
    const s = ed.sample;
    const box = document.getElementById('sf-preview');
    if (!box) return;
    document.getElementById('sf-cols').innerHTML = s.headers.map(h => `<option value="${esc(h)}"></option>`).join('');
    document.getElementById('sf-headers').innerHTML = s.headers.length
      ? `<div class="bu-field"><span>Columns in the file${s.headerLine ? ` · header on line ${s.headerLine}` : ''}</span><div class="sf-headers">${s.headers.map(h => `<code>${esc(h)}</code>`).join('')}</div></div>`
      : '';

    if (s.error) {
      box.innerHTML = `<ul class="bu-checks"><li class="bad">! ${esc(s.error)}</li></ul>`;
      return;
    }
    const checks = [
      s.problems === 0 ? ['ok', `${s.rowCount} ${s.rowCount === 1 ? 'transaction' : 'transactions'} read`] : ['bad', `${s.problems} of ${s.rowCount} rows could not be read — see below`],
      s.skipped ? ['ok', `${s.skipped} passed over by the status filter`] : null,
      s.balanceChecked ? (s.balanceBreaks.length ? ['bad', 'The running balance does not follow on line ' + s.balanceBreaks.join(', ') + ' — check money in and money out are the right way round'] : ['ok', `Running balances follow line by line (${s.newestFirst ? 'newest first' : 'oldest first'})`]) : null,
    ].filter(Boolean);

    box.innerHTML = `
      <div class="sf-section">How the statement will read${s.rowCount > s.rows.length ? ` · first ${s.rows.length} of ${s.rowCount}` : ''}</div>
      <ul class="bu-checks">${checks.map(([cls, text]) => `<li class="${cls}">${cls === 'ok' ? '✓' : '!'} ${esc(text)}</li>`).join('')}</ul>
      <div class="coa-card">
        <div class="card-head" style="align-items:baseline;"><span class="card-title">Bank statement</span><span style="font-size:11px;color:#7A857F;">preview</span></div>
        ${s.rows.map(r => `
          <div class="br-row bu-row${r.skip ? ' done' : ''}">
            <span class="br-date" title="File line ${r.line}">${esc(r.date)}</span>
            <div class="br-what">
              <span class="br-desc" title="${esc(r.desc)}">${esc(r.desc)}</span>
              <div class="br-tags">
                <span class="br-ref">${esc(r.ref)}</span>
                ${r.entryLabel ? `<span class="br-link" style="cursor:default;">${esc(r.entryLabel)} →</span>` : ''}
                ${r.balance ? `<span class="br-tag" style="color:#9AA39E;">balance ${esc(r.balance)}</span>` : ''}
                ${r.skip ? `<span class="br-tag wait">${esc(r.skip)}</span>` : ''}
                ${r.errors.length ? `<span class="br-tag bad">line ${r.line}: ${esc(r.errors.join('; '))}</span>` : ''}
              </div>
            </div>
            <span class="br-amt${!r.skip && r.amt > 0 ? ' in' : ''}">${esc(r.amount)}</span>
          </div>`).join('') || '<div class="coa-empty">No transactions found below the header row.</div>'}
      </div>`;
  }

  async function save(button) {
    const f = ed.format;
    button.disabled = true;
    try {
      const res = await UI.postJSON(f.id ? `/api/statement-formats/${f.id}` : '/api/statement-formats', payload());
      Object.assign(data, { formats: res.formats, accounts: res.accounts });
      UI.closeDrawer();
      UI.toast(res.message);
      render();
    } catch (err) {
      UI.toast(err.message);
      button.disabled = false;
    }
  }

  return { mount };
})();
