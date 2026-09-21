/**
 * Settings (v5): organisation, ledger, segments, currencies, approvals, bank
 * statements, integrations, payroll, appearance, language and translation, users and
 * the audit log.
 *
 * Users and Roles save as they are made (Api\Users, Api\Roles) and need users.manage:
 * a person's access is never left half-changed in a draft.
 *
 * The sections edit one draft, saved together with "Save changes" (or thrown away
 * with "Discard"), so nothing reaches the ledger half-configured and every saved
 * change lands in the audit log. Bank statement formats and the M-Pesa integration
 * save as they are made — a credential cannot sit in a draft in the browser.
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
    entForm: { code: '', name: '', type: 'Branch', currency: '' },
    fundForm: { code: '', name: '', restriction: 'Unrestricted', group: 'General Fund', funder: '' },
    benForm: { name: '', basis: 'flat', taxable: true },
    gradeForm: { grade: '', band: '', ben: {} },
    taxFrom: null,
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
  /**
   * Repaints the shell in a theme. The server writes the saved one onto <html>;
   * this is the preview of an unsaved pick. Only the two chosen colours are set —
   * every shade derived from them is in the stylesheet.
   */
  function paintTheme(appearance) {
    const root = document.documentElement;
    root.dataset.theme = appearance.theme;
    root.style.setProperty('--accent', appearance.theme === 'custom' ? appearance.custom.accent : '');
    root.style.setProperty('--rail-bg', appearance.theme === 'custom' ? appearance.custom.rail : '');
  }

  /** The editable part of the settings, in the shape the API saves. */
  function toDraft(d) {
    return structuredClone({
      organisation: d.organisation,
      entities: d.entities.map(e => ({ code: e.code, name: e.name, type: e.type, currency: e.currency, status: e.status })),
      ledger: d.ledger,
      postingAccounts: Object.fromEntries(d.postingAccounts.map(p => [p.role, p.code])),
      toggles: Object.fromEntries(d.toggles.map(t => [t.key, t.on])),
      segments: Object.fromEntries(d.segments.map(s => [s.key, s.required])),
      currencies: d.currencies.map(c => ({ code: c.code, name: c.name, rate: c.rate, active: c.active })),
      approvals: Object.fromEntries(d.approvals.map(a => [a.key, { threshold: a.threshold, approver: a.approver }])),
      procurement: { quoteThreshold: d.procurement.quoteThreshold },
      days: Object.fromEntries(d.days.map(r => [r.key, r.value])),
      taxes: { vat: { ...(d.taxes.vat || { rate: '', label: 'Standard rate' }) }, wht: d.taxes.wht.map(r => ({ ...r })) },
      payroll: {
        benefits: d.benefits.map(b => ({ key: b.key, name: b.name, basis: b.basis, taxable: b.taxable, active: b.active })),
        grades: d.grades.map(g => ({ grade: g.grade, band: g.band, ben: { ...g.ben }, active: g.active })),
      },
      payAccounts: Object.fromEntries(d.payAccounts.map(c => [c.key, c.code])),
      appearance: {
        theme: d.appearance.theme, appName: d.appearance.appName, appTagline: d.appearance.appTagline,
        custom: { ...d.appearance.custom },
      },
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
    view.taxFrom ??= data.taxes.today;
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
      <div class="st-readonly" id="st-readonly" hidden>You can look through the settings. Changing them needs a role with settings.manage, such as the Finance Manager.</div>
      <div class="st-wrap">
        <nav class="st-nav" id="st-nav" aria-label="Settings sections"></nav>
        <div class="st-main" id="st-main"></div>
      </div>`;

    app.querySelector('#st-save').addEventListener('click', save);
    app.querySelector('#st-discard').addEventListener('click', () => {
      draft = toDraft(data);
      paintTheme(draft.appearance);
      view.curForm = { code: '', name: '', rate: '' };
      view.entForm = { code: '', name: '', type: 'Branch', currency: '' };
      view.fundForm = { code: '', name: '', restriction: 'Unrestricted', group: 'General Fund', funder: '' };
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
      Organisation: organisation, Ledger: ledger, Segments: segments, Currencies: currencies, Taxes: taxes, 'Terms and reminders': terms, Approvals: approvals,
      'Bank statements': bankStatements, 'Opening balances': openingBalances, Integrations: integrations,
      Payroll: payroll, Appearance: appearance,
      'Language and translation': language, Users: users, Roles: roles, 'Audit log': audit,
    };
    (renderers[view.section] || organisation)(main);
    if (!data.canManageUsers) main.querySelectorAll('[data-manage-users]').forEach(el => { el.disabled = true; });
    if (!data.canManage && !['Bank statements', 'Opening balances', 'Integrations', 'Language and translation', 'Users', 'Roles'].includes(view.section)) {
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
      // The date a tax change takes effect goes with it; on its own it changes nothing.
      const res = await UI.postJSON('/api/settings', { ...draft, taxes: { ...draft.taxes, from: view.taxFrom } });
      data = res;
      draft = toDraft(data);
      view.taxFrom = data.taxes.today;
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
        ${entities()}
      </div>`;
  }

  /**
   * The entities the organisation consolidates. A row is edited in place; a new
   * one is added below and reaches the ledger with the rest of the draft.
   *
   * There is no way to remove an entity here, and that is deliberate: every
   * posting ever made carries the entity it was made against, so an office that
   * closes is made dormant — it keeps its history and drops out of the lists that
   * offer a choice.
   */
  function entities() {
    const held = Object.fromEntries(data.entities.map(e => [e.code, e]));
    const cols = 'grid-template-columns:104px minmax(0,1fr) 148px 104px 116px;';
    const currencies = draft.currencies.filter(c => c.active).map(c => c.code);
    const f = view.entForm;
    return `
        <div class="st-block">
          ${head('Entities', 'The offices, branches and related trusts the accounts consolidate. Consolidation eliminates inter-entity balances automatically, and every entity posts into the shared master chart of accounts.')}
          <div class="st-table"><div style="min-width:620px;">
            <div class="st-tr st-th" style="${cols}"><div>Code</div><div>Entity</div><div>Type</div><div>Currency</div><div>Status</div></div>
            ${draft.entities.map((e, i) => {
              const h = held[e.code];
              const head_ = h && h.head;
              const posted = h && h.postings > 0;
              return `
              <div class="st-tr" style="${cols}min-height:44px;">
                <div class="mono st-strong st-ellipsis">${esc(e.code)}${h ? '' : ' <span class="st-sub">new</span>'}</div>
                <div><input class="st-cell" data-bind="entities.${i}.name" value="${esc(e.name)}" aria-label="${esc(e.code)} name"></div>
                <div><select class="st-cell" data-bind="entities.${i}.type" aria-label="${esc(e.code)} type" ${head_ ? 'disabled' : ''}>
                  ${data.entityTypes.map(t => `<option ${t === e.type ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select></div>
                <div>${posted
                  ? `<span class="st-muted mono" title="${esc(fmt(h.postings))} journals are posted in ${esc(e.currency)}">${esc(e.currency)}</span>`
                  : `<select class="st-cell mono" data-bind="entities.${i}.currency" aria-label="${esc(e.code)} currency">
                      ${(currencies.includes(e.currency) ? currencies : [e.currency, ...currencies]).map(c => `<option ${c === e.currency ? 'selected' : ''}>${esc(c)}</option>`).join('')}</select>`}</div>
                <div>${head_
                  ? '<span class="st-live">● Head office</span>'
                  : `<select class="st-cell" data-bind="entities.${i}.status" aria-label="${esc(e.code)} status">
                      <option ${e.status === 'Live' ? 'selected' : ''}>Live</option><option ${e.status === 'Dormant' ? 'selected' : ''}>Dormant</option></select>`}</div>
              </div>`;
            }).join('')}
          </div></div>
          <div class="st-addbox">
            <span class="st-addtitle">Add an entity</span>
            <div class="st-addrow">
              <label class="st-field" style="flex:0 0 116px;">Code<input data-bind="form.entForm.code" value="${esc(f.code)}" placeholder="ELOG-NYZ" maxlength="20" class="mono upper"></label>
              <label class="st-field" style="flex:1 1 220px;min-width:170px;">Entity name<input data-bind="form.entForm.name" value="${esc(f.name)}" placeholder="ELOG Nyanza Regional Office"></label>
              <label class="st-field" style="flex:0 0 150px;">Type<select data-bind="form.entForm.type">
                ${data.entityTypes.filter(t => t !== data.entityTypes[0]).map(t => `<option ${t === f.type ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select></label>
              <label class="st-field" style="flex:0 0 110px;">Currency<select data-bind="form.entForm.currency" class="mono">
                ${currencies.map(c => `<option ${c === (f.currency || draft.ledger.currency) ? 'selected' : ''}>${esc(c)}</option>`).join('')}</select></label>
              <button type="button" class="btn btn-primary" data-act="ent-add" data-manage>Add entity</button>
            </div>
            <span class="st-sub">The code is what user access, imported files and inter-entity references call it by, so it is fixed once the entity is saved. A new entity reports to the head office.</span>
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
        ${postingAccounts()}
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
        ${rule}
        ${funds()}
      </div>`;
  }

  /**
   * The funds every posting is coded to. A fund is opened as it is entered rather
   * than drafted with the rest of the screen: nothing can be coded to it until it
   * exists, and a fund is never deleted — it is closed, which keeps its history.
   */
  function funds() {
    const f = view.fundForm;
    const o = data.fundOptions;
    const restricted = ['Restricted', 'Designated', 'Endowment'].includes(f.restriction);

    return `
      ${head('Funds', 'What the money is held for. Every posting carries one, and the fund decides which column of the ledger and which statement it is reported in. Funds are opened as they are entered, not saved with the rest of the screen.')}
      <div class="st-table"><div style="min-width:660px;">
        <div class="st-tr st-th" style="grid-template-columns:110px minmax(150px,1fr) 118px 132px 92px;"><div>Code</div><div>Fund</div><div>Class</div><div>Ledger column</div><div class="end">Postings</div></div>
        ${data.funds.length ? data.funds.map(x => `
          <div class="st-tr" style="grid-template-columns:110px minmax(150px,1fr) 118px 132px 92px;">
            <div class="mono st-strong">${esc(x.code)}</div>
            <div class="st-stack"><span>${esc(x.name)}</span><span class="st-sub st-ellipsis">${esc(x.funder || x.purpose || (x.status === 'Active' ? 'Open for posting' : 'Closed'))}</span></div>
            <div class="st-muted">${esc(x.restriction)}</div>
            <div class="st-muted st-ellipsis">${esc(x.group)}</div>
            <div class="end mono st-muted">${x.postings ? fmt(x.postings) : '—'}</div>
          </div>`).join('')
          : '<div class="st-tr"><div class="st-sub">No funds yet. Nothing can be posted until there is one — every journal line carries a fund.</div></div>'}
      </div></div>
      <div class="st-addbox">
        <span class="st-addtitle">Open a fund</span>
        <div class="st-addrow">
          <label class="st-field" style="flex:0 0 130px;">Code<input data-bind="form.fundForm.code" value="${esc(f.code)}" placeholder="FND-100" class="mono upper" maxlength="20"></label>
          <label class="st-field" style="flex:1 1 200px;min-width:160px;">Fund name<input data-bind="form.fundForm.name" value="${esc(f.name)}" placeholder="General Fund"></label>
          <label class="st-field" style="flex:0 0 140px;">Class<select data-bind="form.fundForm.restriction">${o.restrictions.map(r => `<option ${r === f.restriction ? 'selected' : ''}>${esc(r)}</option>`).join('')}</select></label>
          <label class="st-field" style="flex:0 0 156px;">Ledger column<select data-bind="form.fundForm.group">${o.groups.map(g => `<option ${g === f.group ? 'selected' : ''}>${esc(g)}</option>`).join('')}</select></label>
          ${restricted && o.funders.length ? `<label class="st-field" style="flex:0 0 180px;">Held for<select data-bind="form.fundForm.funder"><option value="">No single funder</option>${o.funders.map(x => `<option ${x === f.funder ? 'selected' : ''}>${esc(x)}</option>`).join('')}</select></label>` : ''}
          <button type="button" class="btn btn-primary" data-act="fund-add" data-manage>Open fund</button>
        </div>
        <span class="st-sub">An endowment is reported as one and rolls up to the endowment column — set both or neither. The grant and capital columns report money held for a donor, so a fund in them cannot be unrestricted.</span>
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

  function taxes(main) {
    const t = data.taxes;
    const next = (tax) => t.next[tax] ? `From ${t.next[tax].from}: ${t.next[tax].rates.map(r => r.rate + '%').join(', ')}` : '';
    const cols = 'grid-template-columns:120px minmax(160px,1fr) 64px;';
    const inUse = new Set(t.categories.filter(c => c.wht > 0).map(c => Number(c.wht)));
    main.innerHTML = `
      <div class="st-body wide">
        ${head('VAT and withholding tax', 'The rates a bill is captured at, as the Finance Act sets them. A change takes effect from the date below: bills dated earlier keep the rates they were captured with, and nothing already on file is recalculated.')}
        <div class="st-grid">
          <label class="st-field">VAT rate (%)<input data-bind="taxes.vat.rate" value="${esc(draft.taxes.vat.rate)}" class="mono" inputmode="decimal"></label>
          <label class="st-field">Changes take effect from<input type="date" data-bind="form.taxFrom" value="${esc(view.taxFrom)}" min="${esc(t.today)}" class="mono"></label>
        </div>
        <div class="st-sub" style="margin-top:6px;">${t.vat ? `${esc(t.vat.rate)}% in force since ${esc(t.since.vat)}` : 'No VAT rate in force today — bills cannot be captured until one is set.'}${next('vat') ? ' · ' + esc(next('vat')) : ''}</div>
        <div style="margin-top:22px;">
          ${head('Withholding rates', 'The rates a bill may withhold at, besides nil. Each spend category defaults to one of them; overriding the default on a bill needs a reason.')}
          <div class="st-table"><div style="min-width:420px;">
            <div class="st-tr st-th" style="${cols}"><div class="end">Rate (%)</div><div>Applies to</div><div></div></div>
            ${draft.taxes.wht.map((r, i) => `
            <div class="st-tr" style="${cols}">
              <div><input class="st-cell mono end" data-bind="taxes.wht.${i}.rate" value="${esc(r.rate)}" inputmode="decimal" aria-label="Withholding rate"></div>
              <div><input class="st-cell" data-bind="taxes.wht.${i}.label" value="${esc(r.label)}" maxlength="80" aria-label="What the rate applies to"></div>
              <div class="end">${inUse.has(Number(r.rate)) ? '<span class="st-sub" title="A spend category defaults to this rate">in use</span>' : `<button type="button" class="jd-remove" data-act="wht-remove" data-id="${i}" data-manage aria-label="Remove rate">×</button>`}</div>
            </div>`).join('')}
          </div></div>
          <div style="margin-top:8px;"><button type="button" class="btn" data-act="wht-add" data-manage>Add a rate</button></div>
          <div class="st-sub" style="margin-top:6px;">In force since ${esc(t.since.wht)}${next('wht') ? ' · ' + esc(next('wht')) : ''}</div>
        </div>
        <div class="st-infobox">
          <div class="st-kicker">Spend category defaults</div>
          ${t.categories.map(c => `<div class="st-bullet"><span>·</span>${esc(c.name)} — ${esc(c.wht)}%</div>`).join('')}
        </div>
        <div style="margin-top:22px;">
          ${head('Rate history', '')}
          <div class="st-table"><div style="min-width:560px;">
            <div class="st-tr st-th" style="grid-template-columns:140px 80px minmax(160px,1fr) 110px 110px;"><div>Tax</div><div class="end">Rate</div><div>Applies to</div><div>From</div><div>To</div></div>
            ${t.history.map(h => `
            <div class="st-tr" style="grid-template-columns:140px 80px minmax(160px,1fr) 110px 110px;">
              <div>${esc(h.tax)}</div><div class="mono end">${esc(h.rate)}%</div><div class="st-muted st-ellipsis">${esc(h.label)}</div>
              <div class="mono">${esc(h.from)}</div><div class="mono st-muted">${esc(h.to || '—')}</div>
            </div>`).join('')}
          </div></div>
        </div>
      </div>`;
  }

  function terms(main) {
    const cols = 'grid-template-columns:minmax(200px,1.3fr) 150px;';
    main.innerHTML = `
      <div class="st-body wide">
        ${head('Terms and reminders', 'Payment terms and how far ahead the system flags what is coming due. A change applies from now on: a bill or claim already raised keeps the due date it was given.')}
        <div class="st-table"><div style="min-width:460px;">
          <div class="st-tr st-th" style="${cols}"><div>Rule</div><div class="end">Days</div></div>
          ${data.days.map(r => `
          <div class="st-tr" style="${cols}min-height:52px;">
            <div class="st-stack"><span>${esc(r.label)}</span><span class="st-sub">${esc(r.note)}${r.value !== r.standard ? ` · standard ${esc(r.standard)}` : ''}</span></div>
            <div><input class="st-cell mono end" data-bind="days.${esc(r.key)}" value="${esc(draft.days[r.key])}" aria-label="${esc(r.label)}" ${r.key === 'supplierTerms' ? '' : 'inputmode="numeric"'}></div>
          </div>`).join('')}
        </div></div>
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
        <div style="margin-top:22px;">
          ${head('Procurement threshold', 'Above this value a purchase needs three quotations, each with the supplier\'s document, or a single-source justification before its purchase order. A bill entered straight into Payables for more than this, before VAT, goes only to a pre-qualified supplier; below it, a supplier without a current pre-qualification needs a reason on the bill.')}
          <div class="st-table"><div style="min-width:640px;">
            <div class="st-tr st-th" style="grid-template-columns:minmax(140px,1fr) 152px minmax(160px,1fr);"><div>Control</div><div class="end">Threshold (KES)</div><div>Above threshold</div></div>
            <div class="st-tr" style="grid-template-columns:minmax(140px,1fr) 152px minmax(160px,1fr);">
              <div class="st-ellipsis">Quotations and supplier pre-qualification</div>
              <div><input class="st-cell mono end" data-num data-bind="procurement.quoteThreshold" value="${esc(fmt(num(draft.procurement.quoteThreshold)))}" aria-label="Procurement threshold"></div>
              <div class="st-muted st-ellipsis">Three quotations · pre-qualified supplier</div>
            </div>
          </div></div>
        </div>
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

  function openingBalances(main) {
    main.innerHTML = `<div class="st-body wide">${head('Opening balances', 'Carrying an entity\'s permanent balances from a legacy system onto this ledger. The file is checked before anything is written, and what is written is a draft for someone else to approve.')}<div id="st-conversion"></div></div>`;
    Conversion.mount(main.querySelector('#st-conversion'));
  }

  function integrations(main) {
    main.innerHTML = `<div class="st-body wide">${head('Integrations', 'Services outside the ledger that money and mail move through. Changes in this section are saved as you make them, and credentials are never shown again once entered.')}<div id="st-mpesa"></div><div id="st-mail"></div></div>`;
    Mpesa.mount(main.querySelector('#st-mpesa'));
    MailServer.mount(main.querySelector('#st-mail'));
  }

  /**
   * Where each pay component posts. The chart of accounts is imported after the
   * reference data, so a newly installed instance has the components and nothing
   * mapped — until this is set, a run is worked out but has nowhere to go.
   */
  /** The account each automatic posting goes to, by module. */
  function postingAccounts() {
    const cols = 'grid-template-columns:minmax(170px,1fr) minmax(200px,1.2fr);';
    const short = (c) => c.length > 46 ? c.slice(0, 45) + '…' : c;
    let module = '';
    return `
      <div class="st-block">
        <div class="st-kicker">Posting accounts</div>
        <div class="st-note">Where the postings the system makes itself go — a bill, a donor claim, a depreciation run, a bank charge. A change applies to postings from then on. An account that holds a balance to be cleared later, such as trade payables, can move only once that balance is nil.</div>
        ${data.postingAccounts.some(p => p.missing) ? warn(`${data.postingAccounts.filter(p => p.missing).map(p => `${p.label} (${p.code})`).join(', ')} — not in the chart of accounts. Choose an account, or those postings will be refused.`) : ''}
        <div class="st-table"><div style="min-width:440px;">
          <div class="st-tr st-th" style="${cols}"><div>Posting</div><div>Account</div></div>
          ${data.postingAccounts.map(p => {
            const options = data.postingAccountOptions.filter(o => p.types.includes(o.type));
            const current = draft.postingAccounts[p.role];
            const heading = p.module !== module ? `<div class="st-tr" style="${cols}background:#FAF9F6;"><div class="st-sub" style="text-transform:uppercase;letter-spacing:.08em;">${esc(module = p.module)}</div><div></div></div>` : '';
            return `${heading}
            <div class="st-tr" style="${cols}">
              <div class="st-stack"><span>${esc(p.label)}</span><span class="st-sub">${esc(p.what)}${p.control && p.balance ? ` · holds ${fmt(Math.abs(p.balance))}` : ''}</span></div>
              <div><select class="st-cell" data-bind="postingAccounts.${esc(p.role)}" aria-label="Account for ${esc(p.label)}">
                ${options.some(o => o.code === current) ? '' : `<option value="${esc(current)}" selected>${esc(current)} · not in the chart</option>`}
                ${options.map(o => `<option value="${esc(o.code)}" ${o.code === current ? 'selected' : ''}>${esc(short(o.code + ' · ' + o.name))}</option>`).join('')}
              </select></div>
            </div>`;
          }).join('')}
        </div></div>
      </div>`;
  }

  function payAccounts() {
    const rows = data.payAccounts;
    const options = data.payAccountOptions;
    const short = (c) => c.length > 46 ? c.slice(0, 45) + '…' : c;
    const missing = rows.filter(c => c.required && !draft.payAccounts[c.key]);

    return `
      ${head('Posting accounts', 'The account in the chart each pay component is charged or credited to when a run posts. Payroll is the subsidiary record behind these accounts, so a run cannot be approved until every component its journal carries has one.')}
      ${options.length ? '' : warn('The chart of accounts is empty, so there is nothing to map to yet. Import it on the Chart of accounts screen first.')}
      ${missing.length ? warn(`${plural(missing.length, 'component has', 'components have')} no account: ${missing.map(c => c.name).join(', ')}. A run is calculated but cannot be approved or posted until each one is set.`) : ''}
      <div class="st-table"><div style="min-width:560px;">
        <div class="st-tr st-th" style="grid-template-columns:minmax(170px,1fr) 118px minmax(180px,1fr);"><div>Pay component</div><div>On the run</div><div>Posts to</div></div>
        ${rows.map(c => `
          <div class="st-tr" style="grid-template-columns:minmax(170px,1fr) 118px minmax(180px,1fr);">
            <div class="st-stack"><span>${esc(c.name)}</span><span class="st-sub">${c.required ? 'Every run posts this' : 'Only when it is paid'}</span></div>
            <div class="st-muted">${esc(c.kind)}</div>
            <div><select class="st-cell" data-bind="payAccounts.${esc(c.key)}" aria-label="Account for ${esc(c.name)}">
              <option value="">${c.required ? '— not set —' : 'No account'}</option>
              ${options.map(o => `<option value="${esc(o.code)}" ${o.code === draft.payAccounts[c.key] ? 'selected' : ''}>${esc(short(o.code + ' · ' + o.name))}</option>`).join('')}
            </select></div>
          </div>`).join('')}
      </div></div>`;
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
        ${payAccounts()}
        ${rule}
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
    const list = data.users.filter(u => !q || [u.name, u.email, u.roles.join(' '), u.entities, u.status].join(' ').toLowerCase().includes(q));
    const pages = Math.max(1, Math.ceil(list.length / USERS_PER_PAGE));
    view.userPage = Math.min(view.userPage, pages - 1);
    const shown = list.slice(view.userPage * USERS_PER_PAGE, (view.userPage + 1) * USERS_PER_PAGE);
    const count = (s) => data.users.filter(u => u.status === s).length;
    const holders = (role) => data.users.filter(u => u.status === 'Active' && u.roles.includes(role));
    const fm = holders('Finance Manager').length;
    const ed = holders('Executive Director').length;
    const warning = fm === 0 ? 'No active Finance Manager. Journal and bill approvals above their thresholds cannot be actioned until one is assigned.'
      : ed === 0 ? 'No active Executive Director. Payment runs, sub-grants and inter-fund transfers will queue without an approver.'
      : fm > 2 ? `${fm} users hold the Finance Manager role. Auditors normally expect this to be limited to one or two.` : '';
    const noMfa = data.users.filter(u => u.status === 'Active' && !u.mfa).length;
    const cols = 'grid-template-columns:minmax(170px,1fr) 210px 130px 96px 96px 84px;';
    const status = (u) => u.locked ? '<span class="st-invited">⊘ Locked</span>'
      : u.status === 'Active' ? '<span class="st-live">● Active</span>' : u.status === 'Invited' ? '<span class="st-invited">◐ Invited</span>' : '<span class="st-dormant">○ Suspended</span>';

    main.innerHTML = `
      <div class="st-body wide">
        <div class="st-head">
          <div><div class="st-kicker">Users</div><div class="st-note">${count('Active')} active users · ${count('Invited')} invited · ${count('Suspended')} suspended. People get permissions only through their roles — a person can hold several, each at the entities named.</div></div>
          <label class="st-search">⌕<input data-free data-query="user" value="${esc(view.userQuery)}" placeholder="Name, email or role"></label>
          <button type="button" class="btn" data-act="invite" data-manage-users>Invite user</button>
        </div>
        <div class="st-table"><div style="min-width:780px;">
          <div class="st-tr st-th" style="${cols}"><div>User</div><div>Roles</div><div>Entity access</div><div>Second step</div><div>Status</div><div></div></div>
          ${shown.map(u => `
            <div class="st-tr" style="${cols}min-height:48px;">
              <div class="st-user"><span class="st-avatar">${esc(u.initials)}</span><span class="st-stack"><span class="st-ellipsis">${esc(u.name)}</span><span class="st-sub st-ellipsis">${esc(u.email)}</span></span></div>
              <div class="st-chips">${u.roles.map(r => `<span class="st-chip">${esc(r)}</span>`).join('')}</div>
              <div class="st-ellipsis">${esc(u.entities)}</div>
              <div class="st-muted">${u.mfa === 'totp' ? 'App' : u.mfa === 'email' ? 'Email' : '—'}</div>
              <div>${status(u)}</div>
              <div class="st-rowacts"><button type="button" class="btn" data-act="user-access" data-id="${u.id}">Manage</button></div>
            </div>`).join('') || '<div class="coa-empty">No users match.</div>'}
          ${pager('user', list.length, USERS_PER_PAGE, view.userPage, 'users')}
        </div></div>
        ${warn(warning)}
        ${noMfa ? `<div class="st-note">${noMfa === 1 ? '1 active user has' : noMfa + ' active users have'} no second sign-in step yet. Where the instance requires one, they are asked to set it up at their next sign-in.</div>` : ''}
      </div>`;
    wireQuery(main, 'user');
  }

  function roles(main) {
    const catalogue = data.permissionCatalogue;
    const described = (key) => (catalogue.find(p => p.key === key) || { description: key }).description;
    main.innerHTML = `
      <div class="st-body wide">
        <div class="st-head">
          <div><div class="st-kicker">Roles</div><div class="st-note">${data.roleDetail.length} roles. A role is a named set of permissions; people hold roles, never permissions directly. Add as many as the organisation needs. The roles the application starts with keep their names because the approval policy and close checklist refer to them.</div></div>
          <button type="button" class="btn" data-act="role-new" data-manage-users>New role</button>
        </div>
        <div class="st-roles">
          ${data.roleDetail.map(r => `
            <div class="st-role-card">
              <div class="st-role-head">
                <span class="st-role-name">${esc(r.name)}</span>
                ${r.builtIn ? '<span class="st-chip muted">Built-in</span>' : ''}
                <button type="button" class="btn" data-act="role-edit" data-id="${r.id}">${data.canManageUsers ? 'Edit' : 'View'}</button>
              </div>
              <div class="st-role-meta">${r.holders.length ? `${r.holders.length === 1 ? '1 person' : r.holders.length + ' people'} · ${esc(r.holders.slice(0, 4).join(', '))}${r.holders.length > 4 ? '…' : ''}` : 'Nobody holds it yet'}</div>
              ${r.description ? `<div class="st-note">${esc(r.description)}</div>` : ''}
              <div class="st-chips">${r.permissions.map(p => `<span class="st-chip" title="${esc(described(p))}">${esc(p)}</span>`).join('') || '<span class="st-chip muted">No permissions</span>'}</div>
            </div>`).join('')}
        </div>
      </div>`;
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

  /**
   * The interface theme. One theme is held for the whole organisation, so a pick
   * here is a change to a setting like any other: it is drafted, saved with
   * everything else, and logged. The shell repaints as the pick is made — the
   * swatches and the page are drawn from the same custom properties — so the
   * choice is judged on the real screen rather than on a row of squares, and
   * Discard puts the old theme back.
   */
  function appearance(main) {
    const a = draft.appearance;
    const logo = data.appearance.logo;
    main.innerHTML = `
      <div class="st-body">
        <div class="st-block">
          ${head('Name and logo', 'What the application calls itself in the sidebar and the browser tab. This is the name on the screen your staff work in — the registered name that prints on statements is on the Organisation section, and the two need not match.')}
          <div class="st-grid">
            ${field('Application name', 'appearance.appName', a.appName, 'maxlength="40"')}
            ${field('Line underneath', 'appearance.appTagline', a.appTagline, 'maxlength="60"')}
          </div>
          <div class="st-brandrow">
            <div class="st-brandmark">
              ${logo ? `<img src="${esc(logo)}" alt="" class="st-brandlogo">` : `<span class="st-brandinitials">${esc(mark(a.appName))}</span>`}
            </div>
            <div class="st-stack">
              <span class="st-strong">${logo ? 'Logo' : 'No logo — the initials are drawn instead'}</span>
              <span class="st-sub">PNG, JPEG or WebP, under 500 KB. It is drawn at 30 pixels square, so a mark reads better than a wordmark. Saved as it is chosen, not with the draft.</span>
            </div>
            <div class="st-brandbtns">
              <label class="btn">${logo ? 'Replace' : 'Upload'}<input type="file" id="st-logo" accept="image/png,image/jpeg,image/webp" hidden></label>
              ${logo ? '<button type="button" class="btn" data-act="logo-remove" data-manage>Remove</button>' : ''}
            </div>
          </div>
        </div>
        ${rule}
        <div class="st-block">
          ${head('Interface theme', 'The colours everyone here reads the ledger in. It changes what is on screen — never a figure, a code or a date. Urgent, warning and settled keep their own colours in every theme, so an exception always reads as an exception.')}
          <div class="st-themes">
            ${data.themes.map(t => `
              <button type="button" class="st-theme ${t.key === a.theme ? 'on' : ''}" data-act="theme" data-id="${esc(t.key)}" data-manage aria-pressed="${t.key === a.theme}">
                <span class="st-theme-swatch" data-theme="${esc(t.key)}" ${t.key === 'custom' ? `style="--accent:${esc(a.custom.accent)};--rail-bg:${esc(a.custom.rail)}"` : ''} aria-hidden="true">
                  <span class="st-theme-rail"><span class="st-theme-mark"></span><span class="st-theme-line"></span><span class="st-theme-line short"></span></span>
                  <span class="st-theme-page"><span class="st-theme-btn"></span><span class="st-theme-text"></span><span class="st-theme-text short"></span></span>
                </span>
                <span class="st-theme-name">${esc(t.name)}${t.key === a.theme ? '<span class="st-theme-tick">✓</span>' : ''}</span>
                <span class="st-theme-note">${esc(t.note)}</span>
              </button>`).join('')}
          </div>
          ${a.theme === 'custom' ? customColours(a.custom) : ''}
          <div class="st-note">A theme is not a permission. It changes nothing about who can post, approve or read a record.</div>
        </div>
      </div>`;

    main.querySelector('#st-logo')?.addEventListener('change', uploadLogo);
    main.querySelectorAll('[data-colour]').forEach(el => el.addEventListener('change', onColour));
  }

  /**
   * The two colours a custom theme is built from. Only these are chosen: the
   * hover, the active row, the menu labels and the darker button state are all
   * mixed from them by the stylesheet, so a picked pair cannot come out as a
   * palette whose parts do not belong together.
   *
   * Each is held to a contrast ratio against white, because both carry light text.
   * The ratio is shown as it is picked rather than only when a save is refused.
   */
  function customColours(custom) {
    const parts = [
      { key: 'accent', label: 'Accent', note: 'Buttons, links and the active state.', least: 4.5 },
      { key: 'rail', label: 'Menu', note: 'The sidebar the navigation sits on.', least: 7 },
    ];
    return `
      <div class="st-custom">
        ${parts.map(p => {
          const value = custom[p.key];
          const ratio = contrast(value);
          const ok = ratio >= p.least;
          return `
          <div class="st-customrow">
            <input type="color" class="st-swatchpick" value="${esc(value)}" data-colour="${p.key}" data-manage aria-label="${esc(p.label)} colour">
            <div class="st-stack">
              <span class="st-strong">${esc(p.label)}</span>
              <span class="st-sub">${esc(p.note)}</span>
            </div>
            <input class="st-cell mono boxed" style="max-width:104px;" value="${esc(value)}" data-colour="${p.key}" data-manage aria-label="${esc(p.label)} hex value">
            <span class="${ok ? 'st-live' : 'st-fail'}">${ok ? '●' : '▲'} ${ratio}:1${ok ? '' : ' · needs ' + p.least}</span>
          </div>`;
        }).join('')}
        <span class="st-sub">Contrast is measured against white text. Anything below the mark is refused on save — the figure is here so you can see how much darker to go.</span>
      </div>`;
  }

  /** A colour picked from the swatch or typed as hex. Picking one also selects the custom theme — that is what picking it means. */
  function onColour(e) {
    const value = String(e.target.value || '').trim().toLowerCase();
    if (!/^#[0-9a-f]{6}$/.test(value)) {
      UI.toast('Give the colour as a hex value, such as #0F5C4A.');
      renderSection();
      return;
    }
    draft.appearance.custom[e.target.dataset.colour] = value;
    draft.appearance.theme = 'custom';
    paintTheme(draft.appearance);
    renderHead();
    renderSection();
  }

  /** The initials the sidebar draws when there is no logo, as the server derives them. */
  function mark(name) {
    const words = String(name).trim().split(/\s+/);
    return (words.length > 1 ? words[0][0] + words[1][0] : String(name).slice(0, 2)).toUpperCase() || '··';
  }

  /** The WCAG contrast ratio of a colour against white, as the API measures it. */
  function contrast(hex) {
    const v = /^#([0-9a-f]{6})$/i.test(hex) ? hex : '#000000';
    const channel = (c) => { const x = parseInt(c, 16) / 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); };
    const l = 0.2126 * channel(v.slice(1, 3)) + 0.7152 * channel(v.slice(3, 5)) + 0.0722 * channel(v.slice(5, 7));
    return Math.round((1.05 / (l + 0.05)) * 10) / 10;
  }

  async function uploadLogo(e) {
    const file = e.target.files && e.target.files[0];
    if (!file) return;
    const body = new FormData();
    body.append('logo', file);
    try {
      const res = await fetch('/api/settings/logo', { method: 'POST', headers: { Accept: 'application/json' }, body });
      const saved = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(saved.error || `Request failed: ${res.status}`);
      afterLogo(saved);
    } catch (err) {
      UI.toast(err.message);
    } finally {
      e.target.value = '';
    }
  }

  /** A logo change lands outside the draft, so the held settings are replaced and the shell repainted without touching unsaved edits elsewhere. */
  function afterLogo(res) {
    const edits = draft;
    data = res;
    draft = edits;
    renderHead();
    renderSection();
    paintBrand(res.appearance);
    UI.toast(res.message);
  }

  /** The sidebar and the tab, repainted after a logo or a name change without a reload. */
  function paintBrand(appearance) {
    const brand = document.querySelector('.brand');
    if (!brand) return;
    const mark_ = brand.querySelector('.brand-mark, .brand-logo');
    if (mark_) {
      mark_.outerHTML = appearance.logo
        ? `<img class="brand-logo" src="${esc(appearance.logo)}" alt="${esc(appearance.appName)}">`
        : `<div class="brand-mark">${esc(mark(appearance.appName))}</div>`;
    }
    const name = brand.querySelector('.brand-name');
    const sub = brand.querySelector('.brand-sub');
    if (name) name.textContent = appearance.appName;
    if (sub) sub.textContent = appearance.appTagline;
    document.title = document.title.split(' · ')[0] + ' · ' + [appearance.appName, appearance.appTagline].filter(Boolean).join(' ');
  }

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
    const covColour = (pct) => pct >= 95 ? 'var(--calm-ink)' : pct >= 80 ? '#8A6A2E' : '#A45B3E';
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

    if (act === 'theme') {
      draft.appearance.theme = id;
      paintTheme(draft.appearance);
      renderHead();
      renderSection();
      return;
    }
    if (act === 'logo-remove') {
      try {
        afterLogo(await UI.postJSON('/api/settings/logo/remove', {}));
      } catch (err) {
        UI.toast(err.message);
      }
      return;
    }
    if (act === 'ent-add') {
      const f = view.entForm;
      const code = f.code.trim().toUpperCase();
      const name = f.name.trim();
      if (!/^[A-Z0-9][A-Z0-9-]{1,19}$/.test(code)) return UI.toast('Use 2 to 20 letters, digits and hyphens for the code — ELOG-RV.');
      if (draft.entities.some(e => e.code === code)) return UI.toast(code + ' is already an entity code.');
      if (!name) return UI.toast('Name the entity as it should read on a consolidated statement.');
      if (draft.entities.some(e => e.name.toLowerCase() === name.toLowerCase())) return UI.toast(name + ' is already the name of another entity.');
      draft.entities.push({ code, name, type: f.type, currency: f.currency || draft.ledger.currency, status: 'Live' });
      view.entForm = { code: '', name: '', type: 'Branch', currency: '' };
      UI.toast(`${name} added — save to open it for posting.`);
    }
    // A fund is opened as it is entered: the ledger refers to it, so it cannot wait
    // in a draft in the browser while something is coded to it.
    if (act === 'fund-add') {
      const f = view.fundForm;
      const code = f.code.trim().toUpperCase();
      if (!/^[A-Z0-9][A-Z0-9-]{1,19}$/.test(code)) return UI.toast('Use 2 to 20 letters, digits and hyphens for the code — FND-100.');
      if (!f.name.trim()) return UI.toast('Name the fund. It is what the statements and every donor report call it.');
      const group = { 'General Fund': 'general', 'Grant Fund': 'grant', 'Capital Fund': 'capital', 'Endowment Fund': 'endowment' }[f.group];
      try {
        const res = await UI.postJSON('/api/funds', {
          code, name: f.name.trim(), restriction: f.restriction.toLowerCase(), ledgerGroup: group, funder: f.funder || '',
        });
        view.fundForm = { code: '', name: '', restriction: 'Unrestricted', group: 'General Fund', funder: '' };
        data.funds = res.funds;
        render();
        UI.toast(res.message);
      } catch (err) {
        UI.toast(err.message);
      }
      return;
    }
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
    if (act === 'wht-add') draft.taxes.wht.push({ rate: '', label: '' });
    if (act === 'wht-remove') draft.taxes.wht.splice(Number(id), 1);
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
    if (act === 'user-access') {
      openAccess(Number(id));
      return;
    }
    if (act === 'role-new' || act === 'role-edit') {
      openRole(act === 'role-new' ? null : data.roleDetail.find(r => r.id === Number(id)));
      return;
    }
    renderHead();
    renderSection();
  }

  function openInvite() {
    UI.drawer('Invite user', `
      <div class="bu" id="st-invite">
        <p class="bu-intro">The invitation is sent by email and expires after seven days. The person chooses a password and sets up a second sign-in step, and appears as Invited until they do. Each role applies at the entities chosen below; give different roles at different entities afterwards with Manage.</p>
        <label class="bu-field"><span>Full name</span><input id="iv-name" maxlength="120" autocomplete="off"></label>
        <label class="bu-field"><span>Email</span><input id="iv-email" type="email" maxlength="190" autocomplete="off"></label>
        <div class="bu-field"><span>Roles <em>one or more</em></span>
          <div class="st-invite-entities" id="iv-roles">${data.roles.map(r => `<label class="st-inline"><input type="checkbox" value="${esc(r)}">${esc(r)}</label>`).join('')}</div>
        </div>
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
          name: box.querySelector('#iv-name').value, email: box.querySelector('#iv-email').value,
          roles: [...box.querySelectorAll('#iv-roles input:checked')].map(i => i.value),
          entities: all ? 'all' : [...box.querySelectorAll('#iv-entities input:checked')].map(i => i.value),
        });
        UI.closeDrawer();
        // Keep unsaved edits; take the new user list from the server.
        const pending = draft;
        data = res;
        draft = pending;
        render();
        UI.toast(res.message);
      } catch (err) {
        UI.toast(err.message);
      }
    });
  }

  /** Merges what a user or role change returns into the screen, keeping any unsaved draft. */
  function absorb(res) {
    for (const key of ['users', 'roles', 'audit']) if (res[key]) data[key] = res[key];
    if (res.roles && res.roles[0] && typeof res.roles[0] === 'object') {
      data.roleDetail = res.roles;
      data.roles = res.roles.map(r => r.name);
    }
    if (res.roleDetail) data.roleDetail = res.roleDetail;
    if (res.catalogue) data.permissionCatalogue = res.catalogue;
    renderSection();
  }

  /** Manage: a user's roles (any number, each at its own entities) and the account itself. */
  function openAccess(userId) {
    const u = data.users.find(x => x.id === userId);
    if (!u) return;
    const rows = u.access.map(a => ({ role: a.role, all: a.entities === 'all', codes: a.entities === 'all' ? [] : [...a.entities] }));
    const can = data.canManageUsers;
    const self = u.email === data.me;

    function draw() {
      UI.drawer(u.name, `
        <div class="bu" id="st-access">
          <p class="bu-intro">${esc(u.email)} · ${esc(u.status)}${u.mfa ? ' · second step: ' + (u.mfa === 'totp' ? 'authenticator app' : 'email') : ' · no second step yet'}${u.locked ? ' · locked after wrong passwords' : ''}</p>
          <div class="st-kicker">Roles</div>
          ${rows.map((r, i) => `
            <div class="st-access-row" data-row="${i}">
              <div class="st-access-head">
                <select data-f="role" aria-label="Role" ${can ? '' : 'disabled'}>${data.roles.map(n => `<option ${n === r.role ? 'selected' : ''}>${esc(n)}</option>`).join('')}</select>
                <button type="button" class="btn" data-f="remove" title="Remove this role" ${can ? '' : 'disabled'}>✕</button>
              </div>
              <label class="st-inline"><input type="checkbox" data-f="all" ${r.all ? 'checked' : ''} ${can ? '' : 'disabled'}>All entities</label>
              <div class="st-invite-entities" ${r.all ? 'hidden' : ''}>${data.entityOptions.map(e => `<label class="st-inline"><input type="checkbox" data-f="entity" value="${esc(e.code)}" ${r.codes.includes(e.code) ? 'checked' : ''} ${can ? '' : 'disabled'}>${esc(e.name)}</label>`).join('')}</div>
            </div>`).join('') || '<p class="bu-intro">No roles. Add at least one.</p>'}
          <div class="bu-actions" style="justify-content:space-between;">
            <button type="button" class="btn" id="ac-add" ${can ? '' : 'disabled'}>+ Add a role</button>
            <button type="button" class="btn btn-primary" id="ac-save" ${can ? '' : 'disabled'}>Save roles</button>
          </div>
          <div class="st-kicker" style="margin-top:8px;">Account</div>
          <div class="bu-actions" style="justify-content:flex-start;">
            ${!u.hasPassword && u.status !== 'Suspended' ? `<button type="button" class="btn" data-acct="invite" ${can ? '' : 'disabled'}>${u.status === 'Invited' ? 'Send the invitation again' : 'Email a link to set a password'}</button>` : ''}
            ${u.mfa ? `<button type="button" class="btn" data-acct="reset-mfa" ${can ? '' : 'disabled'}>Reset second step</button>` : ''}
            ${u.status === 'Suspended'
              ? `<button type="button" class="btn" data-acct="reinstate" ${can ? '' : 'disabled'}>Reinstate</button>`
              : `<button type="button" class="btn" data-acct="suspend" ${can && !self ? '' : 'disabled'}>Suspend</button>`}
          </div>
          <p class="bu-intro">${can ? (u.mfa ? 'Reset the second step for someone who has lost their phone and their recovery codes — they set up a new one at their next sign-in. ' : '') + 'A suspended user is signed out and cannot sign in.' : 'Changing access needs a role with users.manage.'}</p>
        </div>`);
      const box = document.getElementById('st-access');
      box.querySelectorAll('[data-row]').forEach(el => {
        const r = rows[Number(el.dataset.row)];
        el.querySelector('[data-f="role"]').addEventListener('change', e => { r.role = e.target.value; });
        el.querySelector('[data-f="all"]').addEventListener('change', e => { r.all = e.target.checked; draw(); });
        el.querySelectorAll('[data-f="entity"]').forEach(c => c.addEventListener('change', () => {
          r.codes = [...el.querySelectorAll('[data-f="entity"]:checked')].map(x => x.value);
        }));
        el.querySelector('[data-f="remove"]').addEventListener('click', () => { rows.splice(Number(el.dataset.row), 1); draw(); });
      });
      box.querySelector('#ac-add').addEventListener('click', () => {
        const unused = data.roles.find(n => !rows.some(r => r.role === n)) || data.roles[0];
        rows.push({ role: unused, all: true, codes: [] });
        draw();
      });
      box.querySelector('#ac-save').addEventListener('click', () => act(`/api/users/${u.id}/access`, {
        access: rows.map(r => ({ role: r.role, entities: r.all ? 'all' : r.codes })),
      }));
      box.querySelectorAll('[data-acct]').forEach(b => b.addEventListener('click', () => {
        const what = b.dataset.acct;
        if (what === 'suspend' && !confirm(`Suspend ${u.name}? They are signed out and cannot sign in until reinstated.`)) return;
        if (what === 'reset-mfa' && !confirm(`Reset ${u.name}'s second sign-in step? Their authenticator and recovery codes stop working.`)) return;
        act(`/api/users/${u.id}/${what}`, {});
      }));
    }

    async function act(url, body) {
      try {
        const res = await UI.postJSON(url, body);
        absorb(res);
        UI.closeDrawer();
        UI.toast(res.message);
      } catch (err) {
        UI.toast(err.message);
      }
    }

    draw();
  }

  /** A role: its name, what it is for, and its permissions. `role` null for a new one. */
  function openRole(role) {
    const can = data.canManageUsers;
    const held = new Set(role ? role.permissions : []);
    const groups = [...new Set(data.permissionCatalogue.map(p => p.group))];
    UI.drawer(role ? role.name : 'New role', `
      <div class="bu" id="st-role">
        ${role ? `<p class="bu-intro">${role.holders.length ? `Held by ${esc(role.holders.join(', '))}. A change applies to all of them from their next request.` : 'Nobody holds this role yet.'}</p>` : '<p class="bu-intro">Name the role as people would say it, then tick what it lets its holders do. Give it to people under Users → Manage.</p>'}
        <label class="bu-field"><span>Name${role && role.builtIn ? ' <em>built-in — kept as it is</em>' : ''}</span><input id="rl-name" maxlength="60" value="${esc(role ? role.name : '')}" ${can && !(role && role.builtIn) ? '' : 'disabled'}></label>
        <label class="bu-field"><span>What it is for <em>optional</em></span><textarea id="rl-desc" rows="2" maxlength="500" ${can ? '' : 'disabled'}>${esc(role ? role.description : '')}</textarea></label>
        ${groups.map(g => `
          <div class="st-perm-group">
            <div>${esc(g)}</div>
            ${data.permissionCatalogue.filter(p => p.group === g).map(p => `
              <label class="st-perm"><input type="checkbox" value="${esc(p.key)}" ${held.has(p.key) ? 'checked' : ''} ${can ? '' : 'disabled'}><span>${esc(p.description)} <code>${esc(p.key)}</code></span></label>`).join('')}
          </div>`).join('')}
        <div class="bu-actions" style="justify-content:space-between;">
          <span>${role && !role.builtIn ? `<button type="button" class="btn" id="rl-delete" ${can ? '' : 'disabled'}>Delete role</button>` : ''}</span>
          <span style="display:flex;gap:8px;"><button type="button" class="btn" id="rl-cancel">Cancel</button><button type="button" class="btn btn-primary" id="rl-save" ${can ? '' : 'disabled'}>${role ? 'Save role' : 'Add role'}</button></span>
        </div>
      </div>`);
    const box = document.getElementById('st-role');
    box.querySelector('#rl-cancel').addEventListener('click', UI.closeDrawer);
    box.querySelector('#rl-save').addEventListener('click', async () => {
      try {
        const res = await UI.postJSON(role ? `/api/roles/${role.id}` : '/api/roles', {
          name: box.querySelector('#rl-name').value, description: box.querySelector('#rl-desc').value,
          permissions: [...box.querySelectorAll('.st-perm input:checked')].map(i => i.value),
        });
        absorb(res);
        // Users show role names; take them fresh too.
        absorb(await UI.fetchJSON('/api/settings').then(s => ({ users: s.users, audit: s.audit })));
        UI.closeDrawer();
        UI.toast(res.message);
      } catch (err) {
        UI.toast(err.message);
      }
    });
    box.querySelector('#rl-delete')?.addEventListener('click', async () => {
      if (!confirm(`Delete the ${role.name} role?`)) return;
      try {
        const res = await UI.postJSON(`/api/roles/${role.id}/delete`, {});
        absorb(res);
        UI.closeDrawer();
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
  const form = { open: false, account: { code: '', name: '', shortName: '', kind: 'bank', bankName: '', accountNumber: '', currency: 'KES' } };

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

  async function openAccount() {
    const a = { ...form.account, code: form.account.code || (data.candidates[0] || {}).code || '' };
    if (!a.name.trim()) return UI.toast('Name the account — it is what the reconciliation and the cash book show.');

    try {
      const res = await UI.postJSON('/api/statement-formats/account', a);
      Object.assign(data, { formats: res.formats, accounts: res.accounts, candidates: res.candidates });
      form.open = false;
      form.account = { code: '', name: '', shortName: '', kind: 'bank', bankName: '', accountNumber: '', currency: 'KES' };
      render();
      UI.toast(res.message);
    } catch (err) {
      UI.toast(err.message);
    }
  }

  /**
   * Opening a cash account. The ledger account comes first: a reconciliation agrees
   * the statement of the account behind it, so only a postable asset account that
   * does not already carry one is offered.
   */
  function newAccount() {
    if (!data.canManage) return '';
    if (!data.candidates.length) {
      return `<p class="bu-intro">Every postable asset account already carries a cash account. Add one to the chart of accounts to open another.</p>`;
    }
    const a = form.account;

    return `
      <details class="sf-new" ${form.open ? 'open' : ''}>
        <summary>Open a cash account</summary>
        <div class="bu-grid" style="margin-top:12px;">
          <label class="bu-field"><span>Ledger account</span>
            <select data-a="code">${data.candidates.map(c => `<option value="${esc(c.code)}" ${c.code === a.code ? 'selected' : ''}>${esc(c.code)} · ${esc(c.name)}</option>`).join('')}</select></label>
          <label class="bu-field"><span>Account name</span>
            <input data-a="name" value="${esc(a.name)}" placeholder="KCB Current Account" maxlength="120"></label>
          <label class="bu-field"><span>Short name <em>on the reconciliation</em></span>
            <input data-a="shortName" value="${esc(a.shortName)}" placeholder="KCB Current" maxlength="40"></label>
          <label class="bu-field"><span>Kind</span>
            <select data-a="kind">${Object.entries(data.kinds).map(([k, label]) => `<option value="${esc(k)}" ${k === a.kind ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select></label>
          ${a.kind === 'petty_cash' ? '' : `
            <label class="bu-field"><span>Bank <em>or provider</em></span>
              <input data-a="bankName" value="${esc(a.bankName)}" placeholder="KCB" maxlength="60"></label>
            <label class="bu-field"><span>Account number</span>
              <input data-a="accountNumber" value="${esc(a.accountNumber)}" placeholder="1104578921" class="mono" maxlength="40"></label>`}
          <label class="bu-field"><span>Currency</span>
            <input data-a="currency" value="${esc(a.currency)}" class="mono upper" maxlength="3"></label>
        </div>
        <div class="bu-intro" style="margin-top:8px;">${a.kind === 'petty_cash'
          ? 'Petty cash takes no statement, so it takes no format — it is counted and agreed by hand.'
          : 'Assign it a statement format below once it is open; a statement cannot be loaded without one.'}</div>
        <div class="bu-actions" style="margin-top:10px;"><button type="button" class="btn btn-primary" data-open-account>Open account</button></div>
      </details>`;
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
            </div>`).join('') || '<div class="coa-empty">No cash accounts yet. A reconciliation agrees one of these to its bank statement.</div>'}
        </div>
        ${newAccount()}
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

    root.ontoggle = (e) => {
      if (e.target.classList && e.target.classList.contains('sf-new')) form.open = e.target.open;
    };
    root.oninput = (e) => {
      const field = e.target.closest('[data-a]');
      if (field) form.account[field.dataset.a] = field.value;
    };
    root.onchange = async (e) => {
      // The kind decides which fields the panel shows, so it redraws; the rest do not.
      const field = e.target.closest('[data-a]');
      if (field) {
        form.account[field.dataset.a] = field.value;
        if (field.dataset.a === 'kind') {
          form.open = true;
          render();
        }
        return;
      }
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
      if (btn.dataset.openAccount !== undefined) return openAccount();
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

/**
 * Settings → Integrations → M-Pesa.
 *
 * The panel edits its own copy of the integration and saves it in one call: the
 * API applies what differs and answers with the integration as it now stands, so
 * turning a service on together with the credentials it needs is one save and one
 * refusal if anything is missing.
 *
 * A credential is written and never read back — the panel shows which are set and
 * their last four characters, keeps what has been typed until it is saved, and
 * sends only that. "Check connection" asks Safaricom for an access token; no money
 * moves and nothing is sent to the payer.
 */
const Mpesa = (() => {
  const esc = UI.esc;
  let root = null;
  let data = null;
  let form = null;
  let typed = {};
  let cleared = [];

  const FIELDS = ['environment', 'shortcodeKind', 'shortcode', 'accountReference', 'account', 'callbackBase', 'initiatorName', 'ceiling'];
  const SWITCHES = ['collections', 'disbursements', 'autoMatch'];

  const toForm = (m) => Object.fromEntries([...FIELDS, ...SWITCHES].map(k => [k, m[k]]));
  const dirty = () => JSON.stringify(form) !== JSON.stringify(toForm(data.mpesa)) || Object.keys(typed).length > 0 || cleared.length > 0;

  async function mount(container) {
    // Edits survive a trip to another section and back; only a clean panel reloads.
    const unsaved = data !== null && form !== null && dirty();
    root = container;
    if (unsaved) {
      render();
      return;
    }
    root.innerHTML = '<div class="coa-empty">Loading the M-Pesa integration…</div>';
    try {
      data = await UI.fetchJSON('/api/mpesa');
    } catch (err) {
      root.innerHTML = `<div class="coa-empty">${esc(err.message)}</div>`;
      return;
    }
    reset();
    render();
  }

  function reset() {
    form = toForm(data.mpesa);
    typed = {};
    cleared = [];
  }

  function render() {
    if (!root || !root.isConnected) return;
    const m = data.mpesa;
    const o = data.options;
    const can = data.canManage;
    const paybill = form.shortcodeKind === 'paybill';
    const field = (label, key, hint, attrs) => `
      <label class="bu-field"><span>${esc(label)}${hint ? ` <em>${esc(hint)}</em>` : ''}</span>
        <input data-k="${key}" value="${esc(form[key] ?? '')}" autocomplete="off" ${attrs || ''}></label>`;
    const choose = (label, key, options, hint) => `
      <label class="bu-field"><span>${esc(label)}${hint ? ` <em>${esc(hint)}</em>` : ''}</span>
        <select data-k="${key}">${options.map(x => `<option value="${esc(x.value)}" ${x.value === form[key] ? 'selected' : ''}>${esc(x.text)}</option>`).join('')}</select></label>`;

    root.innerHTML = `
      <div class="sf-cards">
        <div class="mp-status ${esc(m.status.state)}">
          <div class="mp-status-head"><span class="mp-dot"></span>M-Pesa · ${esc(m.status.label)}</div>
          <div class="mp-status-note">${esc(m.status.note)}</div>
          ${m.checked ? `<div class="mp-status-note mp-checked">Last checked ${esc(m.checked.when)} — ${esc(m.checked.result)}</div>` : ''}
          <div class="sf-btns"><button type="button" class="btn" data-check ${can ? '' : 'disabled'}>Check connection</button></div>
        </div>
        ${can ? '' : '<p class="bu-intro">Only the Finance Manager can change the integration — it moves money out of the organisation.</p>'}
        ${m.encryption ? '' : '<div class="st-warn">This installation has no encryption key, so credentials cannot be stored. Set encryption.key in .env (php spark key:generate) first — everything else on this page can still be set up.</div>'}

        <div class="sf-section">Short code</div>
        <div class="bu-grid">
          ${choose('Environment', 'environment', o.environments)}
          ${choose('Short code is a', 'shortcodeKind', o.kinds, noteOf(o.kinds, form.shortcodeKind))}
          ${field('Short code', 'shortcode', 'paybill or till', 'class="mono" inputmode="numeric" maxlength="7" placeholder="509118"')}
          ${paybill ? field('Account number payers quote', 'accountReference', 'optional', 'maxlength="20" placeholder="ELOG"') : ''}
          ${choose('Settles to', 'account', [{ value: '', text: 'No account — nothing can be collected or paid' }, ...o.accounts])}
          ${field('Callback address', 'callbackBase', 'where Safaricom posts results', 'placeholder="https://finance.elog.or.ke"')}
        </div>
        <div class="bu-intro">${esc(host())}${m.statementFormat ? ` · statements for ${esc(m.account)} are read as ${esc(m.statementFormat)}` : ''}</div>
        ${callbacks(m)}

        <div class="sf-section">Daraja credentials</div>
        <p class="bu-intro" style="margin-top:-6px;">Held encrypted and never shown again. Enter one only to set or replace it — leave it blank to keep what is held.</p>
        <div class="coa-card">
          ${m.credentials.map(c => credential(c, can)).join('')}
        </div>

        <div class="sf-section">Services</div>
        ${switchRow('collections', 'Collections', 'Money paid to the short code is received into the ledger against the settlement account.', m.outstanding.collections, can)}
        ${switchRow('disbursements', 'Payments', 'Supplier bills and staff advances can be paid out by M-Pesa.', m.outstanding.disbursements, can)}
        ${form.disbursements || m.disbursements ? `
          <div class="bu-grid" style="max-width:340px;">
            ${field('Most one payment may be', 'ceiling', `Safaricom's ceiling is ${Number(o.ceiling).toLocaleString('en-US')}`, 'class="mono end" inputmode="decimal"')}
          </div>` : ''}
        ${switchRow('autoMatch', 'Match receipts automatically', 'A receipt is matched to the cash book by its M-Pesa receipt number on the reconciliation; anything unmatched is left to be matched by hand.', '', can)}
        ${m.disbursements && m.pending.total ? `<div class="bu-intro">${esc(m.pending.note.charAt(0).toUpperCase() + m.pending.note.slice(1))}, so payments cannot be switched off until ${m.pending.total === 1 ? 'it is' : 'they are'} paid or rescheduled.</div>` : ''}

        ${can ? `<div class="bu-actions">
          <button type="button" class="btn" data-discard ${dirty() ? '' : 'hidden'}>Discard</button>
          <button type="button" class="btn btn-primary" data-save ${dirty() ? '' : 'disabled'}>${dirty() ? 'Save changes' : 'Saved'}</button>
        </div>` : ''}
      </div>`;

    if (!can) root.querySelectorAll('input, select').forEach(el => { el.disabled = true; });
    wire();
  }

  const noteOf = (options, value) => (options.find(o => o.value === value) || {}).note || '';

  const host = () => {
    const known = data.options.hosts[form.environment] || '';
    return form.environment === 'production'
      ? `Requests go to ${known} and move real money.`
      : `Requests go to ${known}. Nothing settles, and no money moves.`;
  };

  /**
   * The addresses each service's results belong at, built from the callback
   * address. They are registered on the Daraja portal alongside the short code.
   */
  function callbacks(m) {
    const shown = Object.values(m.callbacks).filter(c => c.url);
    if (!shown.length) return '';
    return `
      <div class="st-infobox">
        <div class="st-kicker">Where Safaricom's results belong</div>
        <div class="st-note">Register an address on the Daraja portal once the service it belongs to is switched on and can answer it.</div>
        ${shown.map(c => `<div class="mp-url"><span class="mono">${esc(c.url)}</span><span class="st-sub">${esc(c.label)}</span></div>`).join('')}
      </div>`;
  }

  function credential(c, can) {
    const value = typed[c.key] ?? '';
    const gone = cleared.includes(c.key);
    const state = gone ? 'Will be cleared when saved' : value ? 'Will be saved' : c.set ? (c.readable ? 'Set · ' + c.hint : c.hint) : 'Not set';
    return `
      <div class="sf-row mp-cred">
        <div>
          <div class="sf-name">${esc(c.label)} ${c.set && !gone ? '<span class="jr-pill posted">Set</span>' : '<span class="jr-pill draft">Needed</span>'}</div>
          <div class="sf-sub">${esc(c.note)}</div>
          <div class="sf-sub">${esc(state)}</div>
        </div>
        <div class="sf-btns">
          <input type="password" data-secret="${esc(c.key)}" value="${esc(value)}" placeholder="${c.set ? 'Enter to replace' : 'Enter the ' + c.label.toLowerCase()}" autocomplete="new-password" spellcheck="false">
          ${c.set && can ? `<button type="button" class="btn st-small" data-clear="${esc(c.key)}">${gone ? 'Keep' : 'Clear'}</button>` : ''}
        </div>
      </div>`;
  }

  function switchRow(key, label, note, missing, can) {
    const on = !!form[key];
    const blocked = !on && !!missing;
    return `
      <label class="st-check ${on ? 'on' : ''}">
        <input type="checkbox" data-switch="${key}" ${on ? 'checked' : ''} ${can ? '' : 'disabled'}>
        <span>
          <span class="st-check-label">${esc(label)}</span>
          <span class="st-check-note">${esc(note)}</span>
          ${blocked ? `<span class="st-check-example">Still needed: ${esc(missing)}</span>` : ''}
        </span>
      </label>`;
  }

  function wire() {
    root.oninput = (e) => {
      const el = e.target;
      if (el.dataset.secret !== undefined) {
        if (el.value === '') delete typed[el.dataset.secret];
        else typed[el.dataset.secret] = el.value;
        refreshActions();
        return;
      }
      if (el.dataset.k === undefined) return;
      form[el.dataset.k] = el.value;
      refreshActions();
    };
    root.onchange = (e) => {
      const el = e.target;
      if (el.dataset.switch !== undefined) {
        form[el.dataset.switch] = el.checked;
        render();
        return;
      }
      if (el.tagName === 'SELECT' && el.dataset.k !== undefined) {
        form[el.dataset.k] = el.value;
        render();
      }
    };
    root.onclick = async (e) => {
      const btn = e.target.closest('button');
      if (!btn) return;
      if (btn.dataset.clear !== undefined) {
        const key = btn.dataset.clear;
        cleared = cleared.includes(key) ? cleared.filter(k => k !== key) : [...cleared, key];
        delete typed[key];
        render();
        return;
      }
      if (btn.dataset.discard !== undefined) {
        reset();
        render();
        UI.toast('Unsaved M-Pesa changes discarded.');
        return;
      }
      if (btn.dataset.save !== undefined) await save(btn);
      if (btn.dataset.check !== undefined) await check(btn);
    };
  }

  /** Keeps the buttons honest while typing, without re-rendering the fields under the cursor. */
  function refreshActions() {
    const save = root.querySelector('[data-save]');
    if (!save) return;
    const changed = dirty();
    save.disabled = !changed;
    save.textContent = changed ? 'Save changes' : 'Saved';
    root.querySelector('[data-discard]').hidden = !changed;
  }

  async function save(button) {
    button.disabled = true;
    try {
      const res = await UI.postJSON('/api/mpesa', { ...form, ...typed, clear: cleared });
      data = { mpesa: res.mpesa, options: res.options, canManage: res.canManage };
      reset();
      render();
      UI.toast(res.message);
    } catch (err) {
      button.disabled = false;
      UI.toast(err.message);
    }
  }

  async function check(button) {
    const was = button.textContent;
    button.disabled = true;
    button.textContent = 'Checking…';
    try {
      const res = await UI.postJSON('/api/mpesa/check');
      data.mpesa = res.mpesa;
      render();
      UI.toast(res.message);
    } catch (err) {
      UI.toast(err.message);
    } finally {
      button.disabled = false;
      button.textContent = was;
    }
  }

  return { mount };
})();

/**
 * Settings → Integrations → Email: the SMTP server invitations, password resets
 * and sign-in codes go out through.
 *
 * Saved as it is made, like M-Pesa: the password is written and never read back —
 * the panel shows whether it is set and its last four characters, and sends it only
 * when something has been typed. The note at the top says which server mail is
 * actually going through; outside production that is .env's (Mailpit while
 * developing), whatever is set here. "Send a test message" mails the person asking.
 */
const MailServer = (() => {
  const esc = UI.esc;
  let root = null;
  let data = null;
  let form = null;
  let password = '';
  let clearPassword = false;

  const FIELDS = ['host', 'port', 'crypto', 'username', 'fromEmail', 'fromName'];
  const toForm = (m) => Object.fromEntries(FIELDS.map(k => [k, m[k]]));
  const dirty = () => JSON.stringify(form) !== JSON.stringify(toForm(data.mail)) || password !== '' || clearPassword;

  async function mount(container) {
    const unsaved = data !== null && form !== null && dirty();
    root = container;
    if (unsaved) {
      render();
      return;
    }
    root.innerHTML = '<div class="coa-empty">Loading the mail server…</div>';
    try {
      data = await UI.fetchJSON('/api/mail');
    } catch (err) {
      root.innerHTML = `<div class="coa-empty">${esc(err.message)}</div>`;
      return;
    }
    reset();
    render();
  }

  function reset() {
    form = toForm(data.mail);
    password = '';
    clearPassword = false;
  }

  function render() {
    if (!root || !root.isConnected) return;
    const m = data.mail;
    const can = data.canManage;
    const field = (label, key, hint, attrs) => `
      <label class="bu-field"><span>${esc(label)}${hint ? ` <em>${esc(hint)}</em>` : ''}</span>
        <input data-k="${key}" value="${esc(form[key] ?? '')}" autocomplete="off" ${attrs || ''}></label>`;
    const pw = m.password;
    const pwState = clearPassword ? 'Will be cleared when saved' : password ? 'Will be saved' : pw.set ? (pw.readable ? 'Set · ' + pw.hint : pw.hint) : 'Not set';

    root.innerHTML = `
      <div class="sf-cards" style="margin-top:28px;">
        <div class="mp-status ${m.inUse.source === 'settings' ? 'live' : 'sandbox'}">
          <div class="mp-status-head"><span class="mp-dot"></span>Email · ${m.inUse.source === 'settings' ? 'Mail server below' : 'Server from .env'}</div>
          <div class="mp-status-note">${esc(m.inUse.note)}</div>
          <div class="sf-btns"><button type="button" class="btn" data-test ${can ? '' : 'disabled'}>Send a test message</button></div>
        </div>
        ${can ? '' : '<p class="bu-intro">Only the Finance Manager can change the mail server.</p>'}
        ${m.encryption ? '' : '<div class="st-warn">This installation has no encryption key, so the mail server password cannot be stored. Set encryption.key in .env (php spark key:generate) first.</div>'}

        <div class="sf-section">Mail server</div>
        <div class="bu-grid">
          ${field('SMTP server', 'host', 'from your mail provider', 'class="mono" placeholder="smtp.office365.com" spellcheck="false"')}
          ${field('Port', 'port', '587 for STARTTLS, 465 for SSL/TLS', 'class="mono" inputmode="numeric" maxlength="5"')}
          <label class="bu-field"><span>Encryption</span>
            <select data-k="crypto">${data.options.encryptions.map(x => `<option value="${esc(x.value)}" ${x.value === form.crypto ? 'selected' : ''}>${esc(x.text)}</option>`).join('')}</select></label>
          ${field('Username', 'username', 'often the sending address', 'autocomplete="off" spellcheck="false"')}
          ${field('Messages come from', 'fromEmail', 'an address the provider has verified', 'type="email" placeholder="no-reply@elog.or.ke" spellcheck="false"')}
          ${field('Sender name', 'fromName', 'optional', 'maxlength="120" placeholder="ELOG Finance"')}
        </div>
        <div class="coa-card">
          <div class="sf-row mp-cred">
            <div>
              <div class="sf-name">Password ${pw.set && !clearPassword ? '<span class="jr-pill posted">Set</span>' : ''}</div>
              <div class="sf-sub">Held encrypted and never shown again. Enter it only to set or replace it — for Microsoft 365 or Google, an app password.</div>
              <div class="sf-sub">${esc(pwState)}</div>
            </div>
            <div class="sf-btns">
              <input type="password" data-password value="${esc(password)}" placeholder="${pw.set ? 'Enter to replace' : 'Enter the password'}" autocomplete="new-password" spellcheck="false">
              ${pw.set && can ? `<button type="button" class="btn st-small" data-clear>${clearPassword ? 'Keep' : 'Clear'}</button>` : ''}
            </div>
          </div>
        </div>

        ${can ? `<div class="bu-actions">
          <button type="button" class="btn" data-discard ${dirty() ? '' : 'hidden'}>Discard</button>
          <button type="button" class="btn btn-primary" data-save ${dirty() ? '' : 'disabled'}>${dirty() ? 'Save changes' : 'Saved'}</button>
        </div>` : ''}
      </div>`;

    if (!can) root.querySelectorAll('input, select').forEach(el => { el.disabled = true; });
    wire();
  }

  function wire() {
    root.oninput = (e) => {
      const el = e.target;
      if (el.dataset.password !== undefined) {
        password = el.value;
      } else if (el.dataset.k !== undefined) {
        form[el.dataset.k] = el.value;
      } else {
        return;
      }
      refreshActions();
    };
    root.onchange = (e) => {
      const el = e.target;
      if (el.tagName === 'SELECT' && el.dataset.k !== undefined) {
        form[el.dataset.k] = el.value;
        // 465 is SSL/TLS and 587 STARTTLS almost everywhere; follow the choice when the port is one of the two.
        if (el.value === 'ssl' && form.port === '587') form.port = '465';
        if (el.value === 'tls' && form.port === '465') form.port = '587';
        render();
      }
    };
    root.onclick = async (e) => {
      const btn = e.target.closest('button');
      if (!btn) return;
      if (btn.dataset.clear !== undefined) {
        clearPassword = !clearPassword;
        password = '';
        render();
        return;
      }
      if (btn.dataset.discard !== undefined) {
        reset();
        render();
        UI.toast('Unsaved mail server changes discarded.');
        return;
      }
      if (btn.dataset.save !== undefined) await save(btn);
      if (btn.dataset.test !== undefined) await test(btn);
    };
  }

  function refreshActions() {
    const save = root.querySelector('[data-save]');
    if (!save) return;
    const changed = dirty();
    save.disabled = !changed;
    save.textContent = changed ? 'Save changes' : 'Saved';
    root.querySelector('[data-discard]').hidden = !changed;
  }

  async function save(button) {
    button.disabled = true;
    try {
      const body = { ...form, clearPassword };
      if (password !== '') body.password = password;
      const res = await UI.postJSON('/api/mail', body);
      data = { mail: res.mail, options: res.options, canManage: res.canManage };
      reset();
      render();
      UI.toast(res.message);
    } catch (err) {
      button.disabled = false;
      UI.toast(err.message);
    }
  }

  async function test(button) {
    if (dirty()) {
      UI.toast('Save the mail server first — the test goes through what is saved.');
      return;
    }
    const was = button.textContent;
    button.disabled = true;
    button.textContent = 'Sending…';
    try {
      const res = await UI.postJSON('/api/mail/test');
      data.mail = res.mail;
      UI.toast(res.message);
    } catch (err) {
      UI.toast(err.message);
    } finally {
      button.disabled = false;
      button.textContent = was;
    }
  }

  return { mount };
})();

/**
 * Settings → Opening balances: carrying an entity's permanent balances from a
 * legacy system onto this ledger.
 *
 * The panel checks before it writes. "Check the file" answers every rule and
 * changes nothing; "Carry the balances" does the same and, if every check passed,
 * writes them as a DRAFT journal — which is then submitted and approved on the
 * Journals screen like any other entry, so the person who loads the conversion
 * cannot also approve it. Until it is approved the whole load can be thrown away.
 */
const Conversion = (() => {
  const esc = UI.esc;
  const fmt = (n) => Number(n).toLocaleString('en-US');

  let root = null;
  let data = null;
  const state = { period: '', source: '', decimal: '.', preview: null, busy: false };

  async function mount(container) {
    root = container;
    root.innerHTML = '<div class="coa-empty">Loading…</div>';
    try {
      data = await UI.fetchJSON('/api/settings/conversion');
    } catch (err) {
      root.innerHTML = `<div class="coa-empty">${esc(err.message)}</div>`;
      return;
    }
    const open = data.periods.filter(p => p.available);
    if (!state.period || !open.some(p => p.name === state.period)) state.period = (open[0] || {}).name || '';
    render();
  }

  function render() {
    if (!root || !root.isConnected) return;
    root.innerHTML = `<div class="sf-cards">${data.batch ? carried() : ''}${data.batch && data.batch.settled ? '' : loader()}${preview()}</div>`;
    bind();
  }

  // ---- What has already been carried ----

  function carried() {
    const b = data.batch;
    return `
      <div class="coa-card cv-carried">
        <div class="sf-row">
          <div>
            <div class="sf-name">${esc(b.reference || 'Loaded')} · ${esc(b.status)}</div>
            <div class="sf-sub">${esc(fmt(b.total))} ${esc(data.currency)} on ${esc(String(b.rows))} balances, brought forward from ${esc(b.source)} at the close of ${esc(b.cutOff)} · loaded from ${esc(b.file)} by ${esc(b.loadedBy)} on ${esc(b.loadedAt)}</div>
          </div>
          <div class="sf-btns">
            ${b.settled
              ? '<span class="sf-sub">Posted — correct it with a journal</span>'
              : `<button type="button" class="btn" data-cv="discard" ${data.canManage ? '' : 'disabled'}>Discard</button>`}
          </div>
        </div>
      </div>
      ${b.settled ? '' : `<div class="bu-status">${esc(b.reference)} is a draft. Submit it on the Journals screen so a second person approves it — the balances reach the ledger when it posts.</div>`}`;
  }

  // ---- Loading a trial balance ----

  function loader() {
    const open = data.periods.filter(p => p.available);
    const shut = data.periods.filter(p => !p.available);
    const chosen = open.find(p => p.name === state.period);

    if (open.length === 0) {
      return `
        <div class="bu-status bu-warn">Opening balances go onto an empty ledger. ${shut.length === 0
          ? 'No period is open to carry them into.'
          : `Every open month already has postings behind it${shut[0].postedBefore ? ` — ${esc(fmt(shut[0].postedBefore))} entries before ${esc(shut[0].name)}` : ''}. Balances are carried when the system is stood up, before anything is posted.`}</div>`;
    }

    return `
      <div class="sf-section">Load a trial balance</div>
      <p class="bu-intro">Export the trial balance from the old system as CSV. It needs a column naming the account and either debit and credit columns or one signed balance column; fund, programme, award and county are taken from the file where it has them and from the account's defaults where it does not. Figures are read as ${esc(data.currency)}.</p>
      <p class="bu-intro"><a class="cv-template" href="/api/settings/conversion/template?period=${encodeURIComponent(state.period)}" download>↓ Download the trial balance to fill in</a> — the columns this reader expects, with every postable account already in it and coded the way the chart defaults. Enter the figures against the accounts that carry a balance and delete the rest.</p>
      <div class="coa-card" style="padding:14px 16px;">
        <div class="bu-grid">
          <label class="bu-field"><span>Balances carried into</span>
            <select data-cv-k="period">${open.map(p => `<option value="${esc(p.name)}" ${p.name === state.period ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></label>
          <label class="bu-field"><span>Exported from <em>the old system</em></span>
            <input data-cv-k="source" value="${esc(state.source)}" maxlength="80" placeholder="Sage 50, QuickBooks, spreadsheets"></label>
          <label class="bu-field"><span>Figures written as</span>
            <select data-cv-k="decimal">
              <option value="." ${state.decimal === '.' ? 'selected' : ''}>1,234.50</option>
              <option value="," ${state.decimal === ',' ? 'selected' : ''}>1.234,50</option>
            </select></label>
          <label class="bu-field"><span>Trial balance <em>CSV</em></span><input type="file" id="cv-file" accept=".csv,.txt"></label>
        </div>
        ${chosen ? `<div class="bu-status" style="margin-top:12px;">Balances at the close of <strong>${esc(chosen.cutOff)}</strong> become one entry dated ${esc(chosen.starts)}.${chosen.yearStart
          ? ' A full year ended on that date, so only balance-sheet accounts carry — the year\'s result belongs in the accumulated fund.'
          : ' The year is already running, so income and expenditure carry too, as the year to date.'}</div>` : ''}
        <div class="bu-actions" style="margin-top:12px;">
          <button type="button" class="btn" data-cv="check" ${data.canManage ? '' : 'disabled'}>Check the file</button>
          <button type="button" class="btn btn-primary" data-cv="load" ${data.canManage && state.preview && state.preview.ok ? '' : 'disabled'}>Carry the balances</button>
        </div>
        ${data.canManage ? '' : `<div class="bu-intro" style="margin-top:8px;">${esc(data.role)} can look but not load. Only the Finance Manager carries opening balances.</div>`}
      </div>`;
  }

  // ---- What the file says ----

  function preview() {
    const p = state.preview;
    if (!p) return '';
    const s = p.summary;
    return `
      <div class="sf-section">${esc(p.file)}</div>
      <div class="coa-card" style="padding:14px 16px;">
        <ul class="bu-checks">${p.checks.map(c => `<li class="${c.ok ? 'ok' : 'bad'}">${c.ok ? '✓' : '!'} ${esc(c.label)}</li>`).join('')}</ul>
        <div class="bu-summary" style="margin-top:10px;">
          <span>${esc(fmt(s.read))} rows read</span><span>·</span>
          <span>${esc(fmt(s.loaded))} to carry</span>
          ${s.problems ? `<span>·</span><span class="bu-warn">${esc(fmt(s.problems))} to fix</span>` : ''}
          <span>·</span><span>debits ${esc(fmt(s.debit))}</span><span>·</span><span>credits ${esc(fmt(s.credit))}</span>
        </div>
        <div class="cv-rows">
          <div class="cv-row cv-head"><span>Line</span><span>Account</span><span>Fund · programme</span><span class="end">Debit</span><span class="end">Credit</span></div>
          ${p.rows.map(r => `
            <div class="cv-row ${r.problem ? 'bad' : ''}">
              <span class="mono">${esc(String(r.line))}</span>
              <span><span class="mono">${esc(r.account)}</span> ${esc(r.name || r.description)}${r.problem ? `<div class="cv-problem">${esc(r.problem)}</div>` : ''}</span>
              <span>${esc(r.fund)}${r.programme ? ' · ' + esc(r.programme) : ''}${r.grant ? ' · ' + esc(r.grant) : ''}</span>
              <span class="mono end">${r.dr ? esc(fmt(r.dr)) : ''}</span>
              <span class="mono end">${r.cr ? esc(fmt(r.cr)) : ''}</span>
            </div>`).join('')}
        </div>
      </div>`;
  }

  // ---- Events ----

  function bind() {
    root.querySelectorAll('[data-cv-k]').forEach(el => el.addEventListener('change', () => {
      state[el.dataset.cvK] = el.value;
      state.preview = null;
      render();
    }));
    const file = root.querySelector('#cv-file');
    if (file) file.addEventListener('change', () => { state.preview = null; render(); });
    root.querySelectorAll('[data-cv]').forEach(el => el.addEventListener('click', () => {
      if (el.dataset.cv === 'discard') return discard();
      send(el.dataset.cv === 'load');
    }));
  }

  async function send(commit) {
    const chosen = root.querySelector('#cv-file');
    const file = chosen && chosen.files[0];
    if (!file) {
      UI.toast('Choose the trial balance exported from the old system.');
      return;
    }
    if (state.busy) return;
    state.busy = true;

    const form = new FormData();
    form.append('file', file);
    form.append('period', state.period);
    form.append('source', state.source);
    form.append('decimal', state.decimal);

    try {
      const res = await fetch('/api/settings/conversion/' + (commit ? 'load' : 'preview'), {
        method: 'POST', headers: { Accept: 'application/json' }, body: form,
      });
      const body = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(body.error || `Request failed: ${res.status}`);
      data = body;
      state.preview = commit ? null : body;
      render();
      if (commit) UI.toast(body.committed.message);
    } catch (err) {
      UI.toast(err.message);
    } finally {
      state.busy = false;
    }
  }

  async function discard() {
    if (!window.confirm('Discard the conversion? The draft entry and everything it carried are deleted.')) return;
    try {
      data = await UI.postJSON('/api/settings/conversion/discard', {});
      state.preview = null;
      render();
      UI.toast(data.message);
    } catch (err) {
      UI.toast(err.message);
    }
  }

  return { mount };
})();
