/**
 * Budgets (v5).
 *
 * The approved budget against actual, phased month by month. Variance is read
 * against the phasing to date, not the annual figure, so a seasonal programme is
 * not flagged for spending in its season.
 *
 * A year's budget is a series of versions. Budget is moved between lines in a
 * working revision, which someone else approves before it replaces the approved
 * budget; and next year's budget is derived from what the awards still allow and
 * what core costs ran at this year. The API applies every rule again when a change
 * is sent; the modal says the same things before it is.
 */
(async function () {
  const app = document.getElementById('app');
  const esc = UI.esc;
  const params = new URLSearchParams(location.search);
  const state = { version: params.get('version') || '', group: 'Account group', basis: 'phased', q: '' };
  const COLS = 'bgt-cols';
  let data = null;
  let searchTimer;

  const fmt = (n) => UI.fmtMoney(n);
  const num = (v) => parseFloat(String(v == null ? '' : v).replace(/[^0-9.]/g, '')) || 0;
  const pillClass = { Over: 'over', Watch: 'watch', 'On track': 'track', Underspent: 'under' };
  const pill = (status) => `<span class="bgt-pill ${pillClass[status] || 'track'}">${esc(status === 'Over' ? 'Over' : status)}</span>`;

  async function refresh() {
    const p = new URLSearchParams({ version: state.version, group: state.group, basis: state.basis, q: state.q });
    data = await UI.fetchJSON('/api/budgets?' + p.toString());
    if (data.version) state.version = data.version;
    render();
  }

  // ---- The screen ----

  function render() {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Funds and grants',
      title: 'Budgets',
      blurb: 'Approved budget against actual, phased month by month. Variance is read against the phasing to date, '
        + 'not the annual figure, so seasonal programmes are not flagged unfairly.',
      actions: (data.canPrepare && data.newYear && data.newYear.years.length ? '<button type="button" class="btn" data-act="new-year">New financial year</button>' : '')
        + (data.canPrepare && data.revision ? '<button type="button" class="btn" data-act="revise">Budget revision</button>' : '')
        + (data.versions.length ? '<button type="button" class="btn btn-primary" data-act="export">Export variance report</button>' : ''),
    });
    app.querySelectorAll('[data-act]').forEach((b) => b.addEventListener('click', () => ({
      'new-year': openNewYear, revise: () => openRevision(), export: exportReport,
    })[b.dataset.act]()));

    if (!data.versions.length) {
      const empty = document.createElement('div');
      empty.className = 'card empty-state';
      empty.textContent = data.message;
      app.appendChild(empty);
      return;
    }

    app.appendChild(UI.statGrid(data.stats));
    const banner = versionBanner();
    if (banner) app.appendChild(banner);
    app.appendChild(filters());
    app.appendChild(table());
  }

  /** Where the chosen version stands, and what can be done with it. Silent for the approved budget. */
  function versionBanner() {
    const s = data.state;
    if (s.status === 'approved') return null;

    const div = document.createElement('div');
    div.className = 'bgt-banner ' + s.status;
    const moves = s.moves.length ? `
      <div class="bgt-moves">
        ${s.moves.map((m) => `
          <div class="bgt-move">
            <span class="bgt-mono">${esc(m.from)} → ${esc(m.to)}</span>
            <span class="bgt-mono bgt-move-amt">${esc(m.amount)}</span>
            <span>${esc(m.reason)}</span>
            <span class="bgt-faint">${esc(m.ref)} · ${esc(m.by)}</span>
          </div>`).join('')}
      </div>` : '';
    const actions = [
      s.canDiscard ? '<button type="button" class="btn" data-v="discard">Discard</button>' : '',
      s.canSendBack ? '<button type="button" class="btn" data-v="send-back">Send back</button>' : '',
      s.canSubmit ? '<button type="button" class="btn btn-primary" data-v="submit">Submit for approval</button>' : '',
      s.canApprove || s.needsAuthority ? '<button type="button" class="btn btn-primary" data-v="approve">Approve</button>' : '',
    ].join('');

    div.innerHTML = `
      <div class="bgt-banner-main">
        <div class="bgt-banner-text">
          <b>${esc(data.versionLabel)}</b>
          <span>${esc(s.note)}</span>
          ${s.returnedNote ? `<span class="bgt-returned">Sent back: ${esc(s.returnedNote)}</span>` : ''}
          ${s.approvalNote && !s.needsAuthority ? `<span class="bgt-faint">${esc(s.approvalNote)}</span>` : ''}
        </div>
        ${actions ? `<div class="bgt-banner-acts">${actions}</div>` : ''}
      </div>
      ${s.needsAuthority ? `
        <div class="bgt-authority">
          <span>${esc(s.approvalNote)}</span>
          <input id="bgt-authority" placeholder="Reference, e.g. funder consent letter">
        </div>` : ''}
      <div class="bgt-sendback" hidden>
        <input id="bgt-sendback-note" placeholder="What the preparer should change">
        <button type="button" class="btn" data-v="send-back-confirm">Send back to preparer</button>
      </div>
      ${moves}`;

    div.querySelectorAll('[data-v]').forEach((b) => b.addEventListener('click', () => versionAction(b, b.dataset.v)));
    return div;
  }

  async function versionAction(button, what) {
    const body = { version: state.version };
    if (what === 'send-back') {
      const box = app.querySelector('.bgt-sendback');
      box.hidden = false;
      box.querySelector('input').focus();
      return;
    }
    if (what === 'send-back-confirm') {
      what = 'send-back';
      body.note = app.querySelector('#bgt-sendback-note').value;
    }
    if (what === 'approve') {
      const ref = app.querySelector('#bgt-authority');
      if (ref) body.authorityRef = ref.value;
    }
    if (what === 'discard' && !confirm('Discard ' + data.versionLabel + '? Everything applied to it is removed; the approved budget is unchanged.')) return;

    button.disabled = true;
    try {
      const result = await UI.postJSON('/api/budgets/versions/' + what, body);
      UI.toast(result.message);
      state.version = result.version || '';
      await refresh();
    } catch (err) {
      UI.toast(err.message);
      button.disabled = false;
    }
  }

  function filters() {
    const div = document.createElement('div');
    div.className = 'bgt-filters';
    div.innerHTML = `
      <label class="bgt-filter">Budget version
        <select data-f="version" class="wide">${data.versions.map((v) => `<option value="${esc(v.key)}" ${v.key === data.version ? 'selected' : ''}>${esc(v.label)}</option>`).join('')}</select>
      </label>
      <label class="bgt-filter">Group by
        <select data-f="group">${data.groupOptions.map((g) => `<option ${g === data.group ? 'selected' : ''}>${esc(g)}</option>`).join('')}</select>
      </label>
      <label class="bgt-filter">Compare to
        <select data-f="basis">${data.basisOptions.map((b) => `<option value="${esc(b.key)}" ${b.key === data.basis ? 'selected' : ''}>${esc(b.label)}</option>`).join('')}</select>
      </label>
      <div class="bgt-search">
        <span>⌕</span>
        <input type="search" placeholder="Budget line or account code" value="${esc(state.q)}">
      </div>
      <div class="bgt-hint">${esc(data.hint)}</div>`;

    div.querySelectorAll('select[data-f]').forEach((s) => s.addEventListener('change', () => {
      state[s.dataset.f] = s.value;
      refresh();
    }));
    div.querySelector('input[type=search]').addEventListener('input', (e) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(async () => {
        state.q = e.target.value;
        const at = e.target.selectionStart;
        await refresh();
        const again = app.querySelector('.bgt-search input');
        again.focus();
        again.setSelectionRange(at, at);
      }, 220);
    });
    return div;
  }

  function table() {
    const card = document.createElement('div');
    card.className = 'card bgt-card';
    const t = data.totals;
    card.innerHTML = `
      <div class="bgt-scroll">
        <div class="bgt-inner">
          <div class="${COLS} bgt-head">
            <div>Budget line</div><div>Fund</div><div>Programme</div>
            <div class="end">Annual budget</div><div class="end">${esc(data.basisShort)}</div>
            <div class="end">Actual to date</div><div class="end">Variance</div><div>Consumed</div><div>Status</div>
          </div>
          ${data.groups.map((g) => `
            <div class="${COLS} bgt-group">
              <div class="bgt-group-label">${esc(g.label)}</div><div></div><div></div>
              <div class="bgt-mono end strong">${esc(g.annual)}</div>
              <div class="bgt-mono end faint">${esc(g.basis)}</div>
              <div class="bgt-mono end strong">${esc(g.actual)}</div>
              <div class="bgt-mono end strong">${esc(g.variance)}</div>
              <div class="bgt-mono faint small">${esc(g.pct)}</div><div></div>
            </div>
            ${g.lines.map(line).join('')}`).join('')}
          ${data.empty ? '<div class="empty-state">No budget lines match this view.</div>' : ''}
          <div class="${COLS} bgt-total">
            <div class="bgt-total-label">Total expenditure budget</div><div></div><div></div>
            <div class="bgt-mono end heavy">${esc(t.annual)}</div>
            <div class="bgt-mono end faint strong">${esc(t.basis)}</div>
            <div class="bgt-mono end heavy">${esc(t.actual)}</div>
            <div class="bgt-mono end heavy accent">${esc(t.variance)}</div>
            <div class="bgt-mono faint small">${esc(t.pct)}</div><div></div>
          </div>
        </div>
      </div>
      <div class="bgt-foot">
        <span>${esc(data.footer)}</span>
        <span class="bgt-foot-rule">${esc(data.footerRule)}</span>
      </div>`;

    card.querySelectorAll('.bgt-line').forEach((row) => {
      const open = () => openLine(row.dataset.key);
      row.addEventListener('click', open);
      row.addEventListener('keydown', (e) => { if (e.key === 'Enter') open(); });
    });
    return card;
  }

  function line(l) {
    const bar = Math.min(100, l.pctNum);
    const mark = Math.min(100, l.basisPct);
    return `
      <div class="${COLS} bgt-line" tabindex="0" data-key="${esc(l.key)}">
        <div class="bgt-name"><span class="bgt-code">${esc(l.code)}</span><span>${esc(l.name)}</span></div>
        <div class="bgt-plain">${esc(l.fund)}</div>
        <div class="bgt-plain">${esc(l.program)}</div>
        <div class="bgt-mono end">${esc(l.annual)}</div>
        <div class="bgt-mono end faint">${esc(l.basis)}</div>
        <div class="bgt-mono end">${esc(l.actual)}</div>
        <div class="bgt-mono end ${l.adverse ? 'adverse' : 'faint'}">${esc(l.variance)}</div>
        <div class="bgt-consumed">
          <span class="bgt-track">
            <span class="bgt-fill ${pillClass[l.status]}" style="width:${bar}%;"></span>
            <span class="bgt-mark" style="left:min(calc(${mark}% - 1px), calc(100% - 2px));"></span>
          </span>
          <span class="bgt-mono faint small">${esc(l.pct)}</span>
        </div>
        <div>${pill(l.status)}</div>
      </div>`;
  }

  async function exportReport() {
    const p = new URLSearchParams({ version: state.version, group: state.group, basis: state.basis });
    try {
      const res = await fetch('/api/budgets/export?' + p.toString());
      if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'The report could not be exported.');
      UI.download(await res.blob(), `${UI.brand()} budget variance.csv`, res.headers.get('Content-Disposition'));
      UI.toast('Variance report for ' + data.versionLabel + ' exported.');
    } catch (err) {
      UI.toast(err.message);
    }
  }

  // ---- One line ----

  async function openLine(key) {
    let d;
    try {
      d = await UI.fetchJSON('/api/budgets/line?' + new URLSearchParams({ version: state.version, key }).toString());
    } catch (err) {
      UI.toast(err.message);
      return;
    }

    const top = Math.max(1, ...d.months.map((m) => Math.max(m.budget, m.actual || 0)));
    const h = (v) => Math.round((v / top) * 88);
    const chart = d.months.map((m) => `
      <div class="bgt-month">
        <div class="bgt-bars">
          <span class="bgt-bar budget" style="height:${Math.max(2, h(m.budget))}px;" title="Budget ${esc(fmt(m.budget))}"></span>
          <span class="bgt-bar actual ${pillClass[d.status]}" style="height:${m.actual === null ? 0 : Math.max(2, h(Math.max(0, m.actual)))}px;" title="${m.actual === null ? '' : 'Actual ' + esc(fmt(m.actual))}"></span>
        </div>
        <span>${esc(m.label.charAt(0))}</span>
      </div>`).join('');

    UI.drawer(`${d.code} · ${d.name}`, `
      <div class="bgt-d-head">
        <div class="bgt-d-kicker"><span>${esc(d.code)}</span>${pill(d.status === 'Over' ? 'Over' : d.status).replace('>Over<', '>Over budget<')}</div>
        <b>${esc(d.name)}</b>
        <small>${esc(d.fund)} · ${esc(d.program)} · ${esc(d.grant)}</small>
      </div>
      <div class="bgt-d-body">
        ${d.alert ? `<div class="bgt-alert">${esc(d.alert)}</div>` : ''}
        <div class="bgt-facts">
          ${d.facts.map((f) => `<div class="bgt-fact"><span>${esc(f.label)}</span><b class="${f.lead ? 'lead' : ''}">${esc(f.value)}</b></div>`).join('')}
        </div>
        <div>
          <div class="bgt-d-row">
            <div class="bgt-label">Monthly phasing against actual</div>
            <div class="bgt-legend"><span><i class="budget"></i>Budget</span><span><i class="actual"></i>Actual</span></div>
          </div>
          <div class="bgt-chart">${chart}</div>
          <div class="bgt-small">${esc(d.phasingNote)}</div>
          ${d.derivation ? `<div class="bgt-small">${esc(d.derivation)}</div>` : ''}
        </div>
        <div>
          <div class="bgt-label">Version history</div>
          <div class="bgt-history">
            ${d.history.map((h) => `<div><span>${esc(h.label)}</span><span class="bgt-mono">${esc(h.amount)}</span><span class="bgt-faint">${esc(h.note)}</span></div>`).join('')}
          </div>
        </div>
        <div>
          <div class="bgt-label">Revision rules for this line</div>
          <div class="bgt-rules">${d.rules.map((r) => `<div><span>·</span>${esc(r)}</div>`).join('')}</div>
        </div>
      </div>
      <div class="bgt-d-foot">
        <a class="btn" href="/gl?account=${encodeURIComponent(d.code)}">View postings</a>
        <span class="bgt-spacer"></span>
        <button type="button" class="btn" data-d="close">Close</button>
        ${d.canRevise ? '<button type="button" class="btn btn-primary" data-d="revise">Request revision</button>' : ''}
      </div>`, { wide: true });

    document.querySelector('[data-d="close"]').addEventListener('click', UI.closeDrawer);
    const revise = document.querySelector('[data-d="revise"]');
    if (revise) revise.addEventListener('click', () => { UI.closeDrawer(); openRevision(d.key); });
  }

  // ---- Moving budget between lines ----

  let rv = null;

  function openRevision(toKey) {
    const lines = data.revision.lines;
    rv = {
      from: lines[0] ? lines[0].key : '',
      to: toKey || (lines[1] || lines[0] || {}).key || '',
      amount: '', ref: '', reason: '',
    };
    if (rv.from === rv.to && lines[1]) rv.from = lines.find((l) => l.key !== rv.to).key;
    drawRevision();
  }

  /** The same reasons the API gives, in the same order, so nothing is refused after it looked fine. */
  function revisionBlock() {
    const lines = data.revision.lines;
    const from = lines.find((l) => l.key === rv.from);
    const to = lines.find((l) => l.key === rv.to);
    const amt = num(rv.amount);
    if (data.revision.locked) return data.revision.locked;
    if (!from || !to) return 'Choose the line to reduce and the line to increase.';
    if (from.key === to.key) return 'Choose two different budget lines.';
    if (from.fund !== to.fund) return `A revision must net to zero within one fund. ${from.fund} and ${to.fund} are different funds — process this as an inter-fund transfer instead.`;
    if (from.grant !== to.grant) return `These lines sit under different agreements (${from.grant} and ${to.grant}). Budget cannot move between grants.`;
    if (to.fundGroup === 'grant' && to.code.startsWith(data.revision.indirectPrefix)) return `Indirect cost recovery cannot be increased by revision. ${to.code} ${to.name} is a support-cost line.`;
    if (amt > from.available) return `Only ${fmt(from.available)} is uncommitted on ${from.code} ${from.name}.`;
    if (from.fundGroup === 'grant' && from.movedOut + amt > from.approved * data.revision.consentShare + 0.005) {
      return `This takes more than 10% of ${from.code} (${fmt(Math.round(from.approved * data.revision.consentShare))}) off the line`
        + (from.movedOut > 0 ? `, counting the ${fmt(from.movedOut)} already moved in this revision` : '')
        + '. Written consent from the funder is required — attach the amendment before applying.';
    }
    return '';
  }

  function drawRevision() {
    const lines = data.revision.lines;
    const options = (current) => lines.map((l) => `<option value="${esc(l.key)}" ${l.key === current ? 'selected' : ''}>${esc(l.label)}</option>`).join('');

    modal('bgt-rev', 'Budget revision · ' + data.revision.base, 'Move budget between lines', `
      <label class="as-field"><span>Reduce this line</span><select data-r="from">${options(rv.from)}</select></label>
      <label class="as-field"><span>Increase this line</span><select data-r="to">${options(rv.to)}</select></label>
      <div class="bgt-pair">
        <label class="as-field"><span>Amount (KES)</span><input data-r="amount" value="${esc(rv.amount)}" placeholder="0" inputmode="numeric" class="bgt-amount"></label>
        <label class="as-field"><span>Authority reference</span><input data-r="ref" value="${esc(rv.ref)}" placeholder="FIN/BR/2026/07"></label>
      </div>
      <label class="as-field"><span>Justification</span><textarea data-r="reason" rows="2" placeholder="Why the reallocation is needed and how it stays within the funding agreement">${esc(rv.reason)}</textarea></label>
      <div id="bgt-rev-msg"></div>`,
      'Apply to working version', 560);

    // Only the message is redrawn as the fields change, so the dialog under the
    // pointer is never replaced between pressing a button and releasing it.
    const el = document.getElementById('bgt-rev');
    el.querySelectorAll('[data-r]').forEach((input) => input.addEventListener('input', () => {
      rv[input.dataset.r] = input.value;
      revisionMessage();
    }));
    el.querySelector('[data-submit]').addEventListener('click', (e) => commitRevision(e.target));
    revisionMessage();
  }

  function revisionMessage() {
    const lines = data.revision.lines;
    const block = revisionBlock();
    const amt = num(rv.amount);
    const from = lines.find((l) => l.key === rv.from);
    const to = lines.find((l) => l.key === rv.to);
    document.getElementById('bgt-rev-msg').innerHTML = block
      ? `<div class="bgt-alert">${esc(block)}</div>`
      : amt > 0 ? `<div class="bgt-ok">Permitted within ${esc(from.fund)}. ${esc(from.code)} falls to ${esc(fmt(from.annual - amt))} and ${esc(to.code)} rises to ${esc(fmt(to.annual + amt))}. Fund total unchanged.</div>` : '';
  }

  async function commitRevision(button) {
    const block = revisionBlock();
    if (block) { UI.toast(block); return; }
    if (!num(rv.amount)) { UI.toast('Enter an amount to reallocate.'); return; }
    if (!rv.ref.trim()) { UI.toast('An authority reference is required for a budget revision.'); return; }
    if (!rv.reason.trim()) { UI.toast('Say why the reallocation is needed and how it stays within the funding agreement.'); return; }

    button.disabled = true;
    try {
      const result = await UI.postJSON('/api/budgets/revisions', { from: rv.from, to: rv.to, amount: rv.amount, ref: rv.ref, reason: rv.reason });
      closeModal();
      UI.toast(result.message);
      state.version = result.version;
      await refresh();
    } catch (err) {
      UI.toast(err.message);
      button.disabled = false;
    }
  }

  // ---- A new financial year ----

  let ny = null;
  let nyTimer;

  function openNewYear() {
    ny = { step: 1, year: data.newYear.years[0], uplift: 5, keepEmpty: true, derived: null };
    drawNewYear();
  }

  async function derive() {
    const p = new URLSearchParams({ year: ny.year, uplift: ny.uplift, keep: ny.keepEmpty ? '1' : '0' });
    try {
      ny.derived = await UI.fetchJSON('/api/budgets/new-year?' + p.toString());
    } catch (err) {
      UI.toast(err.message);
      ny.step = 1;
    }
    drawNewYear();
  }

  function drawNewYear() {
    const d = ny.derived;
    const step1 = `
      <div class="bgt-pair even">
        <label class="as-field"><span>Financial year</span>
          <select data-n="year">${data.newYear.years.map((y) => `<option value="${y}" ${y === ny.year ? 'selected' : ''}>FY${y}</option>`).join('')}</select></label>
        <label class="as-field"><span>Uplift on core lines</span>
          <div class="bgt-uplift"><input type="range" min="0" max="15" step="1" value="${ny.uplift}" data-n="uplift"><b>${ny.uplift}% on core lines</b></div></label>
      </div>
      <div class="bgt-keep">
        <div>
          <b>Keep lines with nothing left</b>
          <span>Awards closing before the year starts have no ceiling to draw on. Keep the lines at zero so the comparison to this year stays readable, or leave them out.</span>
        </div>
        <button type="button" class="btn" data-n="keep">${ny.keepEmpty ? 'Keeping them' : 'Leaving them out'}</button>
      </div>
      ${d && d.existing && d.year === ny.year ? `<div class="bgt-warn">${esc(d.existing)}</div>` : ''}`;

    const step2 = d ? `
      <div class="bgt-ny-summary">
        ${d.summary.map((s) => `<div><span>${esc(s.label)}</span><b>${esc(s.value)}</b><small>${esc(s.note)}</small></div>`).join('')}
      </div>
      <div class="bgt-checks">
        ${d.checks.map((c) => `<div><i class="${c.ok ? 'ok' : c.tone}">${c.ok ? '✓' : '!'}</i><span>${esc(c.text)}</span></div>`).join('')}
      </div>
      <div class="bgt-ny-table">
        <div class="bgt-ny-row head"><span>Code</span><span>Line</span><span>Basis</span><span class="end">${esc(d.priorLabel)}</span><span class="end">${esc(d.yearLabel)}</span></div>
        ${d.rows.map((r) => `
          <div class="bgt-ny-row">
            <span class="bgt-mono">${esc(r.code)}</span>
            <div><b>${esc(r.program)}</b><small>${esc(r.note)}</small></div>
            <div><span class="bgt-basis ${r.basis === 'Award' ? 'award' : 'core'}">${esc(r.basis)}</span></div>
            <span class="bgt-mono end faint">${esc(r.prior)}</span>
            <span class="bgt-mono end strong">${esc(r.amount)}</span>
          </div>`).join('')}
      </div>` : '<div class="empty-state">Deriving the budget…</div>';

    const intro = d ? d.intro : `FY${ny.year} is built from what is already committed. Grant-funded lines are derived from each award's remaining ceiling, `
      + 'apportioned by the months of the award period that fall inside the year. Core lines roll forward from the approved budget.';

    modal('bgt-ny', ny.step === 1 ? 'Step 1 of 2 · assumptions' : 'Step 2 of 2 · review the derived budget', 'New financial year', `
      <p class="bgt-intro">${esc(intro)}</p>
      ${ny.step === 1 ? step1 : step2}`,
      ny.step === 1 ? 'Derive the budget' : (d ? d.commitLabel : 'Raise'), 760,
      'The draft cannot be used for variance reporting until the Executive Director approves it.',
      ny.step === 2 ? 'Back' : null);

    const el = document.getElementById('bgt-ny');
    const year = el.querySelector('[data-n="year"]');
    if (year) year.addEventListener('change', () => { ny.year = +year.value; ny.derived = null; derive(); });
    const uplift = el.querySelector('[data-n="uplift"]');
    if (uplift) {
      uplift.addEventListener('input', () => {
        ny.uplift = +uplift.value;
        el.querySelector('.bgt-uplift b').textContent = ny.uplift + '% on core lines';
      });
    }
    const keep = el.querySelector('[data-n="keep"]');
    if (keep) keep.addEventListener('click', () => { ny.keepEmpty = !ny.keepEmpty; drawNewYear(); });
    const back = el.querySelector('[data-back]');
    if (back) back.addEventListener('click', () => { ny.step = 1; drawNewYear(); });
    el.querySelector('[data-submit]').addEventListener('click', (e) => {
      if (ny.step === 1) {
        ny.step = 2;
        ny.derived = null;
        clearTimeout(nyTimer);
        drawNewYear();
        derive();
      } else {
        raiseYear(e.target);
      }
    });
    if (ny.step === 1 && !ny.derived) {
      // Fetched quietly so step 1 can warn that a draft for the year already exists.
      nyTimer = setTimeout(derive, 0);
    }
  }

  async function raiseYear(button) {
    if (!ny.derived) return;
    button.disabled = true;
    try {
      const result = await UI.postJSON('/api/budgets/new-year', { year: ny.year, uplift: ny.uplift, keepEmpty: ny.keepEmpty });
      closeModal();
      UI.toast(result.message);
      state.version = result.version;
      await refresh();
    } catch (err) {
      UI.toast(err.message);
      button.disabled = false;
    }
  }

  // ---- The modal shell ----

  let modalEl = null;

  function modal(id, kicker, title, body, submitLabel, width, note, backLabel) {
    const scroll = modalEl && modalEl.id === id ? modalEl.querySelector('.ap-modal-body').scrollTop : 0;
    closeModal();
    modalEl = document.createElement('div');
    modalEl.className = 'ap-modal';
    modalEl.id = id;
    modalEl.innerHTML = `
      <div class="ap-modal-scrim" data-close></div>
      <div class="ap-modal-box" role="dialog" aria-modal="true" aria-label="${esc(title)}" style="width:${width}px;">
        <div class="jd-head" style="padding:16px 20px;border-bottom-color:#E4E2DB;">
          <div style="display:flex;flex-direction:column;gap:3px;">
            <div class="jd-caps" style="letter-spacing:.1em;">${esc(kicker)}</div>
            <div style="font-size:16px;font-weight:600;letter-spacing:-.01em;">${esc(title)}</div>
          </div>
          <button type="button" class="rt-close" data-close aria-label="Close">×</button>
        </div>
        <div class="ap-modal-body">${body}</div>
        <div class="ap-modal-foot">
          <div class="pg-modal-note">${esc(note || '')}</div>
          ${backLabel ? `<button type="button" class="btn" data-back style="height:34px;padding:0 14px;">${esc(backLabel)}</button>` : `<button type="button" class="btn" data-close style="height:34px;padding:0 14px;">Cancel</button>`}
          <button type="button" class="btn btn-primary" data-submit style="height:34px;padding:0 18px;">${esc(submitLabel)}</button>
        </div>
      </div>`;
    document.body.appendChild(modalEl);
    modalEl.querySelector('.ap-modal-body').scrollTop = scroll;
    modalEl.addEventListener('click', (e) => { if (e.target.closest('[data-close]')) closeModal(); });
  }

  function closeModal() {
    if (modalEl) modalEl.remove();
    modalEl = null;
  }

  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

  await refresh();
})();
