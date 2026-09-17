/**
 * Bank reconciliation (v5): one cash account's statement for a period set against
 * its cash book. Lines are ticked on both sides and matched when they agree, the
 * bank's own entries are journalised for approval, and a reconciliation with no
 * difference is signed off. Statements are loaded from the bank's CSV through the
 * upload panel, read with the account's format from Settings → Bank statements.
 * /bank-rec?account=1110&period=Aug 2026 opens a statement straight away. Figures
 * and rules come from /api/bank-rec; the API applies every rule again.
 */
(async function () {
  const app = document.getElementById('app');
  const esc = UI.esc;
  const params = new URLSearchParams(location.search);
  const state = { account: params.get('account') || '', period: params.get('period') || '' };
  const sel = { statement: new Set(), book: new Set() };
  let data = null;

  // Zero shows as "0" here, as the prototype's reconciliation figures do.
  const money = (n) => (n === 0 ? '0' : UI.fmtMoney(n));

  function shell() {
    app.innerHTML = `
      <div class="page-head" style="margin-bottom:0;">
        <div>
          <div class="page-kicker" id="br-kicker"></div>
          <h1 class="page-title">Bank reconciliation</h1>
          <p class="page-blurb">The bank's statement on the left, the cash book on the right. Every line on the statement has to be matched to a posting or journalised before the reconciliation can be completed. Cheques and transfers not yet presented stay in the book as uncleared.</p>
        </div>
        <div class="page-actions" style="margin-left:0;margin-inline-start:auto;">
          <button type="button" class="btn" id="br-upload" hidden>Upload statement</button>
          <button type="button" class="btn" id="br-reopen" hidden>Reopen reconciliation</button>
          <button type="button" class="btn" id="br-auto" hidden>Auto-match</button>
          <button type="button" class="btn btn-primary" id="br-complete"></button>
        </div>
      </div>
      <div class="br-pick">
        <label><span>Account</span><select id="br-account" style="min-width:280px;"></select></label>
        <label><span>Statement period</span><select id="br-period" style="min-width:150px;"></select></label>
        <span class="br-pick-ref" id="br-ref"></span>
        <span class="br-pick-ref br-signed" id="br-signed"></span>
      </div>
      <div class="br-upload-note" id="br-upload-note" hidden></div>
      <div class="stat-grid" id="br-stats" style="margin:16px 0 0;"></div>
      <div class="ap-selbar br-selbar" id="br-selbar" hidden>
        <span style="font-size:12px;font-weight:600;" id="br-sel-label"></span>
        <span class="br-mono" style="font-size:11.5px;color:#B9C6C0;" id="br-sel-sums"></span>
        <span style="margin-inline-start:auto;font-size:11.5px;color:#B9C6C0;" id="br-sel-verdict"></span>
        <button type="button" class="ap-selbar-btn quiet" id="br-clear">Clear</button>
        <button type="button" class="ap-selbar-btn go" id="br-match">Match</button>
      </div>
      <div class="br-sides">
        <div class="coa-card" id="br-statement"></div>
        <div class="coa-card" id="br-book"></div>
      </div>
      <div class="coa-card br-recon" id="br-recon"></div>`;

    app.querySelector('#br-account').addEventListener('change', (e) => { state.account = e.target.value; state.period = ''; clearSelection(); refresh(); });
    app.querySelector('#br-period').addEventListener('change', (e) => { state.period = e.target.value; clearSelection(); refresh(); });
    app.querySelector('#br-auto').addEventListener('click', () => act('auto-match'));
    app.querySelector('#br-complete').addEventListener('click', () => act('complete'));
    app.querySelector('#br-reopen').addEventListener('click', () => act('reopen'));
    app.querySelector('#br-upload').addEventListener('click', () => openUpload());
    app.querySelector('#br-clear').addEventListener('click', () => { clearSelection(); render(); });
    app.querySelector('#br-match').addEventListener('click', () => {
      if (!canMatch()) return;
      act('match', { statement: [...sel.statement], book: [...sel.book] });
    });

    for (const side of ['statement', 'book']) {
      const card = app.querySelector('#br-' + side);
      card.addEventListener('change', (e) => {
        const box = e.target.closest('input[data-id]');
        if (!box) return;
        const id = Number(box.dataset.id);
        box.checked ? sel[side].add(id) : sel[side].delete(id);
        renderSelection();
      });
      card.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-act]');
        if (btn) act(btn.dataset.act, { line: Number(btn.dataset.id) });
      });
    }
  }

  function clearSelection() {
    sel.statement.clear();
    sel.book.clear();
  }

  async function refresh() {
    const p = new URLSearchParams({ account: state.account, period: state.period });
    const res = await fetch('/api/bank-rec?' + p.toString(), { headers: { Accept: 'application/json' } });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
      renderEmpty(body);
      return;
    }
    data = body;
    render();
  }

  /** No statement has been loaded for any account yet. */
  function renderEmpty(body) {
    app.innerHTML = `
      <div class="page-head" style="margin-bottom:0;">
        <div>
          <h1 class="page-title">Bank reconciliation</h1>
          <p class="page-blurb">${esc(body.error || 'The reconciliation could not be loaded.')}</p>
        </div>
      </div>
      <div class="coa-card" style="margin-top:18px;">
        <div class="coa-empty">
          Load a bank or M-Pesa statement to start reconciling.
          <div style="margin-top:14px;"><button type="button" class="btn btn-primary" id="br-upload-first" ${body.canUpload ? '' : `disabled title="${esc((body.role || 'This role') + ' cannot load bank statements. Switch to a preparer to upload one.')}"`}>Upload statement</button></div>
        </div>
      </div>`;
    const first = app.querySelector('#br-upload-first');
    if (first) first.addEventListener('click', () => openUpload(true));
  }

  /** Every write answers with the reconciliation as it now stands. */
  async function act(action, extra) {
    try {
      const res = await UI.postJSON('/api/bank-rec/' + action, { account: state.account, period: state.period, ...(extra || {}) });
      if (action === 'match') clearSelection();
      data = res;
      render();
      UI.toast(res.message);
    } catch (err) {
      UI.toast(err.message);
    }
  }

  function render() {
    state.account = data.account;
    state.period = data.period;
    history.replaceState(null, '', '/bank-rec?' + new URLSearchParams({ account: state.account, period: state.period }).toString());
    // Lines that are now matched cannot stay selected.
    for (const side of ['statement', 'book']) {
      const open = new Set(data[side].rows.filter(r => !r.matched).map(r => r.id));
      for (const id of [...sel[side]]) if (!open.has(id)) sel[side].delete(id);
    }

    app.querySelector('#br-kicker').textContent = data.kicker;
    app.querySelector('#br-account').innerHTML = data.accountOptions
      .map(o => `<option value="${esc(o.code)}" ${o.code === data.account ? 'selected' : ''}>${esc(o.label)}</option>`).join('');
    app.querySelector('#br-period').innerHTML = data.periodOptions
      .map(o => `<option value="${esc(o)}" ${o === data.period ? 'selected' : ''}>${esc(o)}</option>`).join('');
    app.querySelector('#br-ref').textContent = data.statementRef;
    app.querySelector('#br-signed').textContent = data.signedOffBy || (data.periodOpen ? '' : 'The period is closed · read only');
    const note = app.querySelector('#br-upload-note');
    const warn = data.closeAgrees ? '' : `The lines add up to ${data.statement.closing} but the bank printed a closing balance of ${data.printedClose} — part of the statement is missing.`;
    const noteText = [warn || data.uploadNote, data.uploadBlocked].filter(Boolean).join(' · ');
    note.hidden = !noteText;
    note.classList.toggle('warn', !!warn);
    note.textContent = noteText;
    // Shown to everyone, so a role that cannot load statements learns why.
    const uploadBtn = app.querySelector('#br-upload');
    uploadBtn.hidden = false;
    uploadBtn.disabled = !data.can.upload;
    uploadBtn.title = data.uploadBlocked || '';

    const reopen = app.querySelector('#br-reopen');
    reopen.hidden = !data.signedOff;
    reopen.disabled = !data.can.reopen;
    const auto = app.querySelector('#br-auto');
    auto.hidden = data.signedOff;
    auto.disabled = !data.can.match;
    const complete = app.querySelector('#br-complete');
    complete.textContent = data.completeLabel;
    complete.disabled = !data.can.complete;

    app.querySelector('#br-stats').innerHTML = data.stats.map(s => `
      <div class="stat">
        <div class="stat-label">${esc(s.label)}</div>
        <div class="stat-value">${esc(s.value)}</div>
        <div class="stat-note">${esc(s.note)}</div>
      </div>`).join('');

    app.querySelector('#br-statement').innerHTML = side('Bank statement', data.statement, statementTags);
    app.querySelector('#br-book').innerHTML = side('Cash book', data.book, bookTags);
    renderRecon();
    renderSelection();
  }

  function side(title, part, tags) {
    const which = title === 'Cash book' ? 'book' : 'statement';
    const rows = part.rows.map(r => {
      const done = r.matched || data.locked;
      return `
        <div class="br-row${r.matched ? ' done' : ''}">
          <input type="checkbox" data-id="${r.id}" ${sel[which].has(r.id) ? 'checked' : ''} ${done || !data.can.match ? 'disabled' : ''} aria-label="Select ${esc(r.ref)}">
          <span class="br-date">${esc(r.date)}</span>
          <div class="br-what">
            <span class="br-desc" title="${esc(r.desc)}">${esc(r.desc)}</span>
            <div class="br-tags">${tags(r)}</div>
          </div>
          <span class="br-amt${!r.matched && r.amt > 0 ? ' in' : ''}">${esc(money(r.amt))}</span>
        </div>`;
    }).join('');

    return `
      <div class="card-head" style="align-items:baseline;">
        <span class="card-title">${esc(title)}</span>
        <span style="font-size:11px;color:#7A857F;">${esc(part.hint)}</span>
        <span class="br-mono" style="margin-inline-start:auto;font-size:12.5px;font-weight:600;">${esc(part.closing)}</span>
      </div>
      ${rows || '<div class="coa-empty">No lines in this period.</div>'}
      <div class="br-foot">${esc(part.footer)}</div>`;
  }

  function statementTags(r) {
    let html = `<span class="br-ref">${esc(r.ref)}</span>`;
    if (r.matched) {
      html += `<span class="br-tag ok">✓ matched to ${esc(r.matchRef)}</span>`;
      if (data.can.match) html += `<button type="button" class="br-link quiet" data-act="unmatch" data-id="${r.id}">unmatch</button>`;
    } else if (r.pendingRef) {
      html += `<a class="br-tag pending" href="/journals/${encodeURIComponent(r.pendingRef)}">${esc(r.pendingRef)} awaiting approval</a>`;
    } else if (r.journalLabel && data.can.journalise) {
      html += `<button type="button" class="br-link" data-act="journalise" data-id="${r.id}">${esc(r.journalLabel)} →</button>`;
    }
    return html;
  }

  function bookTags(r) {
    let html = `<a class="br-ref link" href="/gl?account=${encodeURIComponent(data.account)}">${esc(r.ref)}</a>`;
    html += r.matched ? '<span class="br-tag ok">✓ cleared</span>' : '<span class="br-tag wait">◐ not yet presented</span>';
    if (r.raised) html += '<span class="br-tag raised">raised from the statement</span>';
    return html;
  }

  function renderRecon() {
    const rows = data.recon.map(r => `
      <div class="br-recon-row ${esc(r.kind)}${r.kind === 'final' ? (data.reconciled ? ' nil' : ' gap') : ''}">
        <span class="br-recon-label">${esc(r.label)}</span>
        <span class="br-recon-note">${esc(r.note)}</span>
        <span class="br-recon-value">${esc(r.value)}</span>
      </div>`).join('');

    app.querySelector('#br-recon').innerHTML = `
      <div class="card-head" style="align-items:baseline;">
        <span class="card-title">Reconciliation statement</span>
        <span style="margin-inline-start:auto;font-size:11px;color:#7A857F;">${esc(data.reconHint)}</span>
      </div>
      <div style="overflow-x:auto;"><div style="min-width:520px;">${rows}</div></div>
      <div class="br-foot">${data.reconciled
        ? '<span style="color:#2C6B58;">✓ The statement and the cash book agree. This reconciliation can be signed off and will satisfy the period-close check.</span>'
        : `<span style="color:#A5442F;">! ${esc(data.gapNote)}</span>`}</div>`;
  }

  const sumOf = (side) => data[side].rows.filter(r => sel[side].has(r.id)).reduce((t, r) => t + r.amt, 0);
  const canMatch = () => sel.statement.size > 0 && sel.book.size > 0 && Math.abs(sumOf('statement') - sumOf('book')) < 0.005;

  function renderSelection() {
    const bar = app.querySelector('#br-selbar');
    bar.hidden = sel.statement.size + sel.book.size === 0;
    if (bar.hidden) return;
    const s = sumOf('statement');
    const b = sumOf('book');
    app.querySelector('#br-sel-label').textContent = `${sel.statement.size} on the statement, ${sel.book.size} in the book`;
    app.querySelector('#br-sel-sums').textContent = `${money(s)}  vs  ${money(b)}`;
    app.querySelector('#br-sel-verdict').textContent = canMatch()
      ? 'Amounts agree — these can be matched'
      : sel.statement.size === 0 || sel.book.size === 0 ? 'Select lines on both sides' : 'Out by ' + money(s - b);
    app.querySelector('#br-match').disabled = !canMatch();
  }

  // ------------------------------------------------------------------
  // Uploading a statement
  // ------------------------------------------------------------------

  const upload = { options: null, preview: null, entries: {}, busy: false };

  async function openUpload(fromEmpty) {
    try {
      upload.options = await UI.fetchJSON('/api/bank-rec/import');
    } catch (err) {
      UI.toast(err.message);
      return;
    }
    upload.preview = null;
    upload.entries = {};
    const o = upload.options;
    const account = (data && !fromEmpty ? data.account : '') || (o.accounts.find(a => a.formatId) || o.accounts[0] || {}).code || '';
    const period = data && !fromEmpty && o.periods.includes(data.period) ? data.period : o.periods[0] || '';

    UI.drawer('Upload statement', `
      <div class="bu" id="bu">
        <p class="bu-intro">Download the statement from the bank or the M-Pesa portal as CSV. It is read with the account's statement format; lines already on the statement are skipped, and nothing loads until the balances agree.</p>
        <div class="bu-grid">
          <label class="bu-field"><span>Account</span><select id="bu-account">${o.accounts.map(a => `<option value="${esc(a.code)}" ${a.code === account ? 'selected' : ''}>${esc(a.code + ' · ' + a.short)}${a.formatId ? '' : ' — no format set'}</option>`).join('')}</select></label>
          <label class="bu-field"><span>Statement period</span><select id="bu-period">${o.periods.map(p => `<option ${p === period ? 'selected' : ''}>${esc(p)}</option>`).join('')}</select></label>
        </div>
        <div class="bu-status" id="bu-status"></div>
        <div class="bu-grid">
          <label class="bu-field"><span>Opening balance</span><input id="bu-opening" inputmode="decimal" autocomplete="off"></label>
          <label class="bu-field"><span>Closing balance on the statement</span><input id="bu-closing" inputmode="decimal" autocomplete="off" placeholder="as printed by the bank"></label>
        </div>
        <label class="bu-field"><span>Statement reference <em>optional</em></span><input id="bu-reference" maxlength="120" autocomplete="off"></label>
        <label class="bu-field"><span>Statement file (CSV)</span><input type="file" id="bu-file" accept=".csv,.txt,text/csv"></label>
        <div class="bu-actions">
          <button type="button" class="btn" id="bu-check">Check file</button>
          <button type="button" class="btn btn-primary" id="bu-load" disabled>Load statement</button>
        </div>
        <div id="bu-preview"></div>
      </div>`, { wide: true });

    const root = document.getElementById('bu');
    const refreshStatus = () => { upload.preview = null; renderUploadStatus(); renderUploadPreview(); };
    root.querySelector('#bu-account').addEventListener('change', refreshStatus);
    root.querySelector('#bu-period').addEventListener('change', refreshStatus);
    for (const id of ['#bu-opening', '#bu-closing', '#bu-reference', '#bu-file']) {
      root.querySelector(id).addEventListener(id === '#bu-file' ? 'change' : 'input', () => { upload.preview = null; renderUploadPreview(); });
    }
    root.querySelector('#bu-check').addEventListener('click', () => sendUpload(false));
    root.querySelector('#bu-load').addEventListener('click', () => sendUpload(true));
    root.addEventListener('change', (e) => {
      const pick = e.target.closest('select[data-line]');
      if (pick) upload.entries[pick.dataset.line] = pick.value;
    });
    renderUploadStatus();
  }

  /** What is already loaded for the chosen account and month. */
  function renderUploadStatus() {
    const root = document.getElementById('bu');
    const acct = upload.options.accounts.find(a => a.code === root.querySelector('#bu-account').value);
    const st = acct && acct.statements[root.querySelector('#bu-period').value];
    const status = root.querySelector('#bu-status');
    const opening = root.querySelector('#bu-opening');
    let html = '';
    let blocked = false;

    if (!acct || !acct.formatId) {
      html = `<span class="bu-warn">! ${esc(acct ? acct.short : 'This account')} has no statement format. Set one in <a href="/settings?section=Bank%20statements">Settings → Bank statements</a>.</span>`;
      blocked = true;
    } else if (st.signedOff) {
      html = `<span class="bu-warn">! ${esc(acct.short)} is signed off for this month. Reopen the reconciliation before loading more of the statement.</span>`;
      blocked = true;
    } else if (st.new) {
      html = `Read as <b>${esc(acct.format)}</b> · a new statement for this month` + (st.opening !== null ? `, opening where the last one closed` : ` — enter the opening balance printed on it`);
    } else {
      html = `Read as <b>${esc(acct.format)}</b> · ${st.lines} ${st.lines === 1 ? 'line' : 'lines'} already loaded, closing ${esc(money(st.closing))}. New lines are added; lines already there are skipped.`;
    }
    status.innerHTML = html;
    opening.value = st && st.opening !== null ? money(st.opening) : '';
    opening.disabled = !st || !st.new;
    for (const id of ['#bu-check', '#bu-file', '#bu-closing', '#bu-reference']) root.querySelector(id).disabled = blocked;
    root.querySelector('#bu-load').disabled = true;
  }

  async function sendUpload(commit) {
    const root = document.getElementById('bu');
    const file = root.querySelector('#bu-file').files[0];
    if (!file) {
      UI.toast('Choose the statement file to upload.');
      return;
    }
    if (upload.busy) return;
    upload.busy = true;
    const account = root.querySelector('#bu-account').value;
    const period = root.querySelector('#bu-period').value;
    const form = new FormData();
    form.append('file', file);
    form.append('payload', JSON.stringify({
      account, period, commit,
      opening: root.querySelector('#bu-opening').disabled ? null : root.querySelector('#bu-opening').value,
      closing: root.querySelector('#bu-closing').value,
      reference: root.querySelector('#bu-reference').value,
      entries: upload.entries,
    }));

    try {
      const res = await fetch('/api/bank-rec/import', { method: 'POST', headers: { Accept: 'application/json' }, body: form });
      const body = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(body.error || `Request failed: ${res.status}`);
      if (commit) {
        UI.closeDrawer();
        clearSelection();
        state.account = account;
        state.period = period;
        if (!data) shell();
        data = body;
        render();
        UI.toast(body.message);
        return;
      }
      upload.preview = body.preview;
      renderUploadPreview();
    } catch (err) {
      UI.toast(err.message);
    } finally {
      upload.busy = false;
    }
  }

  function renderUploadPreview() {
    const root = document.getElementById('bu');
    if (!root) return;
    const p = upload.preview;
    const box = root.querySelector('#bu-preview');
    root.querySelector('#bu-load').disabled = !p || !p.ok;
    root.querySelector('#bu-load').textContent = p && p.ok ? `Load ${p.summary.new} ${p.summary.new === 1 ? 'line' : 'lines'}` : 'Load statement';
    if (!p) {
      box.innerHTML = '';
      return;
    }

    const TAG = { new: ['posted', 'New'], duplicate: ['grey', 'Already loaded'], outside: ['grey', 'Outside ' + p.period], skipped: ['grey', 'Passed over'], error: ['overdue', 'Cannot read'] };
    const chips = [['new', 'new'], ['duplicate', 'already loaded'], ['outside', 'outside the month'], ['skipped', 'passed over'], ['error', 'unreadable']]
      .filter(([k]) => p.summary[k] > 0).map(([k, label]) => `<span class="jr-pill ${TAG[k][0]}">${p.summary[k]} ${label}</span>`).join('');
    const kinds = upload.options.entries;

    box.innerHTML = `
      <div class="bu-summary"><b>${esc(p.file)}</b> · ${p.summary.read} ${p.summary.read === 1 ? 'row' : 'rows'} read ${chips}</div>
      <ul class="bu-checks">${p.checks.map(c => `<li class="${c.ok ? 'ok' : 'bad'}">${c.ok ? '✓' : '!'} ${esc(c.label)}</li>`).join('')}</ul>
      <div class="coa-card">
        ${p.rows.map(r => `
          <div class="br-row bu-row${r.status === 'new' ? '' : ' done'}">
            <span class="br-date" title="File line ${r.line}">${esc(r.date)}</span>
            <div class="br-what">
              <span class="br-desc" title="${esc(r.desc)}">${esc(r.desc)}</span>
              <div class="br-tags">
                <span class="br-ref">${esc(r.ref)}</span>
                <span class="jr-pill ${TAG[r.status][0]}">${esc(TAG[r.status][1])}</span>
                ${r.skip ? `<span class="br-tag wait">${esc(r.skip)}</span>` : ''}
                ${r.errors.length ? `<span class="br-tag bad">line ${r.line}: ${esc(r.errors.join('; '))}</span>` : ''}
                ${r.status === 'new' ? `<select class="bu-entry" data-line="${r.line}" aria-label="Kind of entry">
                  <option value="">Matched to the cash book</option>
                  ${kinds.map(k => `<option value="${esc(k.value)}" ${(upload.entries[r.line] !== undefined ? upload.entries[r.line] : r.entry || '') === k.value ? 'selected' : ''}>${esc(k.text)}</option>`).join('')}
                </select>` : ''}
              </div>
            </div>
            <span class="br-amt${r.status === 'new' && r.amt > 0 ? ' in' : ''}">${esc(r.amount)}</span>
          </div>`).join('') || '<div class="coa-empty">No transactions in the file.</div>'}
      </div>`;
  }

  shell();
  refresh();
})();
