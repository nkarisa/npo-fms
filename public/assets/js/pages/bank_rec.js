/**
 * Bank reconciliation (v5): one cash account's statement for a period set against
 * its cash book. Lines are ticked on both sides and matched when they agree, the
 * bank's own entries are journalised for approval, and a reconciliation with no
 * difference is signed off. /bank-rec?account=1110&period=Aug 2026 opens a statement
 * straight away. Figures and rules come from /api/bank-rec; the API applies every
 * rule again.
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
    try {
      data = await UI.fetchJSON('/api/bank-rec?' + p.toString());
    } catch (err) {
      app.querySelector('#br-statement').innerHTML = `<div class="coa-empty">${esc(err.message)}</div>`;
      return;
    }
    render();
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

  shell();
  refresh();
})();
