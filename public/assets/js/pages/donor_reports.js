/**
 * Donor reports (v5).
 *
 * Expenditure reports built from the ledger, not re-keyed. The register shows
 * where each report stands and whether what the funder is told ties to what is
 * posted; a report opens on its figures, its reconciliation and its compliance
 * record, with the step it is waiting on. The API applies every rule again when
 * a step is taken: a report that does not tie cannot go for review or be
 * submitted, and whoever prepared it never submits it.
 */
(async function () {
  const app = document.getElementById('app');
  const esc = UI.esc;
  const params = new URLSearchParams(location.search);
  // ?q= arrives from an award's drawer, ?status= from the dashboard.
  const state = { status: params.get('status') || 'All', q: params.get('q') || '', page: 1 };
  const COLS = 'dr-cols';
  let data = null;

  const STATUS_CLASS = { Draft: 'draft', 'In review': 'review', Submitted: 'submitted', Accepted: 'accepted', Queried: 'queried', Overdue: 'overdue' };
  const pill = (status) => `<span class="dr-pill ${STATUS_CLASS[status] || 'draft'}">${esc(status)}</span>`;

  async function refresh() {
    const p = new URLSearchParams({ status: state.status, q: state.q, page: state.page });
    data = await UI.fetchJSON('/api/donor-reports?' + p.toString());
    state.page = data.page;
    render();
  }

  // ---- The register ----

  function shell() {
    app.innerHTML = `
      <div class="page-head" style="margin-bottom:0;">
        <div>
          <h1 class="page-title" style="margin-top:0;">Donor reports</h1>
          <p class="page-blurb" style="max-width:660px;">Expenditure reports built from the ledger, not re-keyed. Each report ties to the posted actuals for its grant and period, so what the funder receives reconciles to the books.</p>
        </div>
        <div class="page-actions" style="margin-left:0;margin-inline-start:auto;">
          <button type="button" class="btn dr-lang-btn" data-act="language"><span>⌾</span>Report language</button>
          <button type="button" class="btn" data-act="calendar">Reporting calendar</button>
          <button type="button" class="btn btn-primary" data-act="new" hidden>+ New report</button>
        </div>
      </div>
      <div class="stat-grid" id="dr-stats" style="margin:18px 0 0;"></div>
      <div class="jr-filters">
        <div class="coa-seg" id="dr-tabs"></div>
        <label class="coa-search" style="flex:1 1 220px;min-width:190px;max-width:300px;width:auto;">⌕
          <input type="search" id="dr-q" placeholder="Funder, grant or report period" value="${esc(state.q)}">
        </label>
        <div class="jr-hint" id="dr-hint"></div>
      </div>
      <div class="coa-card">
        <div class="dr-scroll"><div class="dr-inner" id="dr-table"></div></div>
        <div id="dr-pager"></div>
        <div class="coa-foot">
          <span id="dr-footer"></span>
          <span style="margin-inline-start:auto;">Figures drawn from posted ledger actuals · a report cannot be submitted until it ties</span>
        </div>
      </div>`;

    app.querySelectorAll('[data-act]').forEach((b) => b.addEventListener('click', () => ({
      language: openLanguages, calendar: openCalendar, new: openNewReport,
    })[b.dataset.act]()));

    let searchTimer;
    app.querySelector('#dr-q').addEventListener('input', (e) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => { state.q = e.target.value; state.page = 1; refresh(); }, 200);
    });
    app.querySelector('#dr-tabs').addEventListener('click', (e) => {
      const tab = e.target.closest('[data-status]');
      if (!tab) return;
      state.status = tab.dataset.status;
      state.page = 1;
      refresh();
    });
    app.querySelector('#dr-pager').addEventListener('click', (e) => {
      const b = e.target.closest('[data-page]');
      if (!b || b.disabled) return;
      state.page = +b.dataset.page;
      refresh();
    });
    app.querySelector('#dr-table').addEventListener('click', (e) => {
      const row = e.target.closest('[data-ref]');
      if (row) openReport(row.dataset.ref);
    });
    app.querySelector('#dr-table').addEventListener('keydown', (e) => {
      const row = e.target.closest('[data-ref]');
      if (row && e.key === 'Enter') openReport(row.dataset.ref);
    });
  }

  function render() {
    if (!app.querySelector('#dr-table')) shell();
    app.querySelector('[data-act="new"]').hidden = !data.canPrepare;
    app.querySelector('#dr-stats').replaceWith(Object.assign(UI.statGrid(data.stats), { id: 'dr-stats', style: 'margin:18px 0 0;' }));
    app.querySelector('#dr-tabs').innerHTML = data.tabs.map((t) => `
      <button type="button" class="coa-seg-btn ${t.key === data.status ? 'on' : ''}" data-status="${esc(t.key)}">${esc(t.label)}</button>`).join('');
    app.querySelector('#dr-hint').textContent = data.hint;
    app.querySelector('#dr-footer').textContent = data.footer;
    app.querySelector('#dr-table').innerHTML = `
      <div class="${COLS} dr-head">
        <div>Report</div><div>Funder</div><div>Period</div><div>Type</div>
        <div class="end">Reported</div><div class="end">Ledger actual</div><div>Reconciled</div><div>Due</div><div>Status</div>
      </div>
      ${data.rows.map(row).join('')}
      ${data.rows.length ? '' : '<div class="empty-state">No reports match this view.</div>'}`;
    app.querySelector('#dr-pager').innerHTML = data.pages > 1 ? pager() : '';
  }

  function row(r) {
    const reconciled = !r.hasFigures ? '<span class="dr-faint">No figures yet</span>'
      : r.tied ? '<span class="dr-ties">✓ Ties</span>' : `<span class="dr-diff">${esc(r.diff)}</span>`;
    return `
      <div class="${COLS} dr-row" tabindex="0" data-ref="${esc(r.ref)}">
        <div class="dr-report">
          <span>${esc(r.title)}</span>
          <small>${esc(r.ref)} · ${esc(r.grant)}</small>
        </div>
        <div class="dr-plain">${esc(r.funder)}</div>
        <div class="dr-mono dr-faint">${esc(r.period)}</div>
        <div class="dr-plain">${esc(r.type)}</div>
        <div class="dr-mono end">${esc(r.reported)}</div>
        <div class="dr-mono end dr-faint">${esc(r.actual)}</div>
        <div>${reconciled}</div>
        <div><span class="${r.dueSoon ? 'dr-due-soon' : 'dr-faint'}">${esc(r.due)}</span></div>
        <div>${pill(r.status)}</div>
      </div>`;
  }

  function pager() {
    const p = data.page;
    const buttons = [];
    for (let k = 1; k <= data.pages; k++) buttons.push(`<button type="button" class="coa-page ${k === p ? 'on' : ''}" data-page="${k}">${k}</button>`);
    return `
      <div class="coa-pager">
        <span>Showing ${(p - 1) * data.pageSize + 1}–${Math.min(data.filtered, p * data.pageSize)} of ${data.filtered} reports</span>
        <div style="margin-inline-start:auto;display:flex;align-items:center;gap:4px;">
          <button type="button" class="coa-page" data-page="${p - 1}" ${p <= 1 ? 'disabled' : ''}>‹</button>
          ${buttons.join('')}
          <button type="button" class="coa-page" data-page="${p + 1}" ${p >= data.pages ? 'disabled' : ''}>›</button>
        </div>
      </div>`;
  }

  // ---- One report ----

  const report = { el: null, ref: null, d: null, section: 'Expenditure', mode: null };

  async function openReport(ref, section) {
    let d;
    try {
      d = await UI.fetchJSON('/api/donor-reports/' + ref.split('/').map(encodeURIComponent).join('/'));
    } catch (err) {
      UI.toast(err.message);
      return;
    }
    if (report.ref !== ref) report.mode = null;
    Object.assign(report, { ref, d, section: section || (report.ref === ref ? report.section : 'Expenditure') });
    if (!report.el) buildReport();
    drawReport();
    report.el.hidden = false;
  }

  function closeReport() {
    if (report.el) report.el.hidden = true;
    report.ref = null;
    report.mode = null;
  }

  function buildReport() {
    report.el = document.createElement('div');
    report.el.className = 'rt dr-dialog';
    report.el.hidden = true;
    report.el.innerHTML = `
      <div class="jd-backdrop" data-close></div>
      <div class="rt-panel" style="width:760px;" role="dialog" aria-modal="true" aria-label="Donor report">
        <div class="rt-head dr-d-head" id="drd-head"></div>
        <div class="dr-d-tabs" id="drd-tabs"></div>
        <div class="rt-body dr-d-body" id="drd-body"></div>
        <div class="rt-foot dr-d-foot" id="drd-foot"></div>
      </div>`;
    document.body.appendChild(report.el);

    report.el.addEventListener('click', (e) => {
      if (e.target.closest('[data-close]')) return closeReport();
      const tab = e.target.closest('[data-section]');
      if (tab) {
        report.section = tab.dataset.section;
        return drawReport();
      }
      const act = e.target.closest('[data-do]');
      if (act) reportAction(act, act.dataset.do);
    });
  }

  function drawReport() {
    const d = report.d;
    report.el.querySelector('#drd-head').innerHTML = `
      <div class="dr-d-title">
        <div class="dr-d-kicker"><span>${esc(d.ref)}</span>${pill(d.status)}</div>
        <b>${esc(d.title)}</b>
        <small>${esc(d.funder)} · ${esc(d.grant)} · ${esc(d.period)} · prepared by ${esc(d.preparer)}</small>
      </div>
      <button type="button" class="rt-close" data-close aria-label="Close">×</button>`;
    report.el.querySelector('#drd-tabs').innerHTML = ['Expenditure', 'Reconciliation', 'Compliance'].map((k) => `
      <button type="button" class="${k === report.section ? 'on' : ''}" data-section="${k}">${k}</button>`).join('');

    const body = report.el.querySelector('#drd-body');
    body.innerHTML = (d.alert ? `<div class="bgt-alert">${esc(d.alert)}</div>` : '')
      + ({ Expenditure: expenditure, Reconciliation: reconciliation, Compliance: compliance })[report.section](d);
    if (report.section === 'Reconciliation') {
      UI.docPanel(body.querySelector('#drd-docs'), {
        kind: 'donor_report', ref: d.ref, docs: d.attachments, canAdd: d.can.attach, recommended: true,
        empty: 'Nothing attached. Recommended: the general ledger extract, the report as submitted, and the funder\'s acknowledgement or acceptance.',
        label: 'Attach a schedule',
      });
    }
    if (report.section === 'Compliance' && d.can.note) {
      const note = body.querySelector('#drd-note');
      note.addEventListener('change', async () => {
        try {
          await UI.postJSON('/api/donor-reports/note', { ref: d.ref, note: note.value });
          d.note = note.value;
          UI.toast('Note saved to ' + d.ref + '.');
        } catch (err) {
          UI.toast(err.message);
        }
      });
    }
    report.el.querySelector('#drd-foot').innerHTML = footer(d);
  }

  function expenditure(d) {
    const lines = d.lines.length ? d.lines.map((l) => `
      <div class="dr-x-cols dr-x-line">
        <div class="dr-mono dr-faint">${esc(l.code)}</div>
        <div class="dr-x-name">${esc(l.name)}</div>
        <div class="dr-mono end dr-faint">${esc(l.budget)}</div>
        <div class="dr-mono end">${esc(l.period)}</div>
        <div class="dr-mono end">${esc(l.cumulative)}</div>
        <div class="dr-mono end ${l.hot ? 'dr-hot' : 'dr-faint'}">${esc(l.used)}</div>
      </div>`).join('') : `<div class="dr-x-empty">${d.can.refresh ? 'No figures yet. Take them from the ledger to fill the schedule.' : 'This report carries no figures.'}</div>`;

    return `
      <div>
        <div class="dr-d-row">
          <div class="bgt-label" style="margin:0;">Expenditure against approved budget</div>
          <div class="dr-d-aside">${esc(d.figuresNote)}</div>
        </div>
        <div class="dr-box">
          <div class="dr-x-cols dr-x-head">
            <div>Code</div><div>Budget line</div><div class="end">Budget</div><div class="end">This period</div><div class="end">Cumulative</div><div class="end">Used</div>
          </div>
          ${lines}
          <div class="dr-x-cols dr-x-total">
            <div></div><div>Total expenditure</div>
            <div class="dr-mono end">${esc(d.budgetTotal)}</div>
            <div class="dr-mono end">${esc(d.periodTotal)}</div>
            <div class="dr-mono end dr-accent">${esc(d.cumulativeTotal)}</div>
            <div class="dr-mono end dr-faint">${esc(d.usedTotal)}</div>
          </div>
        </div>
        ${d.can.refresh ? `<div class="dr-refresh"><span>A draft can take its figures again, as posted now. Any manual adjustment goes with it.</span><button type="button" class="btn" data-do="refresh">Refresh from ledger</button></div>` : ''}
      </div>
      <div>
        <div class="bgt-label">Fund position for the grant</div>
        <div class="dr-position">
          <div><span>Funds received to date</span><span class="dr-mono">${esc(d.received)}</span></div>
          <div><span>Expenditure to date</span><span class="dr-mono dr-neg">(${esc(d.cumulativeTotal)})</span></div>
          <div class="dr-position-total"><span>Unspent balance held</span><span class="dr-mono">${esc(d.unspent)}</span></div>
        </div>
      </div>`;
  }

  function reconciliation(d) {
    return `
      <div>
        <div class="bgt-label">Reconciliation to the general ledger</div>
        <div class="dr-box">
          ${d.recon.map((c) => `
            <div class="dr-recon">
              <div class="${c.fail ? 'dr-fail' : 'dr-pass'}">${c.fail ? '!' : '✓'}</div>
              <div class="dr-recon-text"><span>${esc(c.label)}</span><small>${esc(c.note)}</small></div>
              <div class="dr-mono end ${c.fail ? 'dr-diff' : 'dr-faint'}">${esc(c.value)}</div>
            </div>`).join('')}
        </div>
      </div>
      ${d.tied
    ? `<div class="bgt-ok">The report ties to the ledger. Reported expenditure of ${esc(d.cumulativeTotal)} equals posted actuals for ${esc(d.grant)}.</div>`
    : `<div class="bgt-alert dr-untied">
          <span>Reported expenditure differs from posted actuals by ${esc(d.diff)}. Resolve before submitting — usually an unposted journal or a coding correction.</span>
          ${d.can.removeAdjustment ? '<button type="button" class="btn" data-do="remove-adjustment">Remove the adjustment</button>' : ''}
        </div>`}
      <div>
        <div class="bgt-label">Supporting schedules attached</div>
        <div id="drd-docs"></div>
      </div>`;
  }

  function compliance(d) {
    const queries = d.queries.length ? `
      <div>
        <div class="bgt-label">Funder queries</div>
        <div class="dr-queries">
          ${d.queries.map((q) => `
            <div class="dr-query">
              <div class="dr-query-head"><span class="dr-mono">${esc(q.ref)}</span><span class="dr-faint">${esc(q.when)}</span>${q.answered ? '<span class="dr-ties">Answered</span>' : ''}</div>
              <div>${esc(q.text)}</div>
              ${!q.answered && d.can.respond
    ? `<label class="dr-field">Response<textarea rows="3" data-answer="${esc(q.ref)}" placeholder="The answer that goes back to ${esc(d.funder)}">${esc(q.response)}</textarea></label>`
    : `<div class="dr-faint">Response: ${esc(q.response || 'Not yet answered.')}</div>`}
            </div>`).join('')}
        </div>
      </div>` : '';

    return `
      <div>
        <div class="bgt-label">Compliance checks for this funder</div>
        <div class="bgt-rules">${d.compliance.length ? d.compliance.map((c) => `<div><span>·</span>${esc(c)}</div>`).join('') : '<div class="dr-faint">No compliance statements recorded for this report.</div>'}</div>
      </div>
      ${queries}
      ${report.mode === 'query' ? `
        <div class="dr-inline">
          <div class="bgt-label" style="margin:0;">Record a funder query</div>
          <label class="dr-field">Their reference<input id="drd-query-ref" placeholder="Leave blank to number it"></label>
          <label class="dr-field">The query, as they put it<textarea id="drd-query" rows="3"></textarea></label>
        </div>` : ''}
      <div>
        <div class="bgt-label">Audit trail</div>
        <div class="dr-trail">${d.trail.map((t) => `<div><span class="dr-mono">${esc(t.when)}</span>${esc(t.what)}</div>`).join('') || '<div class="dr-faint">Nothing recorded yet.</div>'}</div>
      </div>
      <label class="dr-field">Reviewer note
        <textarea id="drd-note" rows="2" placeholder="Notes for the approver or the funder submission cover letter" ${d.can.note ? '' : 'disabled'}>${esc(d.note)}</textarea>
      </label>`;
  }

  function footer(d) {
    const c = d.can;
    const primary = [];
    if (report.mode === 'send-back') {
      return `
        <input class="jd-reason" id="drd-sendback" placeholder="What ${esc(d.preparer)} should change">
        <button type="button" class="btn" data-do="cancel">Cancel</button>
        <button type="button" class="btn btn-primary" data-do="send-back-confirm">Return to preparer</button>`;
    }
    if (report.mode === 'query') {
      return `
        <span class="bgt-spacer"></span>
        <button type="button" class="btn" data-do="cancel">Cancel</button>
        <button type="button" class="btn btn-primary" data-do="query-confirm">Record query</button>`;
    }
    if (c.review) primary.push('<button type="button" class="btn" data-do="review">Send for review</button>');
    if (c.sendBack) primary.push('<button type="button" class="btn" data-do="send-back">Return to preparer</button>');
    if (c.submit) primary.push('<button type="button" class="btn btn-primary" data-do="submit">Submit to funder</button>');
    if (c.respond) primary.push('<button type="button" class="btn btn-primary" data-do="respond">Respond to query</button>');
    if (c.query) primary.push('<button type="button" class="btn" data-do="query">Record funder query</button>');
    if (c.accept) primary.push('<button type="button" class="btn btn-primary" data-do="accept">File acceptance letter</button>');

    return `
      ${d.lines.length ? `<a class="btn" href="${esc(d.glUrl)}">View in ledger</a>` : ''}
      <button type="button" class="btn" data-do="export">Export pack</button>
      <span class="bgt-spacer"></span>
      ${d.submitNote ? `<span class="jd-sod">${esc(d.submitNote)}</span>` : ''}
      <button type="button" class="btn" data-close>Close</button>
      ${primary.join('')}`;
  }

  async function reportAction(button, what) {
    const d = report.d;
    if (what === 'export') return exportPack(d);
    if (what === 'cancel') {
      report.mode = null;
      return drawReport();
    }
    if (what === 'send-back' || what === 'query') {
      report.mode = what;
      if (what === 'query') report.section = 'Compliance';
      drawReport();
      const input = report.el.querySelector(what === 'query' ? '#drd-query' : '#drd-sendback');
      if (input) input.focus();
      return;
    }

    const body = { ref: d.ref };
    let url = {
      refresh: 'refresh', 'remove-adjustment': 'remove-adjustment', review: 'review', submit: 'submit',
      accept: 'accept', respond: 'respond', 'send-back-confirm': 'send-back', 'query-confirm': 'query',
    }[what];
    if (what === 'send-back-confirm') body.note = report.el.querySelector('#drd-sendback').value;
    if (what === 'query-confirm') {
      body.text = report.el.querySelector('#drd-query').value;
      body.queryRef = report.el.querySelector('#drd-query-ref').value;
    }
    if (what === 'respond') {
      if (report.section !== 'Compliance') {
        report.section = 'Compliance';
        drawReport();
        UI.toast('Check the answer to each query, then respond.');
        return;
      }
      body.responses = {};
      report.el.querySelectorAll('[data-answer]').forEach((t) => { body.responses[t.dataset.answer] = t.value; });
    }
    if (what === 'refresh' && d.lines.length && !confirm(`Take ${d.ref}'s figures from the ledger again? The schedule is replaced with what is posted now.`)) return;

    button.disabled = true;
    try {
      const result = await UI.postJSON('/api/donor-reports/' + url, body);
      UI.toast(result.message);
      report.mode = null;
      const closes = ['submit', 'accept', 'respond'].includes(what);
      await refresh();
      if (closes) closeReport();
      else await openReport(d.ref);
    } catch (err) {
      UI.toast(err.message);
      button.disabled = false;
    }
  }

  async function exportPack(d) {
    try {
      const res = await fetch('/api/donor-reports/export?' + new URLSearchParams({ ref: d.ref }).toString());
      if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'The pack could not be exported.');
      UI.download(await res.blob(), `${UI.brand()} donor report ${d.ref.replace(/\//g, '-')}.csv`, res.headers.get('Content-Disposition'));
      UI.toast(`Report pack for ${d.ref} exported with ${d.attachments.length} supporting ${d.attachments.length === 1 ? 'schedule' : 'schedules'} listed.`);
    } catch (err) {
      UI.toast(err.message);
    }
  }

  // ---- Delivery language ----

  let lang = null;

  async function openLanguages(funder) {
    let d;
    try {
      d = await UI.fetchJSON('/api/donor-reports/languages' + (typeof funder === 'string' ? '?' + new URLSearchParams({ funder }).toString() : ''));
    } catch (err) {
      UI.toast(err.message);
      return;
    }
    lang = d;
    if (!d.rows.length) {
      UI.toast('No funder on the reporting calendar names a delivery language yet.');
      return;
    }
    drawLanguages();
  }

  function drawLanguages() {
    const d = lang;
    const p = d.preview;
    modal('dr-lang', 'Report language', 'Delivery language by funder', `
      <p class="bgt-intro">${esc(d.note)}</p>
      <div>
        <div class="bgt-label">Delivery language</div>
        <div class="dr-langs">
          ${d.rows.map((r) => `
            <div class="dr-lang ${r.selected ? 'on' : ''}" data-funder="${esc(r.funder)}">
              <div><b>${esc(r.funder)}</b><small>${esc(r.note)}</small></div>
              <div class="dr-lang-codes">
                ${d.choices.map((c) => `<button type="button" class="${c.code === r.locale ? 'on' : ''}" title="${esc(c.native)} · ${c.coverage}% reviewed" data-locale="${esc(c.code)}" ${d.canChange ? '' : 'disabled'}>${esc(c.short)}</button>`).join('')}
              </div>
            </div>`).join('')}
        </div>
      </div>
      ${p ? `
        <div>
          <div class="bgt-label">Cover page preview</div>
          <div class="dr-cover" dir="${esc(p.dir)}" lang="${esc(p.lang)}">
            <div class="dr-cover-top"><small>${esc(p.org)}</small><b>${esc(p.title)}</b><span>${esc(p.subtitle)}</span></div>
            ${p.lines.map((l) => `<div class="dr-cover-line"><span>${esc(l.label)}</span><span class="dr-mono">${esc(l.value)}</span></div>`).join('')}
            <small class="dr-cover-foot">${esc(p.footnote)}</small>
          </div>
        </div>
        <div class="bgt-warn">${esc(p.warning)}</div>` : ''}`, null, 560, p ? p.status : '');

    modalEl.addEventListener('click', async (e) => {
      const code = e.target.closest('[data-locale]');
      const row = e.target.closest('[data-funder]');
      if (!row) return;
      if (!code) {
        if (!row.classList.contains('on')) openLanguages(row.dataset.funder);
        return;
      }
      if (code.classList.contains('on')) return openLanguages(row.dataset.funder);
      try {
        await UI.postJSON('/api/donor-reports/languages', { funder: row.dataset.funder, locale: code.dataset.locale });
        UI.toast(`${row.dataset.funder}'s reports will be delivered in ${code.title.split(' · ')[0]}, with figures held in KES.`);
        openLanguages(row.dataset.funder);
      } catch (err) {
        UI.toast(err.message);
      }
    });
  }

  // ---- Reporting calendar ----

  function openCalendar() {
    const byMonth = {};
    data.calendar.forEach((r) => {
      const key = r.dueFull.slice(3);
      (byMonth[key] = byMonth[key] || []).push(r);
    });
    const months = Object.keys(byMonth);
    modal('dr-cal', 'Reporting calendar', 'What falls due, and when', months.length ? months.map((m) => `
      <div>
        <div class="bgt-label">${esc(m)}</div>
        <div class="dr-box">
          ${byMonth[m].map((r) => `
            <button type="button" class="dr-cal-row" data-ref="${esc(r.ref)}">
              <span class="dr-mono ${r.dueDays < 0 ? 'dr-diff' : r.dueSoon ? 'dr-due-soon' : 'dr-faint'}">${esc(r.due)}</span>
              <span class="dr-cal-what"><b>${esc(r.title)}</b><small>${esc(r.funder)} · ${esc(r.ref)}</small></span>
              <span class="dr-faint">${esc(r.dueDays < 0 ? Math.abs(r.dueDays) + ' days late' : 'in ' + r.dueDays + ' days')}</span>
              ${pill(r.status)}
            </button>`).join('')}
        </div>
      </div>`).join('') : '<div class="empty-state">Nothing is waiting on a report.</div>', null, 640,
    `Reports still with the organisation or queried · flagged within ${data.warningDays} days of their date`);

    modalEl.addEventListener('click', (e) => {
      const r = e.target.closest('[data-ref]');
      if (!r) return;
      closeModal();
      openReport(r.dataset.ref);
    });
  }

  // ---- A new report ----

  function openNewReport() {
    const f = data.newReport;
    if (!f || !f.awards.length) {
      UI.toast('There is no signed award to report against.');
      return;
    }
    modal('dr-new', 'New report', 'Generate a report from the ledger', `
      <p class="bgt-intro">Choose the award and the period. The schedule is built from what is posted to the award, by account, against its budget; nothing is keyed in.</p>
      <label class="as-field"><span>Award</span>
        <select id="drn-grant">${f.awards.map((a) => `<option value="${esc(a.ref)}" data-from="${esc(a.from)}" data-to="${esc(a.to)}">${esc(a.label)}</option>`).join('')}</select>
      </label>
      <div class="bgt-pair">
        <label class="as-field"><span>Title</span><input id="drn-title" placeholder="Financial report Q4 2026"></label>
        <label class="as-field"><span>Type</span>
          <select id="drn-type">${f.types.map((t) => `<option value="${esc(t.key)}">${esc(t.label)}</option>`).join('')}</select>
        </label>
      </div>
      <div class="bgt-pair">
        <label class="as-field"><span>Period from</span><input type="date" id="drn-from"></label>
        <label class="as-field"><span>Period to</span><input type="date" id="drn-to"></label>
      </div>
      <label class="as-field"><span>Due to the funder</span><input type="date" id="drn-due"></label>
      <div class="dr-faint" id="drn-award"></div>`, 'Generate report', 560, 'It opens as a draft for you; someone else submits it.');

    const grant = modalEl.querySelector('#drn-grant');
    const showAward = () => {
      const o = grant.selectedOptions[0];
      modalEl.querySelector('#drn-award').textContent = `The award runs ${o.dataset.from} to ${o.dataset.to}; the period has to fall inside it.`;
      ['#drn-from', '#drn-to'].forEach((id) => { const i = modalEl.querySelector(id); i.min = o.dataset.from; i.max = o.dataset.to; });
    };
    grant.addEventListener('change', showAward);
    showAward();

    modalEl.querySelector('[data-submit]').addEventListener('click', async (e) => {
      const v = (id) => modalEl.querySelector(id).value;
      e.target.disabled = true;
      try {
        const result = await UI.postJSON('/api/donor-reports', {
          grant: v('#drn-grant'), title: v('#drn-title'), type: v('#drn-type'), from: v('#drn-from'), to: v('#drn-to'), due: v('#drn-due'),
        });
        closeModal();
        UI.toast(result.message);
        await refresh();
        openReport(result.ref);
      } catch (err) {
        modalEl.querySelector('.pg-modal-note').textContent = err.message;
        modalEl.querySelector('.pg-modal-note').classList.add('dr-diff');
        e.target.disabled = false;
      }
    });
  }

  // ---- The modal shell ----

  let modalEl = null;

  function modal(id, kicker, title, body, submitLabel, width, note) {
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
          <button type="button" class="btn" data-close style="height:34px;padding:0 14px;">${submitLabel ? 'Cancel' : 'Close'}</button>
          ${submitLabel ? `<button type="button" class="btn btn-primary" data-submit style="height:34px;padding:0 18px;">${esc(submitLabel)}</button>` : ''}
        </div>
      </div>`;
    document.body.appendChild(modalEl);
    modalEl.addEventListener('click', (e) => { if (e.target.closest('[data-close]')) closeModal(); });
  }

  function closeModal() {
    if (modalEl) modalEl.remove();
    modalEl = null;
  }

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (modalEl) closeModal();
    else closeReport();
  });

  await refresh();
})();
