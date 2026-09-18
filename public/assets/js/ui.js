/** Small render helpers shared by every page script. No framework — just fetch + DOM. */
const UI = (() => {
  const fmtMoney = (n) => {
    if (n === null || n === undefined) return '—';
    if (typeof n === 'string') return n;
    if (n === 0) return '—';
    const s = Math.abs(Math.round(n)).toLocaleString('en-US');
    return n < 0 ? `(${s})` : s;
  };

  /**
   * What this installation calls itself, for a file it names on the way out. The
   * shell puts it on the document; a file the server names carries it already.
   */
  const brand = () => document.documentElement.dataset.brand || 'Finance';

  /** Saves a blob, named by the server's Content-Disposition where it gave one. */
  function download(blob, fallback, disposition) {
    const named = /filename="?([^";]+)"?/.exec(disposition || '');
    const url = URL.createObjectURL(blob);
    const a = Object.assign(document.createElement('a'), { href: url, download: named ? named[1] : fallback });
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  async function fetchJSON(url) {
    const res = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!res.ok) {
      // A refusal explains itself; only fall back to the status code when it does not.
      const body = await res.json().catch(() => ({}));
      throw new Error(body.error || `Request failed: ${res.status}`);
    }
    return res.json();
  }

  function toast(msg) {
    const el = document.getElementById('toast');
    if (!el) return;
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => el.classList.remove('show'), 2400);
  }

  function statGrid(stats) {
    const div = document.createElement('div');
    div.className = 'stat-grid';
    div.innerHTML = stats.map(s => `
      <div class="stat">
        <div class="stat-label">${esc(s.label)}</div>
        <div class="stat-value">${esc(s.value)}</div>
        <div class="stat-note">${esc(s.note || '')}</div>
      </div>`).join('');
    return div;
  }

  function tabs(items, activeLabel, onClick) {
    const div = document.createElement('div');
    div.className = 'tabs';
    div.innerHTML = items.map(t => {
      const label = typeof t === 'string' ? t : t.label;
      const count = typeof t === 'object' && t.count !== undefined ? ` (${t.count})` : '';
      const active = label === activeLabel ? 'active' : '';
      return `<button type="button" class="tab ${active}" data-tab="${esc(label)}">${esc(label)}${count}</button>`;
    }).join('');
    div.querySelectorAll('.tab').forEach(btn => btn.addEventListener('click', () => onClick(btn.dataset.tab)));
    return div;
  }

  function table(columns, rows, onRowClick) {
    const wrap = document.createElement('table');
    wrap.className = 'data';
    const thead = `<thead><tr>${columns.map(c => `<th class="${c.num ? 'num' : ''}">${esc(c.label)}</th>`).join('')}</tr></thead>`;
    const tbody = rows.length
      ? `<tbody>${rows.map((r, i) => `<tr data-i="${i}">${columns.map(c => `<td class="${c.num ? 'num' : ''}">${c.render ? c.render(r) : esc(r[c.key] ?? '')}</td>`).join('')}</tr>`).join('')}</tbody>`
      : '';
    wrap.innerHTML = thead + tbody;
    if (onRowClick) {
      wrap.querySelectorAll('tbody tr').forEach(tr => tr.addEventListener('click', () => onRowClick(rows[+tr.dataset.i])));
    }
    return wrap;
  }

  function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  }

  function badge(text, tone) {
    return `<span class="badge ${tone || 'plain'}">${esc(text)}</span>`;
  }

  function pageHead(container, { kicker, title, blurb, actions }) {
    const div = document.createElement('div');
    div.className = 'page-head';
    div.innerHTML = `
      <div>
        <div class="page-kicker">${esc(kicker || '')}</div>
        <h1 class="page-title">${esc(title)}</h1>
        <p class="page-blurb">${esc(blurb || '')}</p>
      </div>
      <div class="page-actions">${actions || ''}</div>`;
    container.appendChild(div);
  }

  // ---- Journal editor, shared by the Journals, General ledger and dashboard pages ----
  //
  // The v5 journal editor, for a new entry or one already in the register. Everything
  // it offers — periods, document series, linkable records, and the funds and
  // programmes each award may carry — comes from /api/journals/form; the API applies
  // the same rules again when the entry is saved, approved or reversed.

  let journalDrawer;
  let jd = null; // { opts, form, journal, ed }

  const JD_STATUS_CLASS = { Draft: 'draft', 'Pending approval': 'pending', Posted: 'posted', Reversed: 'reversed', Rejected: 'draft' };

  /** The status pill the register and the editor share. */
  const statusPill = (status) => `<span class="jr-pill ${JD_STATUS_CLASS[status] || 'draft'}">${esc(status)}</span>`;

  const jdDate = (iso) => iso ? new Date(iso + 'T00:00:00').toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '';
  const jdPeriodOf = (iso) => (jd.form.periods.find(p => p.min <= iso && iso <= p.max) || {}).name || '';
  const jdGrant = (ref) => jd.form.grants.find(g => g.ref === ref) || null;
  const jdUnique = (list) => list.filter((v, i, a) => v && a.indexOf(v) === i);
  const jdOptions = (list, current, label) => list.map(o => {
    const value = typeof o === 'string' ? o : o.value;
    return `<option value="${esc(value)}" ${value === current ? 'selected' : ''}>${esc(label ? label(o) : (typeof o === 'string' ? o : o.label))}</option>`;
  }).join('');

  function jdLine(l) {
    return { code: '', desc: '', grant: '', grantLabel: '', fund: 'General Fund', program: 'Shared services', dr: '', cr: '', ...l };
  }

  /** A line in a fund that awards are held in, with no award named. */
  const jdGap = (l) => !l.grant && jd.form.awardFunds.includes(l.fund);

  const jdStatus = () => jd.journal ? jd.journal.status : 'Draft';
  /** Posted is immutable: a posted or reversed entry is corrected by reversal, never edited. */
  const jdLocked = () => ['Posted', 'Reversed'].includes(jdStatus());
  const jdCan = () => {
    const { form, journal } = jd;
    const status = jdStatus();
    const mine = !journal || journal.preparer === form.preparer;
    return {
      save: !jdLocked() && form.canPrepare,
      submit: status === 'Draft' && form.canPrepare,
      post: status === 'Pending approval' && form.canApprove && !mine,
      discard: !!journal && status === 'Draft' && form.canPrepare,
      reverse: status === 'Posted' && !journal.opening,
      sodBlocked: status === 'Pending approval' && (!form.canApprove || mine),
      mine,
    };
  };

  function buildJournalDrawer() {
    journalDrawer = document.createElement('div');
    journalDrawer.className = 'jd';
    journalDrawer.hidden = true;
    journalDrawer.innerHTML = `
      <div class="jd-backdrop" data-close></div>
      <div class="jd-panel" role="dialog" aria-modal="true" aria-label="Journal">
        <div class="jd-head">
          <div style="display:flex;flex-direction:column;gap:4px;min-width:0;flex:1;">
            <div style="display:flex;align-items:center;gap:9px;">
              <span class="jd-caps" id="jd-kicker"></span>
              <span id="jd-status"></span>
            </div>
            <span class="jd-caps">Narration</span>
            <input id="jd-narration" class="jd-narration" placeholder="What this entry records — e.g. Reclassification of printing costs to Civic Education">
          </div>
          <button type="button" class="jd-x" data-close aria-label="Close">✕</button>
        </div>
        <div class="jd-meta" id="jd-meta"></div>
        <div class="jd-body">
          <div style="overflow-x:auto;"><div style="min-width:1154px;">
            <div class="jd-grid jd-grid-head jd-caps">
              <div>Account</div><div>Line description</div><div>Grant / award</div><div>Fund</div><div>Programme</div>
              <div style="text-align:end;">Debit</div><div style="text-align:end;">Credit</div><div></div>
            </div>
            <div id="jd-lines"></div>
            <div style="padding:9px 12px;border-bottom:1px solid #F0EEE9;" id="jd-add-line-row"><button type="button" class="jd-dashed" id="jd-add-line">+ Add line</button></div>
            <div class="jd-grid jd-totals">
              <div></div><div style="padding:0 12px;font-size:12px;font-weight:600;">Totals</div><div></div><div></div><div></div>
              <div class="jd-total" id="jd-total-dr"></div><div class="jd-total" id="jd-total-cr"></div><div></div>
            </div>
          </div></div>
            <div class="jd-foot">
              <div id="jd-summary" style="display:flex;flex-direction:column;gap:12px;"></div>
              <label class="jd-memo">Approval memo
                <textarea id="jd-memo" rows="2" placeholder="Context for the approver — grant condition, audit reference, correction being made"></textarea>
              </label>
              <div style="display:flex;flex-direction:column;gap:7px;">
                <div class="jd-caps">Supporting evidence</div>
                <div id="jd-files" style="display:flex;flex-direction:column;gap:7px;"></div>
                <label class="jd-dashed" id="jd-attach-button" style="align-self:flex-start;"><span id="jd-attach-label"></span>
                  <input type="file" id="jd-attach" multiple hidden>
                </label>
                <span class="jd-note" id="jd-files-note" style="font-size:11px;">The reference points at the record; the audit file wants the document itself — invoice, board minute or funder letter.</span>
              </div>
              <div id="jd-trail" style="display:flex;flex-direction:column;gap:7px;"></div>
            </div>
        </div>
        <div class="jd-actions" id="jd-actions"></div>
      </div>`;
    document.body.appendChild(journalDrawer);

    const $ = (id) => journalDrawer.querySelector('#' + id);

    journalDrawer.addEventListener('click', (e) => { if (e.target.closest('[data-close]')) closeJournalDrawer(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !journalDrawer.hidden) closeJournalDrawer(); });
    // Any edit marks the entry changed, so approval never posts something other than what was saved.
    journalDrawer.querySelector('.jd-panel').addEventListener('input', (e) => { if (jd && !e.target.closest('#jd-actions')) jd.dirty = true; });
    journalDrawer.querySelector('.jd-panel').addEventListener('change', (e) => { if (jd && !e.target.closest('#jd-actions')) jd.dirty = true; });

    $('jd-narration').addEventListener('input', (e) => { jd.ed.narration = e.target.value; });
    $('jd-memo').addEventListener('input', (e) => { jd.ed.memo = e.target.value; });

    // ---- Header: period, posting date, type, source document ----
    $('jd-meta').addEventListener('change', (e) => {
      const ed = jd.ed;
      const form = jd.form;
      if (e.target.id === 'jd-period') {
        const period = form.periods.find(p => p.name === e.target.value);
        if (period.closed && !form.allowClosed) {
          toast(`${period.name} is closed to further posting.`);
        } else {
          // Keep the day of the month, within the new period.
          const day = ed.date.slice(8);
          ed.period = period.name;
          ed.date = period.min.slice(0, 8) + (day <= period.max.slice(8) ? day : period.max.slice(8));
        }
      } else if (e.target.id === 'jd-date') {
        const iso = e.target.value;
        const period = jdPeriodOf(iso);
        if (period && period !== ed.period) {
          toast(`The posting date must fall within ${ed.period}. Change the period first if the entry belongs in ${period}.`);
        } else if (iso) {
          ed.date = iso;
        }
      } else if (e.target.id === 'jd-type') {
        ed.type = e.target.value;
      } else if (e.target.id === 'jd-doc') {
        ed.docLink = e.target.value;
      }
      renderJournalMeta();
    });

    // ---- Lines ----
    const linesBox = $('jd-lines');
    linesBox.addEventListener('input', (e) => {
      const row = e.target.closest('.jd-row');
      if (!row) return;
      const l = jd.ed.lines[+row.dataset.i];
      if (e.target.name === 'desc') l.desc = e.target.value;
      if (e.target.name === 'dr' || e.target.name === 'cr') {
        const other = e.target.name === 'dr' ? 'cr' : 'dr';
        e.target.value = e.target.value.replace(/[^0-9.]/g, '');
        l[e.target.name] = e.target.value;
        // A line is a debit or a credit, never both.
        if (e.target.value) { l[other] = ''; row.querySelector(`[name=${other}]`).value = ''; }
        renderJournalSummary();
      }
    });
    linesBox.addEventListener('change', (e) => {
      const row = e.target.closest('.jd-row');
      if (!row) return;
      const i = +row.dataset.i;
      const l = jd.ed.lines[i];
      const value = e.target.value;
      if (e.target.name === 'code') l.code = value;
      if (e.target.name === 'fund') l.fund = value;
      if (e.target.name === 'grant') {
        // The award decides which funds and programmes the line may carry.
        const g = jdGrant(value);
        l.grant = value;
        l.grantLabel = g ? g.label : '';
        if (g) {
          if (!g.funds.includes(l.fund)) l.fund = g.funds[0];
          if (!g.programmes.includes(l.program)) l.program = g.programmes[0];
        } else {
          l.fund = 'General Fund';
        }
      }
      if (e.target.name === 'program') {
        const g = jdGrant(l.grant);
        l.program = value;
        if (g && !g.programmes.includes(value)) { l.grant = ''; l.grantLabel = ''; }
      }
      if (['grant', 'program', 'fund'].includes(e.target.name)) {
        row.replaceWith(renderJournalLine(l, i));
        renderJournalSummary();
      }
    });
    linesBox.addEventListener('click', (e) => {
      if (!e.target.classList.contains('jd-remove')) return;
      jd.ed.lines.splice(+e.target.closest('.jd-row').dataset.i, 1);
      jd.dirty = true;
      renderJournalLines();
    });
    $('jd-add-line').addEventListener('click', () => {
      jd.ed.lines.push(jdLine({ code: jdDefaultCode('5310') }));
      jd.dirty = true;
      renderJournalLines();
    });

    // ---- Supporting evidence ----
    $('jd-attach').addEventListener('change', (e) => {
      jd.ed.files.push(...e.target.files);
      e.target.value = '';
      renderJournalFiles();
    });
    $('jd-files').addEventListener('click', (e) => {
      const button = e.target.closest('.jd-remove');
      if (!button) return;
      e.preventDefault();
      if (button.dataset.kept !== undefined) {
        jd.ed.removed.push(+button.dataset.kept);
      } else {
        jd.ed.files.splice(+button.dataset.i, 1);
      }
      jd.dirty = true;
      renderJournalFiles();
    });

    // ---- Footer actions ----
    $('jd-actions').addEventListener('click', (e) => {
      const action = e.target.closest('[data-action]');
      if (!action || action.disabled) return;
      ({
        'save-draft': () => submitJournal('Draft'),
        submit: () => submitJournal('Pending approval'),
        post: approveJournal,
        reject: () => renderJournalActions(true),
        'reject-cancel': () => renderJournalActions(false),
        'reject-confirm': rejectJournal,
        discard: discardJournal,
        reverse: reverseJournal,
      })[action.dataset.action]();
    });
  }

  const jdDefaultCodeFor = (form, code) => form.accounts.some(a => a.code === code) ? code : (form.accounts[0] || {}).code || '';
  const jdDefaultCode = (code) => jdDefaultCodeFor(jd.form, code);

  function renderJournalHead() {
    const { journal, form } = jd;
    const $ = (id) => journalDrawer.querySelector('#' + id);
    $('jd-kicker').textContent = journal ? `Journal · ${journal.ref}` : `New journal · ${form.ref}`;
    $('jd-status').innerHTML = statusPill(jdStatus());
    $('jd-narration').value = jd.ed.narration;
    $('jd-narration').readOnly = !jdCan().save;
    $('jd-memo').value = jd.ed.memo;
    $('jd-memo').readOnly = !jdCan().save;
    $('jd-memo').placeholder = jdCan().save ? 'Context for the approver — grant condition, audit reference, correction being made' : 'No memo.';
    $('jd-add-line-row').hidden = !jdCan().save;
    $('jd-attach-button').hidden = !jdCan().save;
  }

  function renderJournalMeta() {
    const { form, ed, journal } = jd;
    const disabled = jdCan().save ? '' : 'disabled';
    const period = form.periods.find(p => p.name === ed.period) || { name: ed.period, min: ed.date, max: ed.date, closed: true };
    const open = form.periods.filter(p => !p.closed).map(p => p.name);
    const periodOptions = jdUnique(form.periods.filter(p => !p.closed || p.name === ed.period || form.allowClosed).map(p => p.name).concat([ed.period]));
    const periodNote = jdLocked()
      ? `${journal.status} in ${period.name}`
      : period.closed
        ? (form.allowClosed ? `${period.name} is closed — posting is permitted only because the control allows it` : `${period.name} is closed — this entry cannot be posted`)
        : (open.length ? `Open for posting: ${open.join(', ')}` : 'No period is open for posting');

    // A link the register no longer offers (the entry's own reference, or the record that raised it) stays selectable.
    const known = ed.docLink === 'auto' || form.documents.some(d => d.value === ed.docLink);
    const docs = [{ value: 'auto', label: 'Manual — auto-numbered' }]
      .concat(known ? [] : [{ value: ed.docLink, label: (journal && journal.doc) || 'Linked record' }])
      .concat(form.documents);
    const doc = form.documents.find(d => d.value === ed.docLink);
    const [kind, key] = ed.docLink.split(':');
    const docNote = ed.docLink === 'auto'
      ? (journal && journal.docLink === 'auto' && journal.doc ? journal.doc : `${form.docRefs[ed.type]} · next in the series`)
      : doc ? doc.note
      : kind === 'recurring' ? `Generated from recurring template ${key} · ${journal.doc}`
      : kind === 'module' ? `Raised by ${key.replace(/_/g, ' ')} from ${journal.doc || 'its source record'}`
      : (journal && journal.doc) || 'Reference carried from the source ledger';

    const preparer = journal ? journal.preparer : form.preparer;
    const preparerNote = jdCan().mine ? 'You · recorded from your sign-in' : 'Cannot be reassigned';

    journalDrawer.querySelector('#jd-meta').innerHTML = `
      <label class="jd-field">Period
        <select id="jd-period" ${disabled}>${jdOptions(periodOptions, ed.period)}</select>
        <span class="jd-note ${period.closed && !form.allowClosed && !jdLocked() ? 'warn' : ''}">${esc(periodNote)}</span>
      </label>
      <label class="jd-field">Posting date
        <input type="date" id="jd-date" value="${esc(ed.date)}" min="${esc(period.min)}" max="${esc(period.max)}" ${disabled}>
        <span class="jd-note">${jdLocked() ? esc(jdDate(ed.date)) : `Any day in ${esc(period.name)} · ${esc(jdDate(period.min))} to ${esc(jdDate(period.max))}`}</span>
      </label>
      <label class="jd-field">Journal type
        <select id="jd-type" ${disabled}>${jdOptions(jdUnique(form.types.concat([ed.type])), ed.type)}</select>
      </label>
      <label class="jd-field">Source document
        <select id="jd-doc" style="max-width:290px;" ${disabled}>${jdOptions(docs, ed.docLink)}</select>
        <span class="jd-note">${esc(docNote)}</span>
      </label>
      <label class="jd-field">Prepared by
        <div class="jd-preparer">
          <span style="font-size:12.5px;font-weight:500;color:#28352F;text-transform:none;letter-spacing:0;">${esc(preparer)}</span>
          <span style="margin-inline-start:auto;font-size:10px;color:#8B948F;text-transform:none;letter-spacing:0;">${esc(preparerNote)}</span>
        </div>
      </label>`;
  }

  function renderJournalLine(l, i) {
    const { form } = jd;
    const disabled = jdCan().save ? '' : 'disabled';
    const g = jdGrant(l.grant);
    const grants = [{ value: '', label: 'Unassigned' }]
      .concat(l.grant && !g ? [{ value: l.grant, label: l.grantLabel || l.grant }] : [])
      .concat(form.grants.map(x => ({ value: x.ref, label: x.label })));
    const funds = jdUnique((g ? g.funds : form.funds).concat([l.fund]));
    const programmes = jdUnique((g ? g.programmes : form.programmes).concat([l.program]));
    const accounts = form.accounts.some(a => a.code === l.code) ? form.accounts : [{ code: l.code, label: l.code }].concat(form.accounts);

    const row = document.createElement('div');
    row.className = 'jd-grid jd-row';
    row.dataset.i = i;
    row.innerHTML = `
      <div><select name="code" class="jd-cell mono" ${disabled}>${jdOptions(accounts.map(a => ({ value: a.code, label: a.label })), l.code)}</select></div>
      <div><input name="desc" class="jd-cell text" value="${esc(l.desc)}" placeholder="Description" ${disabled}></div>
      <div><select name="grant" class="jd-cell ${jdGap(l) ? 'gap' : ''}" ${disabled}>${jdOptions(grants, l.grant)}</select></div>
      <div title="${g && g.funds.length === 1 ? 'Fixed by the agreement' : ''}"><select name="fund" class="jd-cell" ${disabled}>${jdOptions(funds, l.fund)}</select></div>
      <div><select name="program" class="jd-cell" ${disabled}>${jdOptions(programmes, l.program)}</select></div>
      <div><input name="dr" class="jd-cell amount" inputmode="decimal" value="${esc(l.dr)}" placeholder="—" ${disabled}></div>
      <div><input name="cr" class="jd-cell amount" inputmode="decimal" value="${esc(l.cr)}" placeholder="—" ${disabled}></div>
      <div style="display:flex;align-items:center;justify-content:center;padding:0;">${disabled ? '' : '<button type="button" class="jd-remove" aria-label="Remove line">✕</button>'}</div>`;
    return row;
  }

  function renderJournalLines() {
    const box = journalDrawer.querySelector('#jd-lines');
    box.replaceChildren(...jd.ed.lines.map(renderJournalLine));
    renderJournalSummary();
  }

  function renderJournalSummary() {
    const { ed } = jd;
    const $ = (id) => journalDrawer.querySelector('#' + id);
    const dr = ed.lines.reduce((a, l) => a + (parseFloat(l.dr) || 0), 0);
    const cr = ed.lines.reduce((a, l) => a + (parseFloat(l.cr) || 0), 0);
    const balanced = dr === cr && dr > 0;
    const gaps = ed.lines.filter(jdGap).length;
    const grantsUsed = jdUnique(ed.lines.map(l => l.grant)).map(ref => (jdGrant(ref) || {}).label || (ed.lines.find(l => l.grant === ref) || {}).grantLabel || ref);
    const funds = jdUnique(ed.lines.map(l => l.fund));

    $('jd-total-dr').textContent = fmtMoney(dr);
    $('jd-total-cr').textContent = fmtMoney(cr);

    let html = balanced
      ? `<div class="jd-msg ok">✓ Entry balances. Debits equal credits at ${fmtMoney(dr)}.</div>`
      : (dr > 0 || cr > 0)
        ? `<div class="jd-msg warn">Out of balance by ${fmtMoney(Math.abs(dr - cr))}. The entry cannot be saved or submitted until debits equal credits.</div>`
        : '<div class="jd-msg idle">Enter the debit and credit amounts. The entry can be submitted once both sides agree.</div>';
    if (gaps > 0) {
      html += `<div class="jd-msg warn stack">
        <span>${gaps === 1 ? 'One line charges a restricted fund with no grant against it.' : `${gaps} lines charge a restricted fund with no grant against them.`}</span>
        <span style="color:#9A7A55;font-size:11.5px;">Restricted funds report by award. Without a grant on the line the cost cannot be claimed on a donor report.</span>
      </div>`;
    } else if (grantsUsed.length > 1) {
      html += `<div class="jd-small">Split across ${grantsUsed.length} awards · ${esc(grantsUsed.join(', '))}</div>`;
    }
    html += `<div class="jd-small">Ledger fund follows the award · ${esc(funds.join(', ') || 'no fund yet')}</div>`;
    $('jd-summary').innerHTML = html;

    // Debits must equal credits before either save action is allowed — no exception for drafts.
    const set = (action, disabled) => { const b = $('jd-actions').querySelector(`[data-action="${action}"]`); if (b) b.disabled = disabled; };
    set('save-draft', !balanced);
    set('submit', !balanced || gaps > 0);
    set('post', !balanced || gaps > 0);
  }

  function renderJournalFiles() {
    const { ed } = jd;
    const editable = jdCan().save;
    const size = (b) => b < 1024000 ? `${Math.max(1, Math.round(b / 1024))} KB` : `${(b / 1048576).toFixed(1)} MB`;
    const kept = ed.attachments.filter(a => !ed.removed.includes(a.id));
    const ref = jd.journal ? encodeURIComponent(jd.journal.ref) : '';
    journalDrawer.querySelector('#jd-files').innerHTML = kept.map(a => `
      <a class="jd-file" href="/api/journals/${ref}/attachments/${a.id}" style="text-decoration:none;color:inherit;">
        <span class="jd-file-name">${esc(a.name)}</span>
        <span class="jd-file-size">${esc(a.size)}</span>
        ${editable ? `<button type="button" class="jd-remove" data-kept="${a.id}" aria-label="Remove ${esc(a.name)}">✕</button>` : ''}
      </a>`).join('') + ed.files.map((f, i) => `
      <div class="jd-file">
        <span class="jd-file-name">${esc(f.name)}</span>
        <span class="jd-file-size">${size(f.size)}</span>
        <button type="button" class="jd-remove" data-i="${i}" aria-label="Remove ${esc(f.name)}">✕</button>
      </div>`).join('');
    const count = kept.length + ed.files.length;
    journalDrawer.querySelector('#jd-attach-label').textContent = count ? '+ Attach another document' : '+ Attach the supporting document';
    const note = journalDrawer.querySelector('#jd-files-note');
    note.hidden = count > 0;
    note.textContent = editable ? 'The reference points at the record; the audit file wants the document itself — invoice, board minute or funder letter.' : 'No supporting document is attached.';
  }

  function renderJournalTrail() {
    const trail = jd.journal ? jd.journal.trail || [] : [];
    journalDrawer.querySelector('#jd-trail').innerHTML = trail.length ? `
      <div class="jd-caps">Audit trail</div>
      ${trail.map(t => `<div style="display:flex;gap:10px;font-size:12px;color:#3E4A44;"><span style="color:#A3ABA7;font-family:'IBM Plex Mono',monospace;font-size:11px;min-width:78px;">${esc(t.when)}</span>${esc(t.what)}</div>`).join('')}` : '';
  }

  /** The footer: what the acting user may do with the entry in its current status. */
  function renderJournalActions(rejecting) {
    const { journal, form } = jd;
    const can = jdCan();
    const box = journalDrawer.querySelector('#jd-actions');

    if (rejecting) {
      box.innerHTML = `
        <input id="jd-reject-reason" class="jd-reason" placeholder="Reason for returning ${esc(journal.ref)} to ${esc(journal.preparer)} — kept on the audit trail">
        <span class="jd-error" id="jd-error" hidden></span>
        <div style="margin-inline-start:auto;display:flex;align-items:center;gap:9px;">
          <button type="button" class="btn" data-action="reject-cancel">Cancel</button>
          <button type="button" class="btn btn-primary" data-action="reject-confirm">Return to draft</button>
        </div>`;
      box.querySelector('#jd-reject-reason').focus();
      return;
    }

    const sodNote = can.mine
      ? 'You prepared this entry. Approval has to come from someone else — switch actor in the account menu.'
      : `${form.role} may prepare and submit, but not post. ${journal ? journal.preparer : 'The preparer'} is waiting on an approver.`;

    box.innerHTML = `
      ${can.sodBlocked ? `<span class="jd-sod">◐ Prepared by ${esc(journal.preparer)}. ${esc(sodNote)}</span>` : ''}
      ${can.discard ? '<button type="button" class="btn jd-quiet" data-action="discard">Discard</button>' : ''}
      ${can.reverse ? '<button type="button" class="btn jd-quiet" data-action="reverse">Reverse entry</button>' : ''}
      <span class="jd-error" id="jd-error" hidden></span>
      <div style="margin-inline-start:auto;display:flex;align-items:center;gap:9px;">
        <button type="button" class="btn" data-close>Close</button>
        ${can.save ? '<button type="button" class="btn" data-action="save-draft">Save draft</button>' : ''}
        ${can.submit ? '<button type="button" class="btn btn-primary" data-action="submit">Submit for approval</button>' : ''}
        ${can.post ? '<button type="button" class="btn" data-action="reject">Reject</button>' : ''}
        ${can.post ? '<button type="button" class="btn btn-primary" data-action="post">Approve and post</button>' : ''}
      </div>`;
    renderJournalSummary();
  }

  function closeJournalDrawer() {
    if (!journalDrawer || journalDrawer.hidden) return;
    const opts = jd ? jd.opts : {};
    journalDrawer.hidden = true;
    jd = null;
    if (opts.onClose) opts.onClose();
  }

  function showJournalError(message) {
    const error = journalDrawer.querySelector('#jd-error');
    error.textContent = message;
    error.hidden = false;
  }

  /** Runs a footer action with its buttons held, closing the editor on success. */
  async function journalAction(run) {
    const buttons = journalDrawer.querySelectorAll('#jd-actions button');
    journalDrawer.querySelector('#jd-error').hidden = true;
    buttons.forEach(b => { b.dataset.was = b.disabled ? '1' : ''; b.disabled = true; });
    try {
      const { message, journal } = await run();
      const { opts } = jd;
      closeJournalDrawer();
      toast(message);
      if (opts.onSaved) opts.onSaved(journal);
    } catch (err) {
      showJournalError(err.message || 'Could not reach the server.');
      buttons.forEach(b => { b.disabled = b.dataset.was === '1'; });
    }
  }

  function submitJournal(status) {
    const { ed, journal } = jd;
    return journalAction(async () => {
      const body = new FormData();
      body.append('payload', JSON.stringify({
        narration: ed.narration, date: ed.date, type: ed.type, period: ed.period, docLink: ed.docLink, memo: ed.memo, status,
        removeAttachments: ed.removed,
        lines: ed.lines.map(l => ({ code: l.code, desc: l.desc.trim(), grantRef: l.grant, fund: l.fund, program: l.program, dr: parseFloat(l.dr) || 0, cr: parseFloat(l.cr) || 0 })),
      }));
      ed.files.forEach(f => body.append('attachments[]', f, f.name));

      const url = journal ? `/api/journals/${encodeURIComponent(journal.ref)}` : '/api/journals';
      const res = await fetch(url, { method: 'POST', headers: { Accept: 'application/json' }, body });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.error || 'Could not save the journal.');
      return { journal: data.journal, message: `${data.journal.ref} · ${status === 'Draft' ? 'draft saved' : 'submitted for approval'}.` };
    });
  }

  function approveJournal() {
    const { journal, form } = jd;
    if (jd.dirty) {
      showJournalError('Approval posts the entry as it was submitted. Close without saving, or save the changes and have them approved again.');
      return;
    }
    return journalAction(async () => {
      const data = await postJSON(`/api/journals/${encodeURIComponent(journal.ref)}/approve`);
      return { journal: data.journal, message: `${journal.ref} · approved and posted by ${form.preparer}.` };
    });
  }

  function rejectJournal() {
    const { journal } = jd;
    const reason = journalDrawer.querySelector('#jd-reject-reason').value.trim();
    return journalAction(async () => {
      const data = await postJSON(`/api/journals/${encodeURIComponent(journal.ref)}/reject`, { reason });
      return { journal: data.journal, message: `${journal.ref} returned to ${journal.preparer} as a draft.` };
    });
  }

  function discardJournal() {
    const { journal } = jd;
    return journalAction(async () => {
      await postJSON(`/api/journals/${encodeURIComponent(journal.ref)}/discard`);
      return { journal: null, message: `${journal.ref} discarded.` };
    });
  }

  function reverseJournal() {
    const { journal } = jd;
    return journalAction(async () => {
      const data = await postJSON(`/api/journals/${encodeURIComponent(journal.ref)}/reverse`);
      return { journal: data.journal, message: `${data.journal.ref} raised against ${journal.ref} and sent for approval.` };
    });
  }

  function showJournalDrawer(opts, form, journal, ed) {
    if (!journalDrawer) buildJournalDrawer();
    jd = { opts, form, journal, ed, dirty: false };
    renderJournalHead();
    renderJournalMeta();
    renderJournalLines();
    renderJournalFiles();
    renderJournalTrail();
    renderJournalActions(false);
    journalDrawer.querySelector('.jd-body').scrollTop = 0;
    journalDrawer.hidden = false;
    if (jdCan().save) journalDrawer.querySelector('#jd-narration').focus();
  }

  /** Opens the editor for a new entry. `defaultLine` pre-codes the first line (the general ledger's account). */
  async function openNewJournalDrawer(opts) {
    opts = opts || {};
    let form;
    try {
      form = await fetchJSON('/api/journals/form');
    } catch (err) {
      toast('The journal form could not be loaded.');
      return;
    }
    if (!form.canPrepare) {
      toast(`${form.role} cannot raise journal entries. Switch to a preparer in the account menu.`);
      return;
    }

    const first = opts.defaultLine || {};
    showJournalDrawer(opts, form, null, {
      narration: '', period: form.period, date: form.date, type: 'Standard', docLink: 'auto', memo: '', files: [], attachments: [], removed: [],
      lines: [
        jdLine({ code: first.code || '5310', fund: form.funds.includes(first.fund) ? first.fund : 'General Fund', program: form.programmes.includes(first.program) ? first.program : 'Shared services' }),
        jdLine({ code: '1110' }),
      ].map(l => ({ ...l, code: jdDefaultCodeFor(form, l.code) })),
    });
  }

  /** Opens an entry from the register: editable while it is a draft or awaiting approval, read-only once posted. */
  async function openJournal(ref, opts) {
    opts = opts || {};
    let form, journal;
    try {
      [form, journal] = await Promise.all([fetchJSON('/api/journals/form'), fetchJSON(`/api/journals/${encodeURIComponent(ref)}`)]);
    } catch (err) {
      toast(`Journal ${ref} could not be found.`);
      if (opts.onClose) opts.onClose();
      return;
    }

    showJournalDrawer(opts, form, journal, {
      narration: journal.narration || '', period: journal.period, date: journal.dateISO, type: journal.type, docLink: journal.docLink,
      memo: journal.memo || '', files: [], attachments: journal.attachments || [], removed: [],
      lines: journal.lines.map(l => jdLine({
        code: l.code, desc: l.desc, grant: l.grantRef || '', grantLabel: l.grant || '', fund: l.fund, program: l.program,
        dr: l.dr ? String(l.dr) : '', cr: l.cr ? String(l.cr) : '',
      })),
    });
  }


  // ---- Generic record drawer, shared by the modules added for the v5 nav ----

  let recordDrawer;

  /**
   * POSTs JSON and surfaces the API's own message on failure.
   *
   * The controllers answer a rejected action with a reason rather than a generic
   * error — a budget block names the shortfall, a blocked receipt names what is
   * actually outstanding — so that text is what the user needs to see.
   */
  async function postJSON(url, body) {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body || {}),
    });
    const data = await res.json().catch(() => ({}));
    // The refusal's other fields (e.g. needsAuthority) travel with the error.
    if (!res.ok) throw Object.assign(new Error(data.error || `Request failed: ${res.status}`), { status: res.status, data });
    return data;
  }

  function buildRecordDrawer() {
    recordDrawer = document.createElement('div');
    recordDrawer.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(13,27,24,.28);z-index:1000;align-items:flex-start;justify-content:flex-end;';
    recordDrawer.innerHTML = `
      <div class="rd-panel" style="background:#fff;width:432px;max-width:92vw;height:100%;overflow-y:auto;box-shadow:-18px 0 40px rgba(13,27,24,.14);display:flex;flex-direction:column;">
        <div style="display:flex;align-items:center;gap:10px;padding:13px 18px;border-bottom:1px solid #E4E2DB;position:sticky;top:0;background:#fff;z-index:1;">
          <span class="rd-title" style="font-size:13px;font-weight:600;"></span>
          <button type="button" class="rd-close" style="margin-left:auto;border:1px solid #DDDAD2;background:#fff;border-radius:6px;width:26px;height:26px;cursor:pointer;color:#6E7873;font-size:14px;line-height:1;">&times;</button>
        </div>
        <div class="rd-body" style="flex:1;"></div>
      </div>`;
    document.body.appendChild(recordDrawer);
    recordDrawer.addEventListener('click', (e) => { if (e.target === recordDrawer) closeDrawer(); });
    recordDrawer.querySelector('.rd-close').addEventListener('click', closeDrawer);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeDrawer(); });
  }

  /** `wide` gives a summary document (the board pack) room; records stay at 432px. */
  function drawer(title, html, opts) {
    if (!recordDrawer) buildRecordDrawer();
    const panel = recordDrawer.querySelector('.rd-panel');
    panel.style.width = opts && opts.wide ? '640px' : '432px';
    recordDrawer.querySelector('.rd-title').textContent = title;
    recordDrawer.querySelector('.rd-body').innerHTML = html;
    panel.scrollTop = 0;
    recordDrawer.style.display = 'flex';
  }

  function closeDrawer() {
    if (recordDrawer) recordDrawer.style.display = 'none';
  }

  /** A labelled bar, used for budget consumption and verification progress. */
  function bar(pct, tone) {
    const width = Math.max(0, Math.min(100, pct));
    const colour = tone === 'urgent' ? '#A6412F' : tone === 'warn' ? '#B4703A' : 'var(--accent-ink)';
    return `<span class="bar-track" style="display:block;"><span class="bar-fill" style="width:${width}%;background:${colour};"></span></span>`;
  }

  return { fmtMoney, brand, download, fetchJSON, postJSON, toast, statGrid, tabs, table, esc, badge, bar, pageHead, drawer, closeDrawer, openNewJournalDrawer, openJournal, statusPill };
})();
