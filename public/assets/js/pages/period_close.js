/**
 * Period close (v5): the year and month switcher, the close checklist, readiness,
 * what is in the period, the close history and the close pack. Every figure and
 * rule comes from /api/period-close; this script only lays it out.
 */
(async function () {
  const app = document.getElementById('app');
  const esc = UI.esc;
  const query = new URLSearchParams(window.location.search);
  const state = { period: query.get('period') || '', year: query.get('year') || '', yearMenu: false, data: null };

  async function load() {
    const p = new URLSearchParams();
    if (state.period) p.set('period', state.period);
    if (state.year) p.set('year', state.year);
    return UI.fetchJSON('/api/period-close?' + p.toString());
  }

  /** Keeps the selection in the address bar so a reload lands on the same month. */
  function remember() {
    const p = new URLSearchParams({ period: state.period });
    if (String(state.year) !== state.period.slice(0, 4)) p.set('year', state.year);
    history.replaceState(null, '', '/period-close?' + p.toString());
  }

  async function post(action, body) {
    try {
      const data = await UI.postJSON(`/api/period-close/${state.period}/${action}`, body);
      if (data.message) UI.toast(data.message);
      render(data);
    } catch (err) {
      UI.toast(err.message);
      refresh();
    }
  }

  function render(data) {
    state.data = data;
    state.period = data.period.code;
    state.year = data.years.selected;
    remember();

    const y = data.years;
    const r = data.readiness;
    const barColour = r.tone === 'done' ? '#2C6B58' : r.tone === 'blocked' ? '#B4703A' : '#8A9A5B';
    const closeButton = data.period.closed
      ? `<button type="button" class="btn" id="pc-reopen">Reopen ${esc(data.period.name)}</button>`
      : `<button type="button" class="${data.close.blockers ? 'pc-close-blocked' : 'btn btn-primary'}" id="pc-close">${esc(data.close.label)}</button>`;

    app.innerHTML = `
      <div class="page-head">
        <div>
          <div class="page-kicker">${esc(data.kicker)}</div>
          <h1 class="page-title">Period close</h1>
          <p class="page-blurb">Closing a period locks it against further posting. Every item below has to be settled first — the checks that can be read from the ledger are answered for you, the rest are confirmed by the person who did the work.</p>
        </div>
        <div class="page-actions" style="margin-inline-start:auto;margin-left:0;">
          <button type="button" class="btn" id="pc-pack" style="white-space:nowrap;">Close pack</button>
          ${closeButton}
        </div>
      </div>

      <div class="pc-years">
        <div class="pc-seg">
          ${y.shown.map(s => `<button type="button" class="pc-year ${s.year === y.selected ? 'on' : ''}" data-year="${s.year}">${esc(s.label)}${s.note ? `<small>${esc(s.note)}</small>` : ''}</button>`).join('')}
          ${y.earlier.length ? `
            <div class="pc-earlier">
              <button type="button" class="pc-year" id="pc-earlier">Earlier · ${y.earlier.length}<small style="font-size:8px;">▼</small></button>
              <div class="pc-earlier-menu" ${state.yearMenu ? '' : 'hidden'}>
                <div class="jd-caps" style="padding:8px 12px;border-bottom:1px solid #F2F1EC;">Earlier years</div>
                ${y.earlier.map(e => `<button type="button" class="pc-earlier-item" data-year="${e.year}"><span style="font-size:12px;font-weight:600;color:#16211E;">${esc(e.label)}</span><span style="margin-inline-start:auto;font-size:10.5px;color:#8B948F;white-space:nowrap;">${esc(e.note)}</span></button>`).join('')}
              </div>
            </div>` : ''}
        </div>
        <span class="pc-year-note">${esc(y.note)}</span>
        ${y.offYear ? `<span class="pc-off-year">${esc(y.offYear)}</span>` : ''}
      </div>

      <div class="pc-periods">
        ${y.periods.map(p => `<button type="button" class="pc-period ${p.code === data.period.code ? 'on' : ''}" data-period="${esc(p.code)}">${esc(p.label)} · ${esc(p.state)}</button>`).join('')}
      </div>

      <div class="pc-grid">
        <div class="card" style="margin:0;">
          <div class="card-head">
            <span class="card-title">Close checklist — ${esc(data.period.name)}</span>
            <span class="card-hint" style="margin-inline-start:auto;margin-left:0;">${esc(data.checklistHint)}</span>
          </div>
          ${data.tasks.map(t => `
            <div class="pc-task">
              <div>
                ${t.kind === 'confirmation'
                  ? `<input type="checkbox" data-step="${esc(t.key)}" ${t.ticked ? 'checked' : ''} ${t.locked || !t.canTick ? 'disabled' : ''} title="${t.locked ? 'Locked with the period' : t.canTick ? '' : 'For ' + esc(t.owner)}" aria-label="${esc(t.label)}">`
                  : `<span style="font-size:13px;color:${t.settled ? '#2C6B58' : '#A5442F'};">${t.settled ? '✓' : '!'}</span>`}
              </div>
              <div style="display:flex;flex-direction:column;gap:3px;min-width:0;">
                <span class="pc-task-label">${esc(t.label)}</span>
                <span class="pc-task-note">${esc(t.note)}</span>
                <span class="pc-task-owner">${esc(t.owner)}</span>
              </div>
              <div class="pc-task-side">
                ${t.settled ? '<span class="pc-pill settled">Settled</span>' : t.kind === 'ledger' ? '<span class="pc-pill blocking">Blocking</span>' : '<span class="pc-pill waiting">Outstanding</span>'}
                ${t.href ? `<a class="pc-link" href="${esc(t.href)}">${esc(t.link)} →</a>` : ''}
              </div>
            </div>`).join('')}
          <div class="pc-foot">${esc(data.footer)}</div>
        </div>

        <div class="pc-side">
          <div class="card" style="margin:0;">
            <div style="padding:14px 16px;display:flex;flex-direction:column;gap:10px;">
              <div style="display:flex;align-items:baseline;gap:10px;">
                <span class="card-title">Readiness</span>
                <span class="pc-mono" style="margin-inline-start:auto;font-size:15px;font-weight:600;">${r.pct}%</span>
              </div>
              <div class="pc-ready-bar"><div style="width:${Math.max(2, r.pct)}%;background:${barColour};"></div></div>
              <span style="font-size:11.5px;color:#7A857F;text-wrap:pretty;">${esc(r.note)}</span>
            </div>
            <div style="border-top:1px solid #EEEDE8;padding:11px 16px;background:#FAF9F6;display:flex;align-items:center;gap:10px;">
              <span style="font-size:11px;color:#7A857F;">Locks posting from</span>
              <span class="pc-mono" style="margin-inline-start:auto;font-size:11.5px;color:#28352F;">${esc(r.lock)}</span>
            </div>
          </div>

          <div class="card" style="margin:0;">
            <div class="card-head"><span class="card-title">What is in the period</span></div>
            ${data.totals.rows.map(row => `
              <div class="pc-row">
                <span style="font-size:12px;color:#3E4A44;">${esc(row.label)}</span>
                <span class="pc-mono" style="margin-inline-start:auto;font-size:12px;font-weight:600;color:#16211E;">${esc(row.value)}</span>
              </div>`).join('')}
            <div style="padding:10px 16px;background:#FAF9F6;font-size:11px;">
              ${data.totals.state === 'balanced' ? '<span style="color:#2C6B58;">✓ Debits and credits agree for the period</span>'
                : data.totals.state === 'unbalanced' ? '<span style="color:#A5442F;">! The period does not balance</span>'
                : `<span style="color:#8A5B2E;">◐ ${esc(data.totals.note)}</span>`}
            </div>
          </div>

          <div class="card" style="margin:0;">
            <div class="card-head"><span class="card-title">Close history</span></div>
            ${data.history.map(h => `
              <div style="padding:10px 16px;border-bottom:1px solid #F2F1EC;display:flex;flex-direction:column;gap:2px;">
                <span style="font-size:11.5px;color:#28352F;">${esc(h.what)}</span>
                <span style="font-size:10.5px;color:#8B948F;">${esc(h.meta)}</span>
              </div>`).join('') || '<div class="pc-foot">No period has been closed yet.</div>'}
          </div>
        </div>
      </div>`;

    bind(data);
  }

  function bind(data) {
    app.querySelectorAll('[data-year]').forEach(b => b.addEventListener('click', () => {
      state.year = b.dataset.year;
      state.yearMenu = false;
      refresh();
    }));
    const earlier = document.getElementById('pc-earlier');
    if (earlier) earlier.addEventListener('click', (e) => {
      e.stopPropagation();
      state.yearMenu = !state.yearMenu;
      app.querySelector('.pc-earlier-menu').hidden = !state.yearMenu;
    });
    app.querySelectorAll('[data-period]').forEach(b => b.addEventListener('click', () => {
      state.period = b.dataset.period;
      refresh();
    }));
    app.querySelectorAll('[data-step]').forEach(box => box.addEventListener('change', () => {
      post('confirm', { step: box.dataset.step, done: box.checked });
    }));
    const close = document.getElementById('pc-close');
    if (close) close.addEventListener('click', () => {
      if (data.close.blockers) {
        UI.toast(`${data.close.blockers} ${data.close.blockers === 1 ? 'item is' : 'items are'} still outstanding — ${data.close.firstBlocker.charAt(0).toLowerCase() + data.close.firstBlocker.slice(1)}.`);
        return;
      }
      post('close');
    });
    const reopen = document.getElementById('pc-reopen');
    if (reopen) reopen.addEventListener('click', () => {
      const reason = window.prompt(`Why is ${data.period.name} being reopened? The reason is kept in the audit log.`);
      if (reason === null) return;
      post('reopen', { reason });
    });
    document.getElementById('pc-pack').addEventListener('click', () => openPack(data));
  }

  document.addEventListener('click', (e) => {
    if (state.yearMenu && !e.target.closest('.pc-earlier')) {
      state.yearMenu = false;
      const menu = app.querySelector('.pc-earlier-menu');
      if (menu) menu.hidden = true;
    }
  });

  // ---- Close pack ----

  let packDrawer;

  function openPack(data) {
    const pack = data.pack;
    if (!packDrawer) {
      packDrawer = document.createElement('div');
      packDrawer.className = 'pk';
      document.body.appendChild(packDrawer);
      packDrawer.addEventListener('click', (e) => { if (e.target.closest('[data-close]')) packDrawer.hidden = true; });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape') packDrawer.hidden = true; });
    }
    packDrawer.innerHTML = `
      <div class="jd-backdrop" data-close></div>
      <div class="pk-panel" role="dialog" aria-modal="true" aria-label="${esc(pack.title)}">
        <div style="flex:0 0 auto;display:flex;align-items:flex-start;gap:12px;padding:16px 20px;border-bottom:1px solid #E4E2DB;">
          <div style="display:flex;flex-direction:column;gap:4px;min-width:0;">
            <span style="font-size:15px;font-weight:600;letter-spacing:-0.01em;">${esc(pack.title)}</span>
            <span style="font-size:11px;color:#8B948F;">${esc(pack.meta)}</span>
          </div>
          <button type="button" class="jd-x" data-close aria-label="Close" style="margin-inline-start:auto;">×</button>
        </div>
        <div style="flex:1;min-height:0;overflow-y:auto;">
          <p style="margin:0;padding:14px 20px;font-size:12px;color:#5C665F;text-wrap:pretty;border-bottom:1px solid #F2F1EC;">${esc(pack.intro)}</p>
          <div style="padding:11px 20px;border-bottom:1px solid #EEEDE8;display:flex;align-items:center;gap:12px;">
            <span style="font-size:12px;font-weight:600;">Contents</span>
            <span style="margin-inline-start:auto;font-size:11px;color:#7A857F;">${esc(pack.count)}</span>
          </div>
          ${pack.docs.map(d => `
            <div class="pk-doc">
              <span class="pc-mono" style="font-size:11px;color:#9AA39E;padding-top:2px;">${esc(d.no)}</span>
              <div style="display:flex;flex-direction:column;gap:3px;min-width:0;">
                <span class="pc-task-label">${esc(d.name)}</span>
                <span class="pc-task-note">${esc(d.detail)}</span>
                ${d.figure ? `<span class="pc-mono" style="font-size:11.5px;color:#28352F;">${esc(d.figure)}</span>` : ''}
              </div>
              <div class="pc-task-side">
                <span class="pc-pill ${d.ready ? 'settled' : 'waiting'}">${d.ready ? 'Ready' : 'Outstanding'}</span>
                ${d.href ? `<a class="pc-link" href="${esc(d.href)}">Open →</a>` : ''}
              </div>
            </div>`).join('')}
        </div>
        <div style="flex:0 0 auto;border-top:1px solid #E4E2DB;padding:13px 20px;background:#FAF9F6;display:flex;align-items:center;gap:12px;">
          <span style="font-size:11px;color:#7A857F;text-wrap:pretty;min-width:0;">${esc(pack.readyNote)}</span>
          <div style="margin-inline-start:auto;display:flex;align-items:center;gap:8px;flex:0 0 auto;">
            <button type="button" class="btn" id="pk-print">Print</button>
            <button type="button" class="btn btn-primary" id="pk-pdf">Export PDF</button>
          </div>
        </div>
      </div>`;
    packDrawer.querySelector('#pk-print').addEventListener('click', () => printPack(data, false));
    packDrawer.querySelector('#pk-pdf').addEventListener('click', () => printPack(data, true));
    packDrawer.hidden = false;
  }

  /** Renders the full pack off-screen and prints only that. The print dialog also saves to PDF. */
  async function printPack(data, asPdf) {
    let pack;
    try {
      pack = await UI.fetchJSON(`/api/period-close/${data.period.code}/pack`);
    } catch (err) {
      UI.toast('The close pack could not be assembled.');
      return;
    }

    let sheet = document.getElementById('pc-print');
    if (!sheet) {
      sheet = document.createElement('div');
      sheet.id = 'pc-print';
      document.body.appendChild(sheet);
    }
    const head = (title, sub) => `<div class="pp-head"><div class="pp-org">${esc(pack.org)} · ${esc(pack.period)}</div><div class="pp-section-title">${esc(title)}</div><div class="pp-small">${esc(sub)}</div></div>`;
    const rows = (list, cells) => list.map(x => `<tr>${cells(x)}</tr>`).join('');
    const amountTable = (list) => `<table><tbody>${rows(list, a => `<td style="width:52px;" class="pp-small">${esc(a.code)}</td><td>${esc(a.name)}</td><td class="num">${esc(a.amount)}</td>`)}</tbody></table>`;
    const total = (label, value) => `<table><tbody><tr class="total"><td>${esc(label)}</td><td class="num">${esc(value)}</td></tr></tbody></table>`;

    sheet.innerHTML = `
      <section>
        <div class="pp-head">
          <div class="pp-org">${esc(pack.org)}</div>
          <div class="pp-title">Close pack</div>
          <div class="pp-sub">${esc(pack.period)}</div>
        </div>
        <div class="pp-small">${esc(pack.stamp)}</div>
        <div class="pp-caps">Contents</div>
        <table><tbody>${rows(pack.contents, c => `<td style="width:34px;" class="num">${esc(c.no)}</td><td>${esc(c.name)}</td><td class="pp-small" style="text-align:end;">${esc(c.status)}</td>`)}</tbody></table>
        <div class="pp-signs"><div>Prepared by · Finance</div><div>Approved by · Executive Director</div></div>
      </section>
      <section>
        ${head('Trial balance', 'All active accounts with a balance · Kenya Shillings')}
        <table>
          <thead><tr><th>Code</th><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
          <tbody>${rows(pack.trialBalance.rows, t => `<td class="pp-small">${esc(t.code)}</td><td>${esc(t.name)}</td><td class="num">${esc(t.dr)}</td><td class="num">${esc(t.cr)}</td>`)}
            <tr class="total"><td></td><td>Total</td><td class="num">${esc(pack.trialBalance.dr)}</td><td class="num">${esc(pack.trialBalance.cr)}</td></tr>
          </tbody>
        </table>
        <div class="pp-small" style="margin-top:6px;">${esc(pack.trialBalance.note)}</div>
      </section>
      <section>
        ${head('Statement of financial position', 'Assets, liabilities and funds carried forward')}
        <div class="pp-caps">Assets</div>${amountTable(pack.position.assets)}${total('Total assets', pack.position.assetTotal)}
        <div class="pp-caps">Liabilities</div>${amountTable(pack.position.liabilities)}${total('Total liabilities', pack.position.liabilityTotal)}
        <div style="margin-top:12px;">${total('Funds carried forward', pack.position.fundTotal)}</div>
      </section>
      <section>
        ${head('Statement of income and expenditure', 'Income raised and spend charged in the period')}
        <div class="pp-caps">Income</div>${amountTable(pack.activities.income)}${total('Total income', pack.activities.incomeTotal)}
        <div class="pp-caps">Expenditure</div>${amountTable(pack.activities.expenditure)}${total('Total expenditure', pack.activities.expenditureTotal)}
        <div style="margin-top:12px;">${total('Surplus for the period', pack.activities.surplus)}</div>
      </section>
      <section>
        ${head('Fund movement schedule', 'Opening, income, spend, transfers and closing balance per fund')}
        <table>
          <thead><tr><th>Fund</th><th class="num">Opening</th><th class="num">Income</th><th class="num">Spend</th><th class="num">Transfers</th><th class="num">Closing</th></tr></thead>
          <tbody>${rows(pack.funds, f => `<td>${esc(f.name)}<span class="pp-small"> · ${esc(f.cls)}</span></td><td class="num">${esc(f.opening)}</td><td class="num">${esc(f.income)}</td><td class="num">${esc(f.spend)}</td><td class="num">${esc(f.transfers)}</td><td class="num">${esc(f.closing)}</td>`)}</tbody>
        </table>
      </section>
      <section>
        ${head('Bank and M-Pesa reconciliations', 'Each cash account against its statement at the period end')}
        <table><tbody>${rows(pack.banks, b => `<td style="width:52px;" class="pp-small">${esc(b.code)}</td><td>${esc(b.name)}</td><td style="text-align:end;">${esc(b.state)}</td>`)}</tbody></table>
        <div class="pp-small" style="margin-top:8px;">Statements and the matched cash-book lines behind each reconciliation are held in the bank reconciliation module and form part of this pack.</div>
      </section>
      <section>
        ${head('Journal listing', 'Every entry posted in the period, with preparer and approver')}
        <div class="pp-small" style="margin-bottom:6px;">${esc(pack.journalNote)}</div>
        <table>
          <thead><tr><th>Ref</th><th>Narrative</th><th>Prepared</th><th>Approved</th><th class="num">Value</th></tr></thead>
          <tbody>${pack.journals.length ? rows(pack.journals, j => `<td class="pp-small" style="white-space:nowrap;">${esc(j.ref)}</td><td>${esc(j.memo)}</td><td>${esc(j.preparer)}</td><td>${esc(j.approver)}</td><td class="num">${esc(j.amount)}</td>`)
            : '<tr><td colspan="5" class="pp-small">No entry lines are printed for this period.</td></tr>'}</tbody>
        </table>
      </section>
      <section>
        ${head('Budget against actual', 'Approved budget to date against spend charged')}
        <table>
          <thead><tr><th>Line</th><th class="num">Budget</th><th class="num">Actual</th><th class="num">Variance</th><th style="text-align:end;">Status</th></tr></thead>
          <tbody>${rows(pack.budget, l => `<td>${esc(l.name)}</td><td class="num">${esc(l.budget)}</td><td class="num">${esc(l.actual)}</td><td class="num">${esc(l.variance)}</td><td style="text-align:end;">${esc(l.status)}</td>`)}</tbody>
        </table>
      </section>
      <section>
        ${head('Signed close checklist', 'Every control confirmed before the period was locked')}
        <table><tbody>${rows(pack.checklist, c => `<td>${esc(c.label)}</td><td class="pp-small">${esc(c.owner)}</td><td style="text-align:end;">${esc(c.state)}</td>`)}</tbody></table>
        <div class="pp-small" style="margin-top:8px;">${esc(pack.stamp)}</div>
        <div class="pp-signs"><div>Finance Manager · date</div><div>Executive Director · date</div></div>
      </section>`;

    const root = document.documentElement;
    const title = document.title;
    root.classList.add('pc-printing');
    if (asPdf) {
      document.title = `ELOG close pack — ${pack.period}`;
      UI.toast(`Choose “Save as PDF” as the destination — the file is named ELOG close pack — ${pack.period}.`);
    }
    const restore = () => { root.classList.remove('pc-printing'); document.title = title; window.removeEventListener('afterprint', restore); };
    window.addEventListener('afterprint', restore);
    setTimeout(() => window.print(), 30);
  }

  async function refresh() {
    try {
      render(await load());
    } catch (err) {
      app.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`;
    }
  }

  refresh();
})();
