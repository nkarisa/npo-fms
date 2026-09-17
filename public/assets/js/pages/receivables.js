/**
 * Receivables (v5): donor claims and other invoices from draft through to receipt.
 * The headline stats, an ageing strip on the outstanding balance, the invoice list
 * (status tabs, search, fund; ten a page) with a selection that can be issued,
 * reminded or received in full, the allowance for doubtful debts (ageing rates and
 * what is held against them), the invoice drawer with receipt capture, allowance,
 * write-off and recovery after write-off, the claim builder, and the aged statement. /receivables/<invoice> opens an invoice straight
 * away. Figures and rules come from /api/receivables; the API applies every rule again.
 */
(async function () {
  const app = document.getElementById('app');
  const esc = UI.esc;
  const fmt = UI.fmtMoney;
  const params = new URLSearchParams(location.search);
  const state = { status: params.get('status') || 'All', age: 'All', fund: 'All funds', q: '', page: 1 };
  const selected = new Map(); // invoice no → outstanding
  let data = null;
  let editingRates = false;

  const PILL = { Draft: 'draft', Issued: 'approved', 'Part received': 'scheduled', Overdue: 'overdue', Received: 'posted', 'Written off': 'reversed' };
  const pill = (status) => `<span class="jr-pill ${PILL[status] || 'draft'}">${esc(status)}</span>`;
  const plural = (n, one, many) => n + ' ' + (n === 1 ? one : many);
  const skippedNote = (d) => d.skipped && d.skipped.length
    ? ` ${plural(d.skipped.length, 'invoice', 'invoices')} skipped — ${d.skipped[0].reason}${d.skipped.length > 1 ? ' …' : ''}`
    : '';

  async function refresh() {
    const p = new URLSearchParams({ status: state.status, age: state.age, fund: state.fund, q: state.q, page: state.page });
    try {
      data = await UI.fetchJSON('/api/receivables?' + p.toString());
    } catch (err) {
      app.querySelector('#ar-table').innerHTML = `<div class="coa-empty">${esc(err.message)}</div>`;
      return;
    }
    state.page = data.page;
    render();
  }

  function shell() {
    app.innerHTML = `
      <div class="page-head" style="margin-bottom:0;">
        <div>
          <h1 class="page-title" style="margin-top:0;">Receivables</h1>
          <p class="page-blurb" style="max-width:660px;">Donor claims and other invoices from draft through to receipt. Issuing a claim raises the receivable and recognises the income; a receipt clears it against the account the money lands in.</p>
        </div>
        <div class="page-actions" style="margin-left:0;margin-inline-start:auto;">
          <button type="button" class="btn" id="ar-statement">Aged statement</button>
          <button type="button" class="btn btn-primary" id="ar-new">+ New donor invoice</button>
        </div>
      </div>
      <div class="stat-grid" id="ar-stats" style="margin:18px 0 0;"></div>
      <div class="ap-aging" id="ar-aging"></div>
      <div class="coa-card ar-allowance" id="ar-allowance"></div>
      <div class="jr-filters">
        <div class="coa-seg" id="ar-tabs"></div>
        <label class="coa-search" style="flex:1 1 220px;min-width:190px;max-width:300px;width:auto;">⌕
          <input type="search" id="ar-q" placeholder="Donor, invoice number or grant reference">
        </label>
        <label class="ap-fund">Fund <select id="ar-fund"></select></label>
        <div class="jr-hint" id="ar-hint"></div>
      </div>
      <div class="ap-selbar" id="ar-selbar" hidden>
        <span style="font-size:12.5px;font-weight:600;" id="ar-sel-count"></span>
        <span style="font-size:12.5px;color:#9DB0A8;" id="ar-sel-total"></span>
        <div style="margin-inline-start:auto;display:flex;flex-wrap:wrap;align-items:center;gap:8px;">
          <button type="button" class="ap-selbar-btn" data-bulk="issue">Issue</button>
          <button type="button" class="ap-selbar-btn" data-bulk="remind">Send reminder</button>
          <button type="button" class="ap-selbar-btn go" data-bulk="receive">Record receipt in full</button>
          <button type="button" class="ap-selbar-btn quiet" data-bulk="clear">Clear</button>
        </div>
      </div>
      <div class="coa-card">
        <div style="overflow-x:auto;"><div style="min-width:1400px;" id="ar-table"></div></div>
        <div id="ar-pager"></div>
        <div class="coa-foot">
          <span id="ar-footer"></span>
          <span style="margin-inline-start:auto;">Issuing raises 1210 grants receivable and recognises income · receipts clear 1210 · doubtful debts provided for in 1215 against 5370 · write-offs use the allowance first</span>
        </div>
      </div>`;

    let searchTimer;
    app.querySelector('#ar-q').addEventListener('input', (e) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => { state.q = e.target.value; state.page = 1; refresh(); }, 200);
    });
    app.querySelector('#ar-fund').addEventListener('change', (e) => { state.fund = e.target.value; state.page = 1; refresh(); });
    app.querySelector('#ar-tabs').addEventListener('click', (e) => {
      const tab = e.target.closest('[data-status]');
      if (!tab) return;
      state.status = tab.dataset.status;
      state.page = 1;
      selected.clear();
      refresh();
    });
    app.querySelector('#ar-aging').addEventListener('click', (e) => {
      const b = e.target.closest('[data-age]');
      if (!b) return;
      state.age = b.dataset.age;
      state.page = 1;
      selected.clear();
      refresh();
    });
    app.querySelector('#ar-pager').addEventListener('click', (e) => {
      const b = e.target.closest('[data-page]');
      if (!b || b.disabled) return;
      state.page = +b.dataset.page;
      refresh();
    });

    const table = app.querySelector('#ar-table');
    table.addEventListener('change', (e) => {
      const box = e.target.closest('[data-select]');
      if (!box) return;
      const row = data.rows.find(r => r.no === box.dataset.select);
      if (box.checked) selected.set(row.no, row.outstanding);
      else selected.delete(row.no);
      renderSelection();
    });
    table.addEventListener('click', (e) => {
      if (e.target.closest('.ap-check')) return;
      const row = e.target.closest('[data-no]');
      if (row) open(row.dataset.no);
    });
    table.addEventListener('keydown', (e) => {
      const row = e.target.closest('[data-no]');
      if (row && e.target === row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); open(row.dataset.no); }
    });

    app.querySelector('#ar-allowance').addEventListener('click', async (e) => {
      const b = e.target.closest('[data-allow]');
      if (!b) return;
      const action = b.dataset.allow;
      if (action === 'edit') { editingRates = true; return renderAllowance(); }
      if (action === 'cancel') { editingRates = false; return renderAllowance(); }
      const buttons = app.querySelectorAll('#ar-allowance button');
      buttons.forEach(x => { x.disabled = true; });
      try {
        if (action === 'save') {
          const rates = {};
          app.querySelectorAll('#ar-allowance [data-rate]').forEach(input => { rates[input.dataset.rate] = input.value; });
          await UI.postJSON('/api/receivables/allowance/rates', { rates });
          editingRates = false;
          UI.toast('Ageing rates saved. Apply them to post the allowance they call for.');
        } else if (action === 'apply') {
          const d = await UI.postJSON('/api/receivables/allowance/apply-rates', {});
          UI.toast(`Allowance brought into line with the ageing rates on ${plural(d.done.length, 'claim', 'claims')}`
            + (d.raised ? ` — ${fmt(d.raised)} raised` : '') + (d.released ? `${d.raised ? ',' : ' —'} ${fmt(d.released)} released` : '') + '.');
        }
        await refresh();
      } catch (err) {
        UI.toast(err.message);
        renderAllowance();
      }
    });

    app.querySelector('#ar-selbar').addEventListener('click', (e) => {
      const b = e.target.closest('[data-bulk]');
      if (b) bulk(b.dataset.bulk);
    });
    app.querySelector('#ar-new').addEventListener('click', () => NewInvoice.open());
    app.querySelector('#ar-statement').addEventListener('click', () => {
      window.location.href = '/api/receivables/statement';
      UI.toast('Aged receivables statement exported for today’s position.');
    });
  }

  function render() {
    app.querySelector('#ar-stats').innerHTML = data.stats.map(s => `
      <div class="stat">
        <div class="stat-label">${esc(s.label)}</div>
        <div class="stat-value">${esc(s.value)}</div>
        <div class="stat-note">${esc(s.note)}</div>
      </div>`).join('');

    app.querySelector('#ar-aging').innerHTML = data.aging.map(a => `
      <button type="button" class="ap-bucket ${a.key === state.age ? 'on' : ''}" data-age="${esc(a.key)}">
        <span class="stat-label">${esc(a.label)}</span>
        <span class="ap-bucket-value">${esc(a.value)}</span>
        <span class="stat-note">${esc(a.count)}</span>
      </button>`).join('');

    app.querySelector('#ar-tabs').innerHTML = data.tabs.map(t => `
      <button type="button" class="coa-seg-btn ${t.label === state.status ? 'on' : ''}" data-status="${esc(t.label)}">${esc(t.label)}</button>`).join('');
    app.querySelector('#ar-fund').innerHTML = data.fundOptions.map(f => `<option ${f === state.fund ? 'selected' : ''}>${esc(f)}</option>`).join('');
    app.querySelector('#ar-hint').textContent = data.hint;
    app.querySelector('#ar-footer').textContent = data.footer;

    app.querySelector('#ar-table').innerHTML = `
      <div class="ar-grid coa-head">
        <div></div><div>Invoice</div><div>Donor</div><div>Issued</div><div>Due</div><div>Fund</div><div>Programme</div>
        <div style="text-align:end;">Invoiced</div><div style="text-align:end;">Received</div><div style="text-align:end;">Outstanding</div><div>Status</div><div style="text-align:end;">Age</div>
      </div>
      ${data.rows.map(i => `
        <div class="ar-grid ap-row" data-no="${esc(i.no)}" tabindex="0">
          <div class="ap-check"><input type="checkbox" data-select="${esc(i.no)}" aria-label="Select ${esc(i.no)}" ${selected.has(i.no) ? 'checked' : ''}></div>
          <div class="jr-ref">${esc(i.no)}</div>
          <div>
            <span class="coa-cell" style="display:block;font-size:12.5px;color:#28352F;">${esc(i.donor)}</span>
            <span class="ap-sub">${esc(i.subtitle)}</span>
          </div>
          <div class="jr-date" style="font-size:11px;color:#6E7873;">${esc(i.issue)}</div>
          <div class="jr-date" style="font-size:11px;color:#6E7873;">${esc(i.due)}</div>
          <div class="coa-cell">${esc(i.fund)}</div>
          <div class="coa-cell">${esc(i.program)}</div>
          <div class="coa-amount" style="color:#28352F;">${fmt(i.amount)}</div>
          <div class="coa-amount" style="font-size:11.5px;color:#8B948F;">${fmt(i.received)}</div>
          <div class="coa-amount" style="font-weight:600;color:#16211E;">${fmt(i.outstanding)}</div>
          <div>${pill(i.status)}</div>
          <div class="ap-age ${i.overdue ? 'late' : ''}">${esc(i.age)}</div>
        </div>`).join('')}
      ${data.rows.length === 0 ? '<div class="coa-empty">No invoices match this view.</div>' : ''}`;

    app.querySelector('#ar-pager').innerHTML = data.pages > 1 ? pager() : '';
    renderAllowance();
    renderSelection();
  }

  /** What 1215 holds against open claims, by ageing bucket, beside what the ageing rates call for. */
  function renderAllowance() {
    const a = data.allowance;
    const differs = a.buckets.some(b => b.held !== b.byRates);
    app.querySelector('#ar-allowance').innerHTML = `
      <div class="ar-allow-head">
        <div style="display:flex;flex-direction:column;gap:3px;min-width:0;">
          <span style="font-size:13px;font-weight:600;color:#16211E;">Allowance for doubtful debts <span style="font-family:'IBM Plex Mono',monospace;font-size:11px;font-weight:400;color:#7A857F;">1215</span></span>
          <span style="font-size:11.5px;color:#7A857F;">Held <b style="color:#16211E;font-weight:600;">${esc(a.held)}</b> · receivables net of the allowance <b style="color:#16211E;font-weight:600;">${esc(a.net)}</b>${a.specific ? ` · ${plural(a.specific, 'claim', 'claims')} set by hand, which the rates leave alone` : ''}</span>
        </div>
        ${a.canManage ? `<div style="margin-inline-start:auto;display:flex;flex-wrap:wrap;gap:8px;">
          ${editingRates
            ? '<button type="button" class="btn" data-allow="cancel">Cancel</button><button type="button" class="btn btn-primary" data-allow="save">Save rates</button>'
            : `<button type="button" class="btn" data-allow="edit">Edit rates</button><button type="button" class="btn ${differs ? 'btn-primary' : ''}" data-allow="apply" ${differs ? '' : 'disabled title="The allowance already matches the ageing rates"'}>Apply rates</button>`}
        </div>` : ''}
      </div>
      <div style="overflow-x:auto;">
        <table class="ar-allow-table">
          <thead><tr><th>Age past due</th><th>Rate</th><th>Outstanding</th><th>By the rates</th><th>Held</th></tr></thead>
          <tbody>${a.buckets.map(b => `
            <tr>
              <td>${esc(b.bucket === 'Current' ? 'Not yet due' : b.bucket)}</td>
              <td>${editingRates
                ? `<input class="mono" data-rate="${esc(b.bucket)}" value="${esc(b.pct)}" inputmode="decimal" aria-label="Rate for ${esc(b.bucket)}"> %`
                : esc(b.pct) + '%'}</td>
              <td>${esc(b.outstanding)}</td>
              <td>${esc(b.byRates)}</td>
              <td style="${b.held !== b.byRates ? 'color:#A45B3E;font-weight:600;' : ''}">${esc(b.held)}</td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>`;
  }

  function renderSelection() {
    app.querySelector('#ar-selbar').hidden = selected.size === 0;
    app.querySelector('#ar-sel-count').textContent = plural(selected.size, 'invoice', 'invoices') + ' selected';
    app.querySelector('#ar-sel-total').textContent = 'Outstanding ' + fmt([...selected.values()].reduce((a, b) => a + b, 0));
  }

  function pager() {
    const p = data.page;
    const buttons = [];
    for (let k = 1; k <= data.pages; k++) buttons.push(`<button type="button" class="coa-page ${k === p ? 'on' : ''}" data-page="${k}">${k}</button>`);
    return `
      <div class="coa-pager">
        <span>Showing ${(p - 1) * data.pageSize + 1}–${Math.min(data.filtered, p * data.pageSize)} of ${data.filtered} claims</span>
        <div style="margin-inline-start:auto;display:flex;align-items:center;gap:4px;">
          <button type="button" class="coa-page" data-page="${p - 1}" ${p <= 1 ? 'disabled' : ''}>‹</button>
          ${buttons.join('')}
          <button type="button" class="coa-page" data-page="${p + 1}" ${p >= data.pages ? 'disabled' : ''}>›</button>
        </div>
      </div>`;
  }

  // ---- Actions ----

  const messages = {
    issue: (d) => (d.done.length === 1
      ? `${d.done[0].no} issued to ${d.done[0].donor} and posted as ${d.done[0].journal} — ${fmt(d.done[0].amount)} recognised as income and raised on 1210 grants receivable.`
      : `${d.done.length} claims issued to donors and posted to the ledger.`) + skippedNote(d),
    remind: (d) => (d.done.length === 1
      ? `Reminder sent to ${d.done[0].donor} with the invoice and ledger extract attached.`
      : `Reminders sent for ${d.done.length} invoices.`) + skippedNote(d),
    receive: (d) => (d.done.length === 1
      ? `Receipt in full recorded against ${d.done[0].no} and cleared from grants receivable.`
      : `Receipts recorded in full for ${d.done.length} invoices.`) + skippedNote(d),
  };

  async function bulk(action) {
    if (action === 'clear') {
      selected.clear();
      render();
      return;
    }
    const buttons = app.querySelectorAll('#ar-selbar button');
    buttons.forEach(b => { b.disabled = true; });
    try {
      const d = await UI.postJSON('/api/receivables/' + action, { nos: [...selected.keys()] });
      selected.clear();
      UI.toast(messages[action](d));
      await refresh();
    } catch (err) {
      UI.toast(err.message);
    } finally {
      buttons.forEach(b => { b.disabled = false; });
    }
  }

  /** Opens an invoice in the drawer, keeping its address in the location bar while it is open. */
  function open(no) {
    history.replaceState(null, '', '/receivables/' + encodeURIComponent(no));
    Invoice.open(no);
  }

  // ---- Invoice drawer ----

  const Invoice = (() => {
    let el;
    let current = null; // { invoice, can, receiptAccounts }
    let writingOff = false;
    let settingAllowance = false;

    function build() {
      el = document.createElement('div');
      el.className = 'rt';
      el.hidden = true;
      el.innerHTML = `
        <div class="jd-backdrop" data-ar-close></div>
        <div class="rt-panel" style="width:520px;" role="dialog" aria-modal="true" aria-label="Invoice">
          <div class="rt-head" style="padding:16px 20px;" id="ard-head"></div>
          <div class="rt-body" style="padding:18px 20px;gap:20px;" id="ard-body"></div>
          <div class="rt-foot" style="padding:13px 20px;flex-wrap:wrap;background:#FBFAF7;" id="ard-foot"></div>
        </div>`;
      document.body.appendChild(el);

      el.addEventListener('click', (e) => {
        if (e.target.closest('[data-ar-close]')) return close();
        const journal = e.target.closest('[data-journal]');
        if (journal) return UI.openJournal(journal.dataset.journal);
        const act = e.target.closest('[data-act]');
        if (!act) return;
        const i = current.invoice;
        switch (act.dataset.act) {
          case 'issue':
            return run(() => UI.postJSON('/api/receivables/issue', { nos: [i.no] }), messages.issue);
          case 'remind':
            return run(() => UI.postJSON('/api/receivables/remind', { nos: [i.no] }), messages.remind);
          case 'receipt': {
            const amount = el.querySelector('#ard-amount').value;
            const account = el.querySelector('#ard-account').value;
            const ref = el.querySelector('#ard-ref').value.trim();
            return run(() => UI.postJSON(`/api/receivables/${encodeURIComponent(i.no)}/receipt`, { amount, account, ref }), (d) => {
              const full = d.invoice.status === 'Received';
              return `${full ? 'Receipt in full' : 'Part receipt'} recorded against ${i.no} and posted to ${account}${full ? ' — settled.' : ` — ${fmt(d.invoice.outstanding)} still outstanding.`}`;
            });
          }
          case 'recovery': {
            const amount = el.querySelector('#ard-amount').value;
            const account = el.querySelector('#ard-account').value;
            const ref = el.querySelector('#ard-ref').value.trim();
            return run(() => UI.postJSON(`/api/receivables/${encodeURIComponent(i.no)}/recovery`, { amount, account, ref }), (d) => {
              const recovered = i.outstanding - d.invoice.outstanding;
              return `${fmt(recovered)} recovered on ${i.no} and posted to ${account} — the write-off is reversed and ${fmt(recovered)} credited back to bad and doubtful debts (5370)${d.invoice.outstanding ? `; ${fmt(d.invoice.outstanding)} stays written off.` : '.'}`;
            });
          }
          case 'set-allowance':
            settingAllowance = true;
            renderFoot();
            el.querySelector('#ard-allowance').select();
            return;
          case 'cancel-allowance':
            settingAllowance = false;
            return renderFoot();
          case 'confirm-allowance': {
            const amount = el.querySelector('#ard-allowance').value.trim();
            const reason = el.querySelector('#ard-reason').value.trim();
            return run(() => UI.postJSON(`/api/receivables/${encodeURIComponent(i.no)}/allowance`, { amount, reason }),
              (d) => `Allowance on ${i.no} ${d.invoice.allowance > i.allowance ? 'raised' : 'released'} to ${fmt(d.invoice.allowance)} — ${fmt(Math.abs(d.invoice.allowance - i.allowance))} ${d.invoice.allowance > i.allowance ? 'charged to' : 'credited back to'} bad and doubtful debts (5370).`);
          }
          case 'write-off':
            writingOff = true;
            renderFoot();
            el.querySelector('#ard-reason').focus();
            return;
          case 'cancel-write-off':
            writingOff = false;
            return renderFoot();
          case 'confirm-write-off': {
            const reason = el.querySelector('#ard-reason').value.trim();
            if (!reason) return UI.toast('Say why the claim will not be paid — a write-off needs its reason on record.');
            return run(() => UI.postJSON(`/api/receivables/${encodeURIComponent(i.no)}/write-off`, { reason }),
              () => `${i.no} written off and cleared from grants receivable — ${writeOffSplit(i)}.`);
          }
        }
      });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !el.hidden && !document.querySelector('.jd:not([hidden])')) close(); });
    }

    /** How a write-off of what is outstanding splits between the allowance and a fresh charge. */
    function writeOffSplit(i) {
      const used = Math.min(i.allowance, i.outstanding);
      const charged = i.outstanding - used;
      return [used ? `${fmt(used)} met from the allowance (1215)` : '', charged || !used ? `${fmt(charged)} charged to bad and doubtful debts (5370)` : '']
        .filter(Boolean).join(' and ');
    }

    async function load(no) {
      current = await UI.fetchJSON('/api/receivables/' + encodeURIComponent(no));
      writingOff = false;
      settingAllowance = false;
      render();
    }

    async function run(request, message) {
      const controls = el.querySelectorAll('#ard-foot button, #ard-body button, #ard-body input, #ard-body select');
      controls.forEach(b => { b.disabled = true; });
      try {
        const d = await request();
        UI.toast(message(d));
        await load(current.invoice.no);
        refresh();
      } catch (err) {
        UI.toast(err.message);
        render();
      }
    }

    function render() {
      const i = current.invoice;
      const can = current.can;
      el.querySelector('#ard-head').innerHTML = `
        <div style="display:flex;flex-direction:column;gap:4px;min-width:0;">
          <div style="display:flex;align-items:center;gap:9px;">
            <span class="jr-ref" style="font-size:12px;">${esc(i.no)}</span>${pill(i.overdue ? 'Overdue' : i.status)}
          </div>
          <div style="font-size:15.5px;font-weight:600;letter-spacing:-.01em;">${esc(i.donor)}</div>
          <div style="font-size:11.5px;color:#7A857F;">${esc(i.grantRef)} · ${esc(i.type)} · ${esc(i.program)}</div>
        </div>
        <button type="button" class="rt-close" data-ar-close aria-label="Close">×</button>`;

      const row = (label, value) => `<div class="ar-kv"><span>${esc(label)}</span><span>${value}</span></div>`;
      const accounts = current.receiptAccounts;
      const defaultAccount = (accounts.find(a => a.currency === i.ccy && a.label.startsWith('Bank')) || accounts.find(a => a.currency === 'KES') || {}).code;

      el.querySelector('#ard-body').innerHTML = `
        <div class="ar-figures">
          <div><span class="jd-caps">Invoiced</span><span>${fmt(i.amount)}</span></div>
          <div><span class="jd-caps">Received</span><span style="color:#2C6B58;">${fmt(i.received)}</span></div>
          <div><span class="jd-caps">Outstanding</span><span style="font-weight:600;">${fmt(i.outstanding)}</span></div>
        </div>
        <div class="ar-kvs">
          ${row('Issued', esc(i.issue))}${row('Due', esc(i.due) + (i.overdue ? ` <span style="color:#A45B3E;">· ${-i.dueIn} days late</span>` : ''))}${row('Fund', esc(i.fund))}
          ${i.fx ? row('Currency', `${esc(i.ccy)} ${Number(i.amountFc).toLocaleString('en-US', { minimumFractionDigits: 2 })} at ${Number(i.fx).toFixed(2)} · carried in KES at the claim rate`) : ''}
          ${row('Basis', esc(i.basis))}
          ${i.journal ? row('Ledger', `Posted as <button type="button" class="ap-link" data-journal="${esc(i.journal)}">${esc(i.journal)}</button>`) : ''}
          ${i.allowance || i.allowanceBasis ? row('Allowance', `${fmt(i.allowance)} held in 1215${i.allowanceBasis ? ' · ' + esc(i.allowanceBasis.toLowerCase()) : ''}`) : ''}
          ${i.status === 'Written off' && i.writeOffReason ? row('Written off', esc(i.writeOffReason)) : ''}
        </div>
        <div style="display:flex;flex-direction:column;gap:9px;">
          <div class="jd-caps">Claim lines</div>
          <div style="border:1px solid #E4E2DB;border-radius:8px;overflow:hidden;">
            ${i.lines.map(l => `
              <div class="ar-line">
                <span style="font-family:'IBM Plex Mono',monospace;font-size:11px;color:#6E7873;">${esc(l.code)}</span>
                <span style="font-size:12px;color:#28352F;line-height:1.45;">${esc(l.desc)}</span>
                <span class="coa-amount" style="color:#16211E;">${fmt(l.amount)}</span>
              </div>`).join('')}
          </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:9px;">
          <div class="jd-caps">Receipts</div>
          ${i.receipts.length ? i.receipts.map(r => `
            <div class="ar-receipt">
              <span style="font-family:'IBM Plex Mono',monospace;font-size:11px;color:#6E7873;">${esc(r.when)}</span>
              <span style="display:flex;flex-direction:column;gap:2px;min-width:0;">
                <span style="font-size:12px;color:#28352F;">${esc(r.note)}</span>
                <span style="font-family:'IBM Plex Mono',monospace;font-size:10.5px;color:#9AA39E;">${esc(r.ref)} · ${esc(r.account)}${r.journal ? ` · <button type="button" class="ap-link" style="font-size:10.5px;" data-journal="${esc(r.journal)}">${esc(r.journal)}</button>` : ''}</span>
              </span>
              <span class="coa-amount" style="font-weight:600;color:#2C6B58;">${fmt(r.amount)}</span>
            </div>`).join('') : '<div class="ar-empty">Nothing received against this invoice yet.</div>'}
          ${can.receive ? `
            <div class="ar-receive">
              <label class="ap-f" style="flex:1 1 140px;"><span>Receipt amount (KES)</span><input class="mono" id="ard-amount" inputmode="numeric" value="${i.outstanding}"></label>
              <label class="ap-f" style="flex:1 1 170px;"><span>Banked in</span>
                <select id="ard-account">${accounts.map(a => `<option value="${esc(a.code)}" ${a.code === defaultAccount ? 'selected' : ''}>${esc(a.code)} · ${esc(a.label)}</option>`).join('')}</select>
              </label>
              <label class="ap-f" style="flex:1 1 140px;"><span>Bank reference</span><input class="mono" id="ard-ref" placeholder="Optional"></label>
              <button type="button" class="btn btn-primary" data-act="receipt" style="height:34px;">Record receipt</button>
            </div>` : ''}
          ${can.recover ? `
            <span class="jd-sod" style="max-width:none;">Money in on a written-off claim reverses the write-off for what came in: 1210 is reinstated against the allowance (1215), the receipt clears it, and the allowance is released to bad and doubtful debts (5370).</span>
            <div class="ar-receive">
              <label class="ap-f" style="flex:1 1 140px;"><span>Amount recovered (KES)</span><input class="mono" id="ard-amount" inputmode="numeric" value="${i.outstanding}"></label>
              <label class="ap-f" style="flex:1 1 170px;"><span>Banked in</span>
                <select id="ard-account">${accounts.map(a => `<option value="${esc(a.code)}" ${a.code === defaultAccount ? 'selected' : ''}>${esc(a.code)} · ${esc(a.label)}</option>`).join('')}</select>
              </label>
              <label class="ap-f" style="flex:1 1 140px;"><span>Bank reference</span><input class="mono" id="ard-ref" placeholder="Optional"></label>
              <button type="button" class="btn btn-primary" data-act="recovery" style="height:34px;">Record recovery</button>
            </div>` : ''}
        </div>
        <div style="display:flex;flex-direction:column;gap:9px;">
          <div class="jd-caps">Audit trail</div>
          ${i.trail.map(t => `
            <div style="display:flex;gap:12px;font-size:12px;color:#3E4A44;">
              <span style="font-family:'IBM Plex Mono',monospace;font-size:11px;color:#9AA39E;width:58px;flex:0 0 58px;">${esc(t.when)}</span>
              <span style="line-height:1.5;">${esc(t.what)}</span>
            </div>`).join('')}
        </div>`;

      renderFoot();
    }

    function renderFoot() {
      const can = current.can;
      const foot = el.querySelector('#ard-foot');
      if (settingAllowance) {
        const i = current.invoice;
        foot.innerHTML = `
          <span class="jd-sod" style="max-width:none;flex:1 1 100%;">How much of the ${fmt(i.outstanding)} outstanding may not come in. The change posts between 1215 and bad and doubtful debts (5370); the ageing rates${i.byRates ? ` (${fmt(i.byRates)} on this claim)` : ''} leave it alone from then on.</span>
          <label class="ap-f" style="flex:0 0 140px;"><span>Allowance (KES)</span><input class="mono" id="ard-allowance" inputmode="numeric" value="${i.allowance || i.byRates || i.outstanding}"></label>
          <input class="jd-reason" id="ard-reason" placeholder="Why the claim is in doubt" style="align-self:flex-end;height:34px;">
          <button type="button" class="btn" data-act="cancel-allowance" style="align-self:flex-end;">Cancel</button>
          <button type="button" class="btn btn-primary" data-act="confirm-allowance" style="align-self:flex-end;">Save allowance</button>`;
        return;
      }
      if (writingOff) {
        foot.innerHTML = `
          <span class="jd-sod" style="max-width:none;flex:1 1 100%;">Writes off ${fmt(current.invoice.outstanding)} — ${writeOffSplit(current.invoice)}. The reason goes on the claim's record.</span>
          <input class="jd-reason" id="ard-reason" placeholder="Why the donor will not pay">
          <button type="button" class="btn" data-act="cancel-write-off">Cancel</button>
          <button type="button" class="btn jd-quiet" style="color:#A6412F;border-color:#E4C7BF;" data-act="confirm-write-off">Write off</button>`;
        return;
      }
      foot.innerHTML = `
        ${can.issue ? '<button type="button" class="btn btn-primary" data-act="issue">Issue to donor</button>' : ''}
        ${can.remind ? '<button type="button" class="btn" data-act="remind">Send reminder</button>' : ''}
        ${can.allowance ? `<button type="button" class="btn" data-act="set-allowance">${current.invoice.allowance ? 'Change allowance' : 'Set allowance'}</button>` : ''}
        ${can.writeOff ? '<button type="button" class="btn" style="color:#A6412F;border-color:#E4C7BF;" data-act="write-off">Write off</button>' : ''}
        ${can.note ? `<span class="jd-sod">${esc(can.note)}</span>` : ''}
        <button type="button" class="btn" data-ar-close style="margin-inline-start:auto;">Close</button>`;
    }

    async function openDrawer(no) {
      if (!el) build();
      try {
        await load(no);
      } catch (err) {
        UI.toast(`Invoice ${no} could not be loaded.`);
        history.replaceState(null, '', '/receivables');
        return;
      }
      el.hidden = false;
    }

    function close() {
      if (el) el.hidden = true;
      history.replaceState(null, '', '/receivables');
    }

    return { open: openDrawer };
  })();

  // ---- New donor invoice (claim builder) ----

  const NewInvoice = (() => {
    let el;
    let form = null; // /api/receivables/form
    let f = null;
    let claims = null; // budget line code → text entered
    let saving = false;

    const num = (v) => parseFloat(String(v || '').replace(/[^0-9.]/g, '')) || 0;

    function build() {
      el = document.createElement('div');
      el.className = 'ap-modal';
      el.hidden = true;
      el.innerHTML = `
        <div class="ap-modal-scrim" data-an-close></div>
        <div class="ap-modal-box" style="width:860px;" role="dialog" aria-modal="true" aria-label="New donor invoice">
          <div class="jd-head" style="padding:16px 20px;border-bottom-color:#E4E2DB;">
            <div style="display:flex;flex-direction:column;gap:3px;">
              <div class="jd-caps" style="letter-spacing:.1em;">New donor invoice</div>
              <div style="font-size:16px;font-weight:600;letter-spacing:-.01em;">Build a claim from expenditure already in the ledger</div>
            </div>
            <button type="button" class="rt-close" data-an-close aria-label="Close">×</button>
          </div>
          <div class="ap-modal-body" id="an-body"></div>
          <div class="ap-modal-foot">
            <div style="min-width:0;flex:1;font-size:12px;line-height:1.5;" id="an-status"></div>
            <button type="button" class="btn" data-an-close style="height:34px;padding:0 14px;">Cancel</button>
            <button type="button" class="btn btn-primary" id="an-create" style="height:34px;padding:0 18px;">Build draft claim</button>
          </div>
        </div>`;
      document.body.appendChild(el);

      el.addEventListener('click', (e) => {
        if (e.target.closest('[data-an-close]')) return close();
        const all = e.target.closest('[data-an-all]');
        if (all) {
          const line = award().lines.find(l => l.code === all.dataset.anAll);
          claims[line.code] = String(line.actual);
          return renderBody();
        }
        if (e.target.closest('[data-an-claim-all]')) {
          award().lines.forEach(l => { claims[l.code] = String(l.actual); });
          return renderBody();
        }
        if (e.target.closest('[data-an-clear]')) {
          claims = {};
          return renderBody();
        }
        if (e.target.closest('[data-an-indirect]')) {
          f.indirect = !f.indirect;
          return renderBody();
        }
        if (e.target.closest('#an-create')) create();
      });
      el.addEventListener('input', (e) => {
        const t = e.target;
        if (t.dataset.anClaim !== undefined) claims[t.dataset.anClaim] = t.value;
        else if (t.dataset.an && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA')) f[t.dataset.an] = t.value;
        else return;
        renderDerived();
      });
      el.addEventListener('change', (e) => {
        const t = e.target;
        if (!t.dataset.an || t.tagName !== 'SELECT') return;
        f[t.dataset.an] = t.value;
        if (t.dataset.an === 'award') {
          f.type = t.value === '—' ? 'Other income' : 'Grant claim';
          claims = {};
        }
        if (t.dataset.an === 'ccy') f.fx = String(form.currencies[t.value]);
        renderBody();
      });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !el.hidden) close(); });
    }

    const award = () => form.awards.find(a => a.ref === f.award) || null;

    /** The claim as entered, and the first thing that would stop it being built. */
    function derive() {
      const g = award();
      const other = !g;
      const rows = g ? g.lines.map(l => ({ ...l, claim: num(claims[l.code]), over: num(claims[l.code]) > l.actual })) : [];
      const direct = rows.reduce((a, r) => a + r.claim, 0);
      const raw = g && g.indirect && f.indirect ? Math.round(direct * g.indirect.pct / 100) : 0;
      const indirect = g && g.indirect ? Math.min(raw, g.indirect.cap) : 0;
      const total = other ? num(f.amount) : direct + indirect;
      const fx = f.ccy === 'KES' ? 1 : num(f.fx);

      const err = !f.period.trim() ? 'Name the period the claim covers, e.g. Jul – Sep 2026.'
        : other && !f.payer.trim() ? 'Name who the invoice is addressed to.'
        : other && !f.basis.trim() ? 'Describe what is being invoiced — an invoice with no award behind it needs a basis on its face.'
        : !total ? (other ? 'Enter the amount being invoiced.' : 'Claim at least one line. The builder only lets you claim expenditure already in the ledger.')
        : rows.some(r => r.over) ? 'One or more lines claim more than has been spent. A claim above actual expenditure is what triggers a donor disallowance.'
        : f.ccy !== 'KES' && !(fx > 0) ? 'Enter the exchange rate used for the claim.'
        : g && total > g.unclaimed ? `Only ${fmt(g.unclaimed)} of expenditure is unclaimed on this award — ${fmt(g.spent)} spent against ${fmt(g.claimed)} already claimed. Claiming ${fmt(total)} would invoice the same costs twice.`
        : g && total > g.left ? `This claim of ${fmt(total)} takes total claims past the award value — only ${fmt(g.left)} is left unclaimed on ${g.ref}.`
        : '';

      return { g, other, rows, direct, indirect, capped: raw > indirect, total, fx, err,
        fcText: f.ccy === 'KES' || !(fx > 0) ? '' : `${f.ccy} ${(Math.round(total / fx * 100) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` };
    }

    function renderBody() {
      const d = derive();
      const option = (value, label, cur) => `<option value="${esc(value)}" ${String(value) === String(cur) ? 'selected' : ''}>${esc(label)}</option>`;
      el.querySelector('#an-body').innerHTML = `
        <div class="ap-cols" style="display:grid;grid-template-columns:minmax(0,1fr) 190px 170px;gap:14px;">
          <label class="ap-f"><span>Award being claimed against</span>
            <select data-an="award">
              ${form.awards.map(a => option(a.ref, `${a.funder} · ${a.ref}`, f.award)).join('')}
              ${option('—', 'No award — other income or county MoU', f.award)}
            </select>
          </label>
          <label class="ap-f"><span>Claim type</span>
            <select data-an="type">${(d.other ? ['Other income'] : ['Grant claim', 'Cost reimbursement']).map(t => option(t, t, f.type)).join('')}</select>
          </label>
          <label class="ap-f"><span>Period claimed</span><input data-an="period" value="${esc(f.period)}" placeholder="Jul – Sep 2026"></label>
        </div>
        ${d.g ? `
          <div class="ar-award">
            <div style="grid-column:1/-1;"><span class="jd-caps">Agreement</span><span style="font-size:12.5px;">${esc(d.g.title)}</span></div>
            <div><span class="jd-caps">Award value</span><span class="pc-mono">${fmt(d.g.value)}</span></div>
            <div><span class="jd-caps">Claimed to date</span><span class="pc-mono">${fmt(d.g.claimed)}</span></div>
            <div><span class="jd-caps">Left on the award</span><span class="pc-mono">${fmt(d.g.left)}</span></div>
            <div><span class="jd-caps">Unclaimed expenditure</span><span class="pc-mono" style="color:#0F5C4A;font-weight:600;">${fmt(d.g.unclaimed)}</span></div>
          </div>
          <div class="ap-lines">
            <div class="ar-claim" style="background:#FAF9F6;font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:#8B948F;">
              <div>Budget line</div><div style="text-align:end;">Budget</div><div style="text-align:end;">Spent</div><div style="text-align:end;">Claim now</div><div></div>
            </div>
            ${d.rows.map(r => `
              <div class="ar-claim">
                <div style="display:flex;flex-direction:column;gap:1px;min-width:0;">
                  <span class="coa-cell" style="font-size:12.5px;color:#16211E;">${esc(r.name)}</span>
                  <span style="font-size:10.5px;color:#9AA39E;font-family:'IBM Plex Mono',monospace;">${esc(r.code)}<span data-over="${esc(r.code)}" style="color:#A6412F;">${r.over ? ' · above expenditure incurred' : ''}</span></span>
                </div>
                <div class="coa-amount" style="font-size:11.5px;">${fmt(r.budget)}</div>
                <div class="coa-amount" style="font-size:11.5px;">${fmt(r.actual)}</div>
                <div><input class="ar-claim-input" data-an-claim="${esc(r.code)}" value="${esc(claims[r.code] || '')}" inputmode="numeric" placeholder="0"></div>
                <div><button type="button" class="rt-back" data-an-all="${esc(r.code)}" ${r.actual > 0 ? '' : 'disabled'}>All</button></div>
              </div>`).join('')}
            <div style="display:flex;align-items:center;gap:12px;padding:9px 12px;">
              <button type="button" class="jd-dashed" data-an-claim-all>Claim all expenditure</button>
              <button type="button" class="rt-back" data-an-clear>Clear</button>
              <span style="margin-inline-start:auto;font-size:11.5px;color:#7A857F;">Direct costs claimed <span class="pc-mono" style="color:#16211E;font-weight:600;" id="an-direct"></span></span>
            </div>
          </div>
          ${d.g.indirect ? `
            <div class="ar-indirect">
              <button type="button" class="btn ${f.indirect ? 'btn-primary' : ''}" data-an-indirect style="white-space:nowrap;">${f.indirect ? 'Included ✓' : 'Not claimed'}</button>
              <div style="display:flex;flex-direction:column;gap:3px;min-width:0;flex:1;">
                <span style="font-size:12.5px;color:#16211E;">${esc(d.g.indirect.name)} — capped at ${fmt(d.g.indirect.cap)}</span>
                <span style="font-size:11px;color:#7A857F;">Recovery is calculated on the direct costs claimed above, not on the budget — claiming less direct cost recovers less overhead.</span>
                <span id="an-capped" style="font-size:11px;color:#8A5B2E;"></span>
              </div>
              <span class="pc-mono" style="font-weight:600;" id="an-indirect"></span>
            </div>` : ''}` : `
          <div class="ap-cols" style="display:grid;grid-template-columns:minmax(0,1fr) 220px;gap:14px;">
            <label class="ap-f"><span>Invoice to</span><input data-an="payer" value="${esc(f.payer)}" placeholder="e.g. County Government of Kisumu"></label>
            <label class="ap-f"><span>Income account</span>
              <select data-an="account">${form.otherAccounts.map(a => option(a.code, `${a.code} · ${a.name}`, f.account)).join('')}</select>
            </label>
          </div>
          <div class="ap-cols" style="display:grid;grid-template-columns:minmax(0,1fr) 220px;gap:14px;align-items:start;">
            <label class="ap-f"><span>What is being invoiced</span>
              <textarea data-an="basis" rows="2" class="ar-textarea" placeholder="e.g. Technical support under the Kisumu county MoU — register verification, September">${esc(f.basis)}</textarea>
              <span class="ap-note" style="font-weight:400;">Income with no award behind it is coded to general fund income, outside the fund restriction.</span>
            </label>
            <label class="ap-f"><span>Amount (KES)</span><input class="mono" data-an="amount" value="${esc(f.amount)}" inputmode="numeric" placeholder="0"></label>
          </div>`}
        <div class="ap-cols" style="display:grid;grid-template-columns:120px 140px minmax(0,1fr);gap:14px;align-items:end;">
          <label class="ap-f"><span>Invoice currency</span>
            <select data-an="ccy">${Object.keys(form.currencies).map(c => option(c, c, f.ccy)).join('')}</select>
          </label>
          ${f.ccy !== 'KES' ? `<label class="ap-f"><span>Rate used</span><input class="mono" data-an="fx" value="${esc(f.fx)}" inputmode="decimal"></label>` : '<div></div>'}
          <div class="ap-note" style="padding-bottom:4px;">The claim is presented to the donor in <strong style="color:#16211E;">${esc(f.ccy)}</strong> and carried in the ledger in shillings at the rate on the claim. Any movement by the time cash lands is an exchange difference, not a shortfall in the claim.</div>
        </div>
        <div class="ar-total">
          <span class="jd-caps" style="color:#2C6B58;">Total claim</span>
          <span id="an-total" class="pc-mono" style="font-size:16px;font-weight:600;color:#0F5C4A;"></span>
          <span id="an-fc" style="font-size:11.5px;color:#7A857F;"></span>
          <span style="margin-inline-start:auto;font-size:11.5px;color:#7A857F;">Due ${esc(form.due)} · 30 days after issue</span>
        </div>`;
      renderDerived();
    }

    function renderDerived() {
      const d = derive();
      const $ = (id) => el.querySelector('#' + id);
      d.rows.forEach(r => {
        const flag = el.querySelector(`[data-over="${CSS.escape(r.code)}"]`);
        if (flag) flag.textContent = r.over ? ' · above expenditure incurred' : '';
      });
      if ($('an-direct')) $('an-direct').textContent = fmt(d.direct);
      if ($('an-indirect')) $('an-indirect').textContent = fmt(d.indirect);
      if ($('an-capped')) $('an-capped').textContent = d.capped ? 'Capped at the agreement ceiling — the excess cannot be claimed here and stays a cost to unrestricted funds.' : '';
      $('an-total').textContent = fmt(d.total);
      $('an-fc').textContent = d.fcText ? 'presented as ' + d.fcText : '';
      $('an-status').innerHTML = d.err
        ? `<span style="color:#A6412F;">${esc(d.err)}</span>`
        : '<span style="color:#8B948F;">Saved as a draft. Issuing it to the donor is what raises the receivable and recognises the income.</span>';
    }

    async function create() {
      const d = derive();
      if (d.err) return UI.toast(d.err);
      if (saving) return;
      saving = true;
      el.querySelector('#an-create').disabled = true;
      try {
        const res = await UI.postJSON('/api/receivables', { ...f, award: d.g ? d.g.ref : '—', claims });
        const inv = res.invoice;
        close();
        UI.toast(`${inv.no} built as a draft ${d.other ? 'invoice' : 'claim'} of ${fmt(inv.amount)}${d.indirect ? ` including ${fmt(d.indirect)} of indirect recovery` : ''}. Nothing hits 1210 until it is issued to the donor.`);
        state.status = 'All'; state.age = 'All'; state.fund = 'All funds'; state.q = ''; state.page = 1;
        app.querySelector('#ar-q').value = '';
        await refresh();
        open(inv.no);
      } catch (err) {
        el.querySelector('#an-status').innerHTML = `<span style="color:#A6412F;">${esc(err.message)}</span>`;
      } finally {
        saving = false;
        el.querySelector('#an-create').disabled = false;
      }
    }

    async function openModal() {
      if (!el) build();
      try {
        form = await UI.fetchJSON('/api/receivables/form');
      } catch (err) {
        UI.toast('The claim builder could not be loaded.');
        return;
      }
      f = {
        award: (form.awards[0] || { ref: '—' }).ref, type: form.awards.length ? 'Grant claim' : 'Other income', period: 'Jul – Sep 2026',
        ccy: 'KES', fx: '1', indirect: true, basis: '', payer: '', amount: '', account: (form.otherAccounts.find(a => a.code === '4220') || form.otherAccounts[0] || {}).code || '',
      };
      claims = {};
      renderBody();
      el.hidden = false;
    }

    function close() {
      if (el) el.hidden = true;
    }

    return { open: openModal };
  })();

  shell();
  await refresh();

  const openNo = app.getAttribute('data-open');
  if (openNo) open(openNo);
})();
