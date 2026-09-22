/**
 * The installer. The server says which step the wizard is on (`step` on every
 * /api/install response) and this draws that step:
 *
 *   server       the database server and a user to reach it with
 *   database     one of the databases it lists, or a new one; reached and proved
 *   data         a new instance, or the demonstration organisation
 *   organisation the organisation, its reporting basis and its first year
 *   entities     the head office, and any other entity to open with it
 *   user         the first user
 *   review       read it all back, then write it
 *   done         what was installed, and what to do next
 *
 * Because the step lives on the server, reloading the page carries on where it was
 * rather than starting again, and a refusal that moves nothing leaves the wizard
 * where it stood.
 *
 * The last step is four calls in order — env, migrate, seed, finish — shown as a
 * checklist so a long migration looks like progress rather than a hung page. The
 * done screen is drawn from the answer to `finish` and never re-fetched: writing
 * the lock closes the installer, so by then /api/install is answered as not found.
 */
(function () {
  const root = document.getElementById('install');
  const esc = UI.esc;
  let state = null;
  /** Entities besides the head office, while that step is being filled in. */
  let branches = [];

  async function api(method, url, body) {
    const res = await fetch(url, {
      method,
      headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
      body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({}));
    if (data.step) state = data;
    if (!res.ok) throw Object.assign(new Error(data.error || `Something went wrong (${res.status}).`), { data });
    return data;
  }

  function paint(html) {
    root.innerHTML = html;
    const first = root.querySelector('input:not([type=checkbox]):not([type=radio]):not([readonly]), select');
    if (first) first.focus();
  }

  const note = (text, tone) => (text ? `<p class="auth-msg ${tone || ''}" role="${tone === 'error' ? 'alert' : 'status'}">${esc(text)}</p>` : '');

  function showError(err) {
    const el = root.querySelector('.auth-msg-slot');
    if (el) {
      el.innerHTML = note(err.message, 'error');
      el.scrollIntoView({ block: 'nearest' });
    } else {
      UI.toast(err.message);
    }
  }

  /** Disables a form's buttons while a request is out, and redraws if the step moved. */
  async function busy(form, run) {
    const before = state && state.step;
    const buttons = form.querySelectorAll('button');
    buttons.forEach((b) => { b.disabled = true; });
    try {
      await run();
    } catch (err) {
      if (state && state.step !== before) route(err.message); else showError(err);
    } finally {
      buttons.forEach((b) => { b.disabled = false; });
    }
  }

  // ---- The step indicator ----

  /** Which steps are shown, given a demonstration install skips three of them. */
  function shownSteps() {
    const all = Object.keys(state.steps).filter((s) => s !== 'done');
    if (state.answers.data === 'demo') return all.filter((s) => !['organisation', 'entities', 'user'].includes(s));
    return all;
  }

  function stepper() {
    const steps = shownSteps();
    const at = steps.indexOf(state.step);
    return `<ol class="ins-steps">${steps.map((s, i) => `
      <li class="ins-step ${i === at ? 'now' : (at > i ? 'done' : '')}">
        <span class="ins-step-n">${at > i ? '✓' : i + 1}</span>${esc(state.steps[s])}
      </li>`).join('')}</ol>`;
  }

  const head = (title, blurb) => `
    ${state.step === 'done' ? '' : stepper()}
    <h1 class="auth-title">${esc(title)}</h1>
    ${blurb ? `<p class="auth-note">${blurb}</p>` : ''}`;

  const backButton = (label) => `<button type="button" class="auth-link" data-back>← back to ${esc(label)}</button>`;

  function wireBack() {
    const button = root.querySelector('[data-back]');
    if (button) {
      button.addEventListener('click', async () => {
        button.disabled = true;
        try { await api('POST', '/api/install/back'); route(); } catch (err) { showError(err); button.disabled = false; }
      });
    }
  }

  function route(message) {
    const step = state ? state.step : 'server';
    if (!state.authorised && state.token.required) return gate(message);
    if (step === 'done') return done(message);
    if (step === 'review') return review(message);
    if (step === 'user') return userStep(message);
    if (step === 'entities') return entitiesStep(message);
    if (step === 'organisation') return organisationStep(message);
    if (step === 'data') return dataStep(message);
    if (step === 'database') return databaseStep(message);
    return serverStep(message);
  }

  // ---- The setup key ----

  function gate(message) {
    paint(`
      <h1 class="auth-title">Setup key</h1>
      <p class="auth-note">This instance has not been installed yet, and the installer writes the database credentials and
        makes the first user — so it asks for a key only someone with access to the server can read. It is in
        <code>${esc(state.token.path)}</code>.</p>
      <form class="auth-form" id="f-token" novalidate>
        <label class="auth-field"><span>Setup key</span><input name="token" autocomplete="off" spellcheck="false" required></label>
        <div class="auth-msg-slot">${note(message)}</div>
        <button type="submit" class="btn btn-primary auth-submit">Continue</button>
      </form>
      <p class="auth-note ins-dim">On the server itself the key is not asked for. You can also install from the command
        line instead: <code>php spark migrate</code>, <code>php spark db:seed BaselineSeeder</code>, <code>php spark install</code>.</p>`);
    const form = root.querySelector('#f-token');
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      busy(form, async () => {
        const res = await api('POST', '/api/install/token', { token: form.token.value });
        route(res.message);
      });
    });
  }

  // ---- Step 1: the server ----

  function preflightPanel() {
    const p = state.preflight;
    if (p.ok && !p.rows.some((r) => !r.ok)) {
      return `<details class="ins-fold"><summary>This server has everything it needs</summary>
        <ul class="ins-checks">${p.rows.map(checkRow).join('')}</ul></details>`;
    }
    return `<div class="ins-panel ${p.ok ? 'warn' : 'bad'}">
      <div class="ins-panel-head">${p.ok ? 'Worth knowing about this server' : 'This server is not ready yet'}</div>
      <ul class="ins-checks">${p.rows.filter((r) => !r.ok).map(checkRow).join('')}</ul>
      ${p.ok ? '' : '<p class="ins-dim">Put these right and reload this page. Nothing can be installed until then.</p>'}
    </div>`;
  }

  const checkRow = (r) => `<li class="${r.ok ? 'ok' : (r.blocks ? 'bad' : 'warn')}">
    <span class="ins-check-mark">${r.ok ? '✓' : (r.blocks ? '✕' : '!')}</span>
    <span><strong>${esc(r.label)}</strong> ${esc(r.note)}</span></li>`;

  function serverStep(message) {
    const o = state.options;
    const held = state.answers.server || {};
    const drivers = Object.entries(o.drivers);
    const driver = held.driver || (drivers[0] || ['MySQLi'])[0];
    const sqlite = driver === 'SQLite3';

    paint(`
      ${head('The database server', `Where the books will be kept. The installer signs in to the server, lists the
        databases this user can reach, and you choose one — or create one, where the user is allowed to.`)}
      ${preflightPanel()}
      <details class="ins-fold"><summary>What the database user needs</summary>
        <p class="ins-dim">The schema is more than tables: it has triggers that keep a posted journal and the audit log
          append-only, and two reporting views. <code>TRIGGER</code> and <code>CREATE VIEW</code> are the privileges most
          often left out. For the installer to create the database itself, the user also needs <code>CREATE</code> on
          <code>*.*</code>; otherwise have the administrator create it first:</p>
        <pre class="ins-sql">CREATE DATABASE fms CHARACTER SET ${esc(o.charset)} COLLATE ${esc(o.collation)};
CREATE USER 'fms'@'localhost' IDENTIFIED BY '…';
GRANT SELECT, INSERT, UPDATE, DELETE,
      CREATE, ALTER, DROP, INDEX, REFERENCES,
      TRIGGER, CREATE VIEW
  ON fms.* TO 'fms'@'localhost';</pre>
      </details>
      <form class="auth-form" id="f-server" novalidate>
        <label class="auth-field"><span>Kind</span>
          <select name="driver">${drivers.map(([k, label]) => `<option value="${esc(k)}" ${k === driver ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select>
        </label>
        <div class="ins-row ${sqlite ? 'ins-hidden' : ''}" data-server>
          <label class="auth-field"><span>Host</span><input name="hostname" value="${esc(held.hostname || 'localhost')}" autocomplete="off"></label>
          <label class="auth-field ins-narrow"><span>Port</span><input name="port" value="${esc(held.port || '3306')}" inputmode="numeric" autocomplete="off"></label>
        </div>
        <div class="ins-row ${sqlite ? 'ins-hidden' : ''}" data-server>
          <label class="auth-field"><span>User</span><input name="username" value="${esc(held.username || '')}" autocomplete="off" spellcheck="false"></label>
          <label class="auth-field"><span>Password</span><input name="password" type="password" autocomplete="off"></label>
        </div>
        <p class="ins-hint ${sqlite ? '' : 'ins-hidden'}" data-file>An SQLite database is a file in <code>writable/</code> on this
          server. Suited to trying the application out, or to one person; use MySQL for an organisation's books.</p>
        <label class="auth-field"><span>Table prefix <em>optional</em></span><input name="prefix" value="${esc(held.prefix || '')}" autocomplete="off" spellcheck="false"></label>
        <div class="auth-msg-slot">${note(message)}</div>
        <button type="submit" class="btn btn-primary auth-submit" ${state.preflight.ok ? '' : 'disabled'}>${sqlite ? 'List the database files' : 'Connect'}</button>
      </form>`);

    const form = root.querySelector('#f-server');
    const sync = () => {
      const isFile = form.driver.value === 'SQLite3';
      root.querySelectorAll('[data-server]').forEach((el) => el.classList.toggle('ins-hidden', isFile));
      root.querySelector('[data-file]').classList.toggle('ins-hidden', !isFile);
      form.querySelector('button[type=submit]').textContent = isFile ? 'List the database files' : 'Connect';
    };
    form.driver.addEventListener('change', sync);
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      busy(form, async () => {
        const res = await api('POST', '/api/install/server', {
          driver: form.driver.value,
          hostname: form.hostname.value,
          port: form.port.value,
          username: form.username.value,
          password: form.password.value,
          prefix: form.prefix.value,
        });
        route(res.message);
      });
    });
  }

  // ---- Step 2: the database ----

  /** The four answers the server gives about a database it was pointed at. */
  function reportPanel() {
    const r = state.answers.report;
    if (!r) return '';
    const rows = [
      ['Reached', true, `${esc(r.label)} ${esc(r.server)} · ${esc(r.database)}`],
      ['Character set', r.charset.ok, `${esc(r.charset.name)}${r.charset.collation ? ' / ' + esc(r.charset.collation) : ''} — ${esc(r.charset.note)}`],
      ['What it holds', r.schema.state !== 'installed', esc(r.schema.note)],
      ['Privileges', r.privileges.ok, esc(r.privileges.note)],
    ];
    return `<div class="ins-panel ${r.ok ? 'good' : 'bad'}">
      <div class="ins-panel-head">${r.ok ? 'This database will do' : 'This database cannot be used as it stands'}</div>
      <ul class="ins-checks">${rows.map(([label, ok, text]) => `<li class="${ok ? 'ok' : 'bad'}">
        <span class="ins-check-mark">${ok ? '✓' : '✕'}</span><span><strong>${esc(label)}</strong> ${text}</span></li>`).join('')}</ul>
    </div>`;
  }

  const STATE_CHIP = { empty: 'Empty', migrated: 'Part installed', other: 'Other tables', installed: 'In use', unreadable: 'Unreadable' };

  function databaseStep(message) {
    const server = state.answers.server || {};
    const sqlite = server.driver === 'SQLite3';
    const held = state.answers.databases || { list: [], canCreate: false };
    const list = held.list || [];
    const chosen = (state.answers.database || {}).database || '';
    // What was chosen before; else creating a new one, where the user may. Never
    // someone else's empty database on a shared server — that has to be picked.
    const preferred = list.some((d) => d.value === chosen && d.usable) ? chosen : (held.canCreate ? '__new' : '');
    const where = `${server.username || '(no user)'}@${server.hostname || 'localhost'}:${server.port || '3306'}`;

    const rows = list.map((d) => `
      <label class="ins-db ${d.usable ? '' : 'off'}">
        <input type="radio" name="pick" value="${esc(d.value)}" ${d.usable ? '' : 'disabled'} ${d.value === preferred ? 'checked' : ''}>
        <span class="ins-db-body">
          <span class="ins-db-name">${esc(d.name)}
            <span class="st-chip ${d.state === 'empty' ? '' : 'muted'}">${esc(STATE_CHIP[d.state] || d.state)}</span>
            ${d.charsetOk ? '' : `<span class="st-chip muted">${esc(d.charset)}</span>`}</span>
          <span class="ins-hint">${esc(d.note)}</span>
        </span>
      </label>`).join('');

    paint(`
      ${head('The database', `${sqlite ? 'The database files in writable/ on this server.' : `The databases ${esc(where)} can reach.`}
        A new organisation wants an empty one; one that already holds an organisation cannot be chosen.`)}
      ${reportPanel()}
      <form class="auth-form" id="f-db" novalidate>
        <div class="ins-dbs" role="radiogroup" aria-label="Databases">
          ${rows ? `<div class="ins-db-list">${rows}</div>` : (message ? '' : `<p class="ins-hint">${sqlite ? 'There are no database files in writable/ yet.' : 'This user cannot see any databases.'}</p>`)}
          ${held.canCreate ? `
            <label class="ins-db">
              <input type="radio" name="pick" value="__new" ${preferred === '__new' ? 'checked' : ''}>
              <span class="ins-db-body">
                <span class="ins-db-name">Create a new ${sqlite ? 'database file' : 'database'}</span>
                <span class="ins-hint">${sqlite ? 'Made in writable/ as <em>name</em>.sqlite.' : `Created empty, as ${esc(state.options.charset)} / ${esc(state.options.collation)}.`}</span>
                <input name="newName" class="ins-db-new" placeholder="${sqlite ? 'books' : 'fms'}" autocomplete="off" spellcheck="false" maxlength="64">
              </span>
            </label>` : ''}
          <label class="ins-db">
            <input type="radio" name="pick" value="__typed" ${chosen !== '' && preferred === '' ? 'checked' : ''}>
            <span class="ins-db-body">
              <span class="ins-db-name">Another ${sqlite ? 'file' : 'database'}, by ${sqlite ? 'path' : 'name'}</span>
              <span class="ins-hint">${sqlite ? 'A database file anywhere this server can write.' : 'For a database this user can reach but not list.'}</span>
              <input name="typed" class="ins-db-new" value="${esc(list.some((d) => d.value === chosen) ? '' : chosen)}"
                placeholder="${sqlite ? '/var/lib/fms/books.sqlite' : 'fms'}" autocomplete="off" spellcheck="false">
            </span>
          </label>
        </div>
        ${held.canCreate || sqlite ? '' : `<p class="ins-hint">This user may not create databases. To have the installer create one,
          grant it <code>CREATE</code> on <code>*.*</code>; or have the administrator create it and connect again.</p>`}
        <div class="auth-msg-slot">${note(message)}</div>
        <button type="submit" class="btn btn-primary auth-submit">Check this database</button>
      </form>
      ${backButton('the server')}`);

    const form = root.querySelector('#f-db');
    // Typing a name picks the row it belongs to.
    [['newName', '__new'], ['typed', '__typed']].forEach(([field, value]) => {
      const input = form[field];
      if (!input) return;
      input.addEventListener('focus', () => {
        const radio = form.querySelector(`input[name=pick][value="${value}"]`);
        if (radio) radio.checked = true;
      });
    });
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const picked = (form.querySelector('input[name=pick]:checked') || {}).value;
      if (!picked) { showError(new Error('Choose a database.')); return; }
      const body = picked === '__new' ? { create: true, database: form.newName.value }
        : { database: picked === '__typed' ? form.typed.value : picked };
      busy(form, async () => {
        const res = await api('POST', '/api/install/database', body);
        route(res.message);
      });
    });
    wireBack();
  }

  // ---- Step 3: what to put in it ----

  function dataStep(message) {
    const r = state.answers.report;
    paint(`
      ${head('What to put in it', 'Two ways to start. The first is how a real instance begins.')}
      ${r ? `<p class="auth-note ins-dim">${esc(r.label)} · ${esc(r.database)} · ${esc(r.schema.note)}</p>` : ''}
      <div class="mfa-choices">
        <button type="button" class="mfa-choice" data-choice="new">
          <span class="mfa-choice-title">A new organisation <em>recommended</em></span>
          <span class="mfa-choice-sub">The schema and the reference data — roles, permissions, currencies, the coding
            segments, the payroll rates. Then you name the organisation, open its entities and make the first user.
            No chart of accounts, no funds, no balances: those are yours to load.</span>
        </button>
        <button type="button" class="mfa-choice" data-choice="demo">
          <span class="mfa-choice-title">A copy of the demonstration organisation</span>
          <span class="mfa-choice-sub">A whole worked organisation with five entities, a full ledger, budgets, payroll and
            last year's comparatives, for training and for looking at how the application behaves. <strong>Its passwords
            are published</strong> in docs/setup.md, so it is never for real books.</span>
        </button>
      </div>
      ${state.options.production ? `
        <label class="ins-tick">
          <input type="checkbox" name="confirmDemo">
          <span><strong>This server runs in production.</strong>
            <span class="ins-hint">Tick to confirm the demonstration organisation is wanted here anyway, knowing anyone who
              has read docs/setup.md can sign in to it.</span></span>
        </label>` : ''}
      <div class="auth-msg-slot">${note(message)}</div>
      ${backButton('the database')}`);
    root.querySelectorAll('.mfa-choice').forEach((b) => b.addEventListener('click', async () => {
      root.querySelectorAll('.mfa-choice').forEach((x) => { x.disabled = true; });
      try {
        const confirm = root.querySelector('[name=confirmDemo]');
        const res = await api('POST', '/api/install/data', { data: b.dataset.choice, confirm: !!(confirm && confirm.checked) });
        route(res.message);
      } catch (err) {
        showError(err);
        root.querySelectorAll('.mfa-choice').forEach((x) => { x.disabled = false; });
      }
    }));
    wireBack();
  }

  // ---- Step 4: the organisation ----

  const options = (list, current, value, label) => list.map((o) => {
    const v = value ? value(o) : o;
    return `<option value="${esc(v)}" ${v === current ? 'selected' : ''}>${esc(label ? label(o) : o)}</option>`;
  }).join('');

  function organisationStep(message) {
    const o = state.options;
    const held = state.answers.organisation || {};
    const n = (key) => esc((o.notes[key] || {}).note || '');

    paint(`
      ${head('The organisation', 'As it is registered, and how it keeps its books. All of this can be changed afterwards in Settings, except where it says otherwise.')}
      <form class="auth-form" id="f-org" novalidate>
        <label class="auth-field"><span>Registered name</span>
          <input name="registeredName" value="${esc(held.registeredName || '')}" required maxlength="160">
          <span class="ins-hint">${n('registeredName')}</span>
        </label>
        <label class="auth-field"><span>Short name</span>
          <input name="shortName" value="${esc(held.shortName || '')}" required maxlength="40">
          <span class="ins-hint">${n('shortName')}. It becomes what the application calls itself.</span>
        </label>
        <div class="ins-row">
          <label class="auth-field"><span>Tax PIN <em>optional</em></span><input name="taxPin" value="${esc(held.taxPin || '')}" autocomplete="off"></label>
          <label class="auth-field"><span>Registration no. <em>optional</em></span><input name="registrationNo" value="${esc(held.registrationNo || '')}" autocomplete="off"></label>
        </div>
        <div class="ins-row">
          <label class="auth-field"><span>Functional currency</span>
            <select name="currency">${options(o.currencies, held.currency || 'KES', (c) => c.code, (c) => `${c.code} — ${c.name}`)}</select>
            <span class="ins-hint">The currency the books are kept in.</span>
          </label>
          <label class="auth-field"><span>Framework</span>
            <select name="framework">${options(o.frameworks, held.framework || o.frameworks[0])}</select>
          </label>
        </div>
        <div class="ins-row">
          <label class="auth-field"><span>Year end</span>
            <select name="yearEnd">${options(o.yearEnds, held.yearEnd || o.yearEnds[0])}</select>
          </label>
          <label class="auth-field"><span>Account code length</span>
            <select name="codeLength">${options(o.codeLengths, held.codeLength || o.codeLengths[0])}</select>
          </label>
          <label class="auth-field ins-narrow"><span>First year</span>
            <input name="firstYear" value="${esc(held.firstYear || String(new Date().getFullYear()))}" inputmode="numeric" maxlength="4">
          </label>
        </div>
        <p class="ins-hint" id="org-year"></p>
        <div class="auth-msg-slot">${note(message)}</div>
        <button type="submit" class="btn btn-primary auth-submit">Continue</button>
      </form>
      ${backButton('what to put in it')}`);

    const form = root.querySelector('#f-org');
    // The year the ledger opens with, worked out as it is typed — the same arithmetic
    // the installer uses, so what is shown is what will be created.
    const starts = { '31 December': 1, '30 June': 7, '30 September': 10 };
    const showYear = () => {
      const year = parseInt(form.firstYear.value, 10);
      const el = root.querySelector('#org-year');
      if (!year || String(year).length !== 4) { el.textContent = ''; return; }
      const month = starts[form.yearEnd.value] || 1;
      const endMonth = month === 1 ? 12 : month - 1;
      const from = new Date(Date.UTC(endMonth === 12 ? year : year - 1, month - 1, 1));
      const to = new Date(Date.UTC(from.getUTCFullYear(), from.getUTCMonth() + 12, 0));
      const fmt = (d) => d.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
      el.textContent = `FY${year} · ${fmt(from)} to ${fmt(to)} — twelve months, all open.`;
    };
    form.yearEnd.addEventListener('change', showYear);
    form.firstYear.addEventListener('input', showYear);
    showYear();

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      busy(form, async () => {
        const res = await api('POST', '/api/install/organisation', {
          registeredName: form.registeredName.value, shortName: form.shortName.value,
          taxPin: form.taxPin.value, registrationNo: form.registrationNo.value,
          currency: form.currency.value, framework: form.framework.value,
          yearEnd: form.yearEnd.value, codeLength: form.codeLength.value, firstYear: form.firstYear.value,
        });
        route(res.message);
      });
    });
    wireBack();
  }

  // ---- Step 5: the entities ----

  function suggestCode(shortName) {
    const letters = String(shortName || '').toUpperCase().replace(/[^A-Z0-9]+/g, '');
    return letters ? `${letters.slice(0, 8)}-HQ` : '';
  }

  function entitiesStep(message) {
    const o = state.options;
    const org = state.answers.organisation || {};
    const held = state.answers.head || {};
    branches = (state.answers.branches || []).map((b) => ({ ...b }));

    paint(`
      ${head('Its entities', `Every organisation has at least one reporting entity — the head office the accounts
        consolidate into — and it is created now. Add the branches or related trusts that keep their own books, or add
        them later in Settings → Organisation.`)}
      <form class="auth-form" id="f-ent" novalidate>
        <div class="ins-entity head">
          <div class="ins-entity-head"><span class="st-chip">Head office</span><span class="ins-dim">Required · its code is fixed once saved</span></div>
          <div class="ins-row">
            <label class="auth-field ins-narrow"><span>Code</span>
              <input name="entityCode" value="${esc(held.entityCode || suggestCode(org.shortName))}" required maxlength="20" spellcheck="false">
            </label>
            <label class="auth-field"><span>Name</span>
              <input name="entityName" value="${esc(held.entityName || (org.registeredName ? org.registeredName + ' — Head Office' : ''))}" required maxlength="120">
            </label>
          </div>
          <span class="ins-hint">2 to 20 letters, digits and hyphens. User access and imported files refer to it by this
            code. It keeps its books in ${esc(org.currency || 'KES')}.</span>
        </div>
        <div id="ins-branches"></div>
        <button type="button" class="ins-add" id="ins-add">+ Add another entity</button>
        <div class="auth-msg-slot">${note(message)}</div>
        <button type="submit" class="btn btn-primary auth-submit">Continue</button>
      </form>
      ${backButton('the organisation')}`);

    const box = root.querySelector('#ins-branches');
    const draw = () => {
      box.innerHTML = branches.map((b, i) => `
        <div class="ins-entity" data-i="${i}">
          <div class="ins-entity-head">
            <span class="st-chip muted">Entity ${i + 2}</span>
            <button type="button" class="ins-remove" data-remove="${i}" aria-label="Remove entity ${i + 2}">Remove</button>
          </div>
          <div class="ins-row">
            <label class="auth-field ins-narrow"><span>Code</span><input data-f="code" value="${esc(b.code || '')}" maxlength="20" spellcheck="false"></label>
            <label class="auth-field"><span>Name</span><input data-f="name" value="${esc(b.name || '')}" maxlength="120"></label>
          </div>
          <div class="ins-row">
            <label class="auth-field"><span>Kind</span>
              <select data-f="type">${options(state.options.entityTypes, b.type || state.options.entityTypes[0])}</select>
            </label>
            <label class="auth-field"><span>Currency</span>
              <select data-f="currency">${options(state.options.currencies, b.currency || (state.answers.organisation || {}).currency || 'KES', (c) => c.code, (c) => c.code)}</select>
            </label>
          </div>
        </div>`).join('');
    };
    draw();

    box.addEventListener('input', (e) => {
      const row = e.target.closest('[data-i]');
      if (row && e.target.dataset.f) branches[+row.dataset.i][e.target.dataset.f] = e.target.value;
    });
    box.addEventListener('change', (e) => {
      const row = e.target.closest('[data-i]');
      if (row && e.target.dataset.f) branches[+row.dataset.i][e.target.dataset.f] = e.target.value;
    });
    box.addEventListener('click', (e) => {
      const button = e.target.closest('[data-remove]');
      if (!button) return;
      branches.splice(+button.dataset.remove, 1);
      draw();
    });
    root.querySelector('#ins-add').addEventListener('click', () => {
      branches.push({ code: '', name: '', type: o.entityTypes[0], currency: (org.currency || 'KES') });
      draw();
      const last = box.querySelector('[data-i]:last-child input');
      if (last) last.focus();
    });

    const form = root.querySelector('#f-ent');
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      busy(form, async () => {
        const res = await api('POST', '/api/install/entities', {
          entityCode: form.entityCode.value,
          entityName: form.entityName.value,
          entities: branches.filter((b) => (b.code || '').trim() !== '' || (b.name || '').trim() !== ''),
        });
        route(res.message);
      });
    });
    wireBack();
  }

  // ---- Step 6: the first user ----

  function userStep(message) {
    const o = state.options;
    const held = state.answers.user || {};
    const count = (state.answers.branches || []).length + 1;

    paint(`
      ${head('The first user', 'You. There has to be someone who can change the settings, load the chart of accounts and invite everybody else.')}
      <form class="auth-form" id="f-user" novalidate>
        <label class="auth-field"><span>Full name</span>
          <input name="userName" value="${esc(held.userName || '')}" required maxlength="120" autocomplete="name">
          <span class="ins-hint">First name and surname. The ledger shows who prepared and who approved every entry, so it needs both.</span>
        </label>
        <label class="auth-field"><span>Email</span>
          <input name="userEmail" type="email" value="${esc(held.userEmail || '')}" required maxlength="190" autocomplete="username">
          <span class="ins-hint">What you sign in with.</span>
        </label>
        <label class="auth-field"><span>Password</span>
          <input name="userPassword" type="password" autocomplete="new-password" minlength="${o.minPasswordLength}">
          <span class="ins-hint">A few unrelated words make a password that is long and easy to remember. Leave it empty
            and the installer shows a one-time link to choose it instead.</span>
        </label>
        ${UI.passwordRules(o.passwordRules)}
        <div class="ins-panel">
          <div class="ins-panel-head">What you will hold</div>
          <p><strong>Every permission, at ${count === 1 ? 'the head office' : `all ${count} entities`}</strong> — the
            ${esc(o.adminRole)} role, all ${o.permissionCount} permissions${count > 1 ? ', and the consolidated view' : ''}.
            Entities opened later are yours too. You approve as the ${esc(o.firstRole)}, the role the approval rules name.</p>
          <p class="ins-dim">You will not be able to approve your own entries: the person who prepares an entry can never be
            the person who approves it, whatever permissions they hold. Inviting a second person is the last thing the
            installer will ask you to do.</p>
        </div>
        <div class="auth-msg-slot">${note(message)}</div>
        <button type="submit" class="btn btn-primary auth-submit">Continue</button>
      </form>
      ${backButton('its entities')}`);

    const form = root.querySelector('#f-user');
    const who = () => ({ email: form.userEmail.value, name: form.userName.value });
    const tick = () => UI.tickPasswordRules(form.querySelector('.pw-rules'), o.passwordRules, form.userPassword.value, who());
    form.userPassword.addEventListener('input', tick);
    form.userName.addEventListener('input', tick);
    form.userEmail.addEventListener('input', tick);
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      busy(form, async () => {
        const res = await api('POST', '/api/install/user', {
          userName: form.userName.value,
          userEmail: form.userEmail.value,
          userPassword: form.userPassword.value,
        });
        route(res.message);
      });
    });
    wireBack();
  }

  // ---- Step 7: read it back, then write it ----

  const line = (label, value) => `<div class="ins-read"><span>${esc(label)}</span><span>${esc(value)}</span></div>`;

  function review(message) {
    const a = state.answers;
    const demo = a.data === 'demo';
    const db = a.database || {};
    const org = a.organisation || {};
    const head1 = a.head || {};
    const user = a.user || {};

    const rows = [
      line('Database', `${(state.options.drivers[db.driver] || db.driver || '')} · ${db.database || ''}${db.prefix ? ` · prefix ${db.prefix}` : ''}`),
      line('Contents', demo ? 'A copy of the demonstration organisation' : 'A new organisation'),
    ];
    if (!demo) {
      rows.push(
        line('Organisation', `${org.registeredName || ''}${org.taxPin ? ` · PIN ${org.taxPin}` : ''}`),
        line('Books', `${org.currency} · ${org.framework} · year end ${org.yearEnd} · ${org.codeLength} account codes`),
        line('First year', `FY${org.firstYear} · twelve months, all open`),
        line('Head office', `${head1.entityCode || ''} · ${head1.entityName || ''}`),
      );
      (a.branches || []).forEach((b, i) => rows.push(line(i === 0 ? 'Also opened' : '', `${b.code} · ${b.name} · ${String(b.type || '').toLowerCase()} · ${b.currency}`)));
      rows.push(
        line('First user', `${user.userName || ''} <${user.userEmail || ''}>`),
        line('Holding', `${state.options.adminRole} and ${state.options.firstRole} — every permission, at every entity`),
      );
    }

    paint(`
      ${head('Check and install', demo
        ? 'The demonstration organisation brings its own entities, users and ledger. Nothing else was asked.'
        : 'Nothing has been written yet. The organisation, its entities, its first year and its first user are written in one transaction: if any of it is refused, none of it is kept.')}
      <div class="ins-readback">${rows.join('')}</div>
      <ol class="ins-run" id="ins-run">
        <li data-run="env"><span class="ins-run-mark">·</span>Write the configuration</li>
        <li data-run="migrate"><span class="ins-run-mark">·</span>Build the schema</li>
        <li data-run="seed"><span class="ins-run-mark">·</span>${demo ? 'Load the demonstration organisation' : 'Seed the reference data'}</li>
        <li data-run="finish"><span class="ins-run-mark">·</span>${demo ? 'Close the installer' : 'Write the organisation and the first user'}</li>
      </ol>
      <form class="auth-form" id="f-run" novalidate>
        <div class="auth-msg-slot">${note(message)}</div>
        <button type="submit" class="btn btn-primary auth-submit">Install</button>
      </form>
      ${backButton(demo ? 'what to put in it' : 'the first user')}`);

    const form = root.querySelector('#f-run');
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      run(form);
    });
    wireBack();
  }

  /**
   * The four writing calls, in order, each shown as it goes. A step that fails stops
   * the rest: the ones after it would only fail too, and the message says what state
   * the database was left in.
   */
  async function run(form) {
    const steps = ['env', 'migrate', 'seed', 'finish'];
    const marks = {};
    root.querySelectorAll('[data-run]').forEach((li) => { marks[li.dataset.run] = li; });
    form.querySelectorAll('button').forEach((b) => { b.disabled = true; });
    const back = root.querySelector('[data-back]');
    if (back) back.disabled = true;

    for (const step of steps) {
      const li = marks[step];
      li.classList.add('doing');
      li.querySelector('.ins-run-mark').textContent = '…';
      try {
        const res = await api('POST', `/api/install/${step}`);
        li.classList.remove('doing');
        li.classList.add('ok');
        li.querySelector('.ins-run-mark').textContent = '✓';
        if (res.message) li.insertAdjacentHTML('beforeend', ` <span class="ins-dim">${esc(res.message)}</span>`);
      } catch (err) {
        li.classList.remove('doing');
        li.classList.add('bad');
        li.querySelector('.ins-run-mark').textContent = '✕';
        showError(err);
        form.querySelectorAll('button').forEach((b) => { b.disabled = false; });
        if (back) back.disabled = false;
        return;
      }
    }

    // Installed. The lock is written, so /api/install is closed from here on: the
    // done screen is drawn from what finish returned and nothing is fetched again.
    route();
  }

  // ---- Done ----

  function done() {
    const d = state.done || {};
    paint(`
      <h1 class="auth-title">Installed</h1>
      <p class="auth-note">${esc(d.organisation || '')} is ready.</p>
      <div class="ins-readback">
        ${(d.entities || []).map((e, i) => line(i === 0 ? 'Entities' : '', e)).join('')}
        ${d.year ? line('Financial year', `${d.year} · ${d.periods} months, all open`) : ''}
        ${d.user ? line('First user', d.user) : ''}
        ${d.role ? line('Holding', d.role) : ''}
      </div>
      ${d.link ? `
        <div class="ins-panel good">
          <div class="ins-panel-head">Choose your password</div>
          <p><a class="ins-link" href="${esc(d.link)}">${esc(d.link)}</a></p>
          <p class="ins-dim">${esc(d.linkNote || '')}</p>
        </div>` : ''}
      ${d.note ? `<p class="auth-note">${esc(d.note)}</p>` : ''}
      <div class="ins-panel">
        <div class="ins-panel-head">What to do next</div>
        <ol class="ins-next">${(d.next || []).map((s) => `<li>${esc(s)}</li>`).join('')}</ol>
      </div>
      <a class="btn btn-primary auth-submit ins-signin" href="${esc(d.signIn || '/login')}">Sign in</a>
      <p class="auth-note ins-dim">The installer has closed. To offer it again — for an instance whose database has been
        dropped — delete <code>writable/installed.lock</code> on the server.</p>`);
  }

  // ---- Start ----

  (async () => {
    try {
      await api('GET', '/api/install');
    } catch (err) {
      paint(`<h1 class="auth-title">Install</h1>${note(err.message, 'error')}`);
      return;
    }
    route();
  })();
})();
