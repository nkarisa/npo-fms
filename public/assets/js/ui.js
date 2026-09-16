/** Small render helpers shared by every page script. No framework — just fetch + DOM. */
const UI = (() => {
  const fmtMoney = (n) => {
    if (n === null || n === undefined) return '—';
    if (typeof n === 'string') return n;
    if (n === 0) return '—';
    const s = Math.abs(Math.round(n)).toLocaleString('en-US');
    return n < 0 ? `(${s})` : s;
  };

  async function fetchJSON(url) {
    const res = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error(`Request failed: ${res.status}`);
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

  // ---- New journal drawer, shared by the Journals, General ledger and dashboard pages ----
  //
  // The v5 journal editor. Everything it offers — periods, document series, linkable
  // records, and the funds and programmes each award may carry — comes from
  // /api/journals/form; the API applies the same rules again when the entry is saved.

  let journalDrawer;
  let jd = null; // { opts, form, ed }

  const jdDate = (iso) => iso ? new Date(iso + 'T00:00:00').toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '';
  const jdPeriodOf = (iso) => (jd.form.periods.find(p => p.min <= iso && iso <= p.max) || {}).name || '';
  const jdGrant = (ref) => jd.form.grants.find(g => g.ref === ref) || null;
  const jdUnique = (list) => list.filter((v, i, a) => v && a.indexOf(v) === i);
  const jdOptions = (list, current, label) => list.map(o => {
    const value = typeof o === 'string' ? o : o.value;
    return `<option value="${esc(value)}" ${value === current ? 'selected' : ''}>${esc(label ? label(o) : (typeof o === 'string' ? o : o.label))}</option>`;
  }).join('');

  function jdLine(l) {
    return { code: '', desc: '', grant: '', fund: 'General Fund', program: 'Shared services', dr: '', cr: '', ...l };
  }

  /** A line in a fund that awards are held in, with no award named. */
  const jdGap = (l) => !l.grant && jd.form.awardFunds.includes(l.fund);

  function buildJournalDrawer() {
    journalDrawer = document.createElement('div');
    journalDrawer.className = 'jd';
    journalDrawer.hidden = true;
    journalDrawer.innerHTML = `
      <div class="jd-backdrop" data-close></div>
      <div class="jd-panel" role="dialog" aria-modal="true" aria-label="New journal">
        <div class="jd-head">
          <div style="display:flex;flex-direction:column;gap:4px;min-width:0;flex:1;">
            <div style="display:flex;align-items:center;gap:9px;">
              <span class="jd-caps" id="jd-kicker"></span>
              <span class="jd-badge">Draft</span>
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
            <div style="padding:9px 12px;border-bottom:1px solid #F0EEE9;"><button type="button" class="jd-dashed" id="jd-add-line">+ Add line</button></div>
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
                <label class="jd-dashed" style="align-self:flex-start;"><span id="jd-attach-label"></span>
                  <input type="file" id="jd-attach" multiple hidden>
                </label>
                <span class="jd-note" id="jd-files-note" style="font-size:11px;">The reference points at the record; the audit file wants the document itself — invoice, board minute or funder letter.</span>
              </div>
            </div>
        </div>
        <div class="jd-actions">
          <span class="jd-error" id="jd-error" hidden></span>
          <div style="margin-inline-start:auto;display:flex;align-items:center;gap:9px;">
            <button type="button" class="btn" data-close>Close</button>
            <button type="button" class="btn" id="jd-save-draft">Save draft</button>
            <button type="button" class="btn btn-primary" id="jd-submit">Submit for approval</button>
          </div>
        </div>
      </div>`;
    document.body.appendChild(journalDrawer);

    const $ = (id) => journalDrawer.querySelector('#' + id);

    journalDrawer.addEventListener('click', (e) => { if (e.target.closest('[data-close]')) closeJournalDrawer(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !journalDrawer.hidden) closeJournalDrawer(); });

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
        if (g && !g.programmes.includes(value)) l.grant = '';
      }
      if (['grant', 'program', 'fund'].includes(e.target.name)) {
        row.replaceWith(renderJournalLine(l, i));
        renderJournalSummary();
      }
    });
    linesBox.addEventListener('click', (e) => {
      if (!e.target.classList.contains('jd-remove')) return;
      jd.ed.lines.splice(+e.target.closest('.jd-row').dataset.i, 1);
      renderJournalLines();
    });
    $('jd-add-line').addEventListener('click', () => {
      jd.ed.lines.push(jdLine({ code: jdDefaultCode('5310') }));
      renderJournalLines();
    });

    // ---- Supporting evidence ----
    $('jd-attach').addEventListener('change', (e) => {
      jd.ed.files.push(...e.target.files);
      e.target.value = '';
      renderJournalFiles();
    });
    $('jd-files').addEventListener('click', (e) => {
      if (!e.target.classList.contains('jd-remove')) return;
      jd.ed.files.splice(+e.target.dataset.i, 1);
      renderJournalFiles();
    });

    $('jd-save-draft').addEventListener('click', () => submitJournal('Draft'));
    $('jd-submit').addEventListener('click', () => submitJournal('Pending approval'));
  }

  function jdDefaultCode(code) {
    const accounts = jd.form.accounts;
    return accounts.some(a => a.code === code) ? code : (accounts[0] || {}).code || '';
  }

  function renderJournalMeta() {
    const { form, ed } = jd;
    const period = form.periods.find(p => p.name === ed.period);
    const open = form.periods.filter(p => !p.closed).map(p => p.name);
    const periodOptions = form.periods.filter(p => !p.closed || p.name === ed.period || form.allowClosed).map(p => p.name);
    const periodNote = period.closed
      ? (form.allowClosed ? `${period.name} is closed — posting is permitted only because the control allows it` : `${period.name} is closed — this entry cannot be posted`)
      : (open.length ? `Open for posting: ${open.join(', ')}` : 'No period is open for posting');
    const docs = [{ value: 'auto', label: 'Manual — auto-numbered' }].concat(form.documents);
    const doc = form.documents.find(d => d.value === ed.docLink);
    const docNote = doc ? doc.note : `${form.docRefs[ed.type]} · next in the series`;

    journalDrawer.querySelector('#jd-meta').innerHTML = `
      <label class="jd-field">Period
        <select id="jd-period">${jdOptions(periodOptions, ed.period)}</select>
        <span class="jd-note ${period.closed && !form.allowClosed ? 'warn' : ''}">${esc(periodNote)}</span>
      </label>
      <label class="jd-field">Posting date
        <input type="date" id="jd-date" value="${esc(ed.date)}" min="${esc(period.min)}" max="${esc(period.max)}">
        <span class="jd-note">Any day in ${esc(period.name)} · ${esc(jdDate(period.min))} to ${esc(jdDate(period.max))}</span>
      </label>
      <label class="jd-field">Journal type
        <select id="jd-type">${jdOptions(form.types, ed.type)}</select>
      </label>
      <label class="jd-field">Source document
        <select id="jd-doc" style="max-width:290px;">${jdOptions(docs, ed.docLink)}</select>
        <span class="jd-note">${esc(docNote)}</span>
      </label>
      <label class="jd-field">Prepared by
        <div class="jd-preparer">
          <span style="font-size:12.5px;font-weight:500;color:#28352F;text-transform:none;letter-spacing:0;">${esc(form.preparer)}</span>
          <span style="margin-inline-start:auto;font-size:10px;color:#8B948F;text-transform:none;letter-spacing:0;">You · recorded from your sign-in</span>
        </div>
      </label>`;
  }

  function renderJournalLine(l, i) {
    const { form } = jd;
    const g = jdGrant(l.grant);
    const grants = [{ value: '', label: 'Unassigned' }].concat(form.grants.map(x => ({ value: x.ref, label: x.label })));
    const funds = jdUnique((g ? g.funds : form.funds).concat([l.fund]));
    const programmes = jdUnique((g ? g.programmes : form.programmes).concat([l.program]));
    const accounts = form.accounts.some(a => a.code === l.code) ? form.accounts : [{ code: l.code, label: l.code }].concat(form.accounts);

    const row = document.createElement('div');
    row.className = 'jd-grid jd-row';
    row.dataset.i = i;
    row.innerHTML = `
      <div><select name="code" class="jd-cell mono">${jdOptions(accounts.map(a => ({ value: a.code, label: a.label })), l.code)}</select></div>
      <div><input name="desc" class="jd-cell text" value="${esc(l.desc)}" placeholder="Description"></div>
      <div><select name="grant" class="jd-cell ${jdGap(l) ? 'gap' : ''}">${jdOptions(grants, l.grant)}</select></div>
      <div title="${g && g.funds.length === 1 ? 'Fixed by the agreement' : ''}"><select name="fund" class="jd-cell">${jdOptions(funds, l.fund)}</select></div>
      <div><select name="program" class="jd-cell">${jdOptions(programmes, l.program)}</select></div>
      <div><input name="dr" class="jd-cell amount" inputmode="decimal" value="${esc(l.dr)}" placeholder="—"></div>
      <div><input name="cr" class="jd-cell amount" inputmode="decimal" value="${esc(l.cr)}" placeholder="—"></div>
      <div style="display:flex;align-items:center;justify-content:center;padding:0;"><button type="button" class="jd-remove" aria-label="Remove line">✕</button></div>`;
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
    const grantsUsed = jdUnique(ed.lines.map(l => l.grant)).map(ref => jdGrant(ref).label);
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
    $('jd-save-draft').disabled = !balanced;
    $('jd-submit').disabled = !balanced || gaps > 0;
  }

  function renderJournalFiles() {
    const files = jd.ed.files;
    const size = (b) => b < 1024000 ? `${Math.max(1, Math.round(b / 1024))} KB` : `${(b / 1048576).toFixed(1)} MB`;
    journalDrawer.querySelector('#jd-files').innerHTML = files.map((f, i) => `
      <div class="jd-file">
        <span class="jd-file-name">${esc(f.name)}</span>
        <span class="jd-file-size">${size(f.size)}</span>
        <button type="button" class="jd-remove" data-i="${i}" aria-label="Remove ${esc(f.name)}">✕</button>
      </div>`).join('');
    journalDrawer.querySelector('#jd-attach-label').textContent = files.length ? '+ Attach another document' : '+ Attach the supporting document';
    journalDrawer.querySelector('#jd-files-note').hidden = files.length > 0;
  }

  function closeJournalDrawer() {
    journalDrawer.hidden = true;
    jd = null;
  }

  async function submitJournal(status) {
    const { ed, opts } = jd;
    const error = journalDrawer.querySelector('#jd-error');
    const buttons = journalDrawer.querySelectorAll('#jd-save-draft, #jd-submit');
    error.hidden = true;

    const body = new FormData();
    body.append('payload', JSON.stringify({
      narration: ed.narration, date: ed.date, type: ed.type, period: ed.period, docLink: ed.docLink, memo: ed.memo, status,
      lines: ed.lines.map(l => ({ code: l.code, desc: l.desc.trim(), grantRef: l.grant, fund: l.fund, program: l.program, dr: parseFloat(l.dr) || 0, cr: parseFloat(l.cr) || 0 })),
    }));
    ed.files.forEach(f => body.append('attachments[]', f, f.name));

    buttons.forEach(b => { b.dataset.was = b.disabled ? '1' : ''; b.disabled = true; });
    try {
      const res = await fetch('/api/journals', { method: 'POST', headers: { Accept: 'application/json' }, body });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        error.textContent = data.error || 'Could not save the journal.';
        error.hidden = false;
        return;
      }
      closeJournalDrawer();
      toast(`${data.journal.ref} · ${status === 'Draft' ? 'draft saved' : 'submitted for approval'}.`);
      if (opts.onSaved) opts.onSaved(data.journal);
    } catch (err) {
      error.textContent = 'Could not reach the server.';
      error.hidden = false;
    } finally {
      buttons.forEach(b => { b.disabled = b.dataset.was === '1'; });
    }
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
    if (!journalDrawer) buildJournalDrawer();

    const first = opts.defaultLine || {};
    jd = { opts, form, ed: null };
    jd.ed = {
      narration: '', period: form.period, date: form.date, type: 'Standard', docLink: 'auto', memo: '', files: [],
      lines: [
        jdLine({ code: jdDefaultCode(first.code || '5310'), fund: form.funds.includes(first.fund) ? first.fund : 'General Fund', program: form.programmes.includes(first.program) ? first.program : 'Shared services' }),
        jdLine({ code: jdDefaultCode('1110') }),
      ],
    };

    journalDrawer.querySelector('#jd-kicker').textContent = `New journal · ${form.ref}`;
    journalDrawer.querySelector('#jd-narration').value = '';
    journalDrawer.querySelector('#jd-memo').value = '';
    journalDrawer.querySelector('#jd-error').hidden = true;
    renderJournalMeta();
    renderJournalLines();
    renderJournalFiles();

    journalDrawer.hidden = false;
    journalDrawer.querySelector('#jd-narration').focus();
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
    if (!res.ok) throw new Error(data.error || `Request failed: ${res.status}`);
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
    const colour = tone === 'urgent' ? '#A6412F' : tone === 'warn' ? '#B4703A' : '#2C6B58';
    return `<span class="bar-track" style="display:block;"><span class="bar-fill" style="width:${width}%;background:${colour};"></span></span>`;
  }

  return { fmtMoney, fetchJSON, postJSON, toast, statGrid, tabs, table, esc, badge, bar, pageHead, drawer, closeDrawer, openNewJournalDrawer };
})();
