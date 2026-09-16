(async function () {
  const app = document.getElementById('app');
  let state = { status: 'All', age: 'All', fund: 'All funds', q: '' };

  async function load() {
    const p = new URLSearchParams(state);
    return UI.fetchJSON('/api/receivables?' + p.toString());
  }

  function render(data) {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Accounting',
      title: 'Receivables',
      blurb: 'Grant tranches and reimbursable claims due to ELOG, from draft through receipt.',
    });
    app.appendChild(UI.statGrid(data.stats));

    const card = document.createElement('div');
    card.className = 'card';

    const toolbar = document.createElement('div');
    toolbar.className = 'toolbar';
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Search donor, award or claim…';
    search.value = state.q;
    search.addEventListener('input', (e) => { state.q = e.target.value; refresh(); });

    const fund = document.createElement('select');
    fund.innerHTML = data.fundOptions.map(f => `<option ${f === state.fund ? 'selected' : ''}>${UI.esc(f)}</option>`).join('');
    fund.addEventListener('change', (e) => { state.fund = e.target.value; refresh(); });

    // "All" clears the ageing filter rather than being a bucket of its own.
    const age = document.createElement('select');
    age.innerHTML = ['All'].concat(data.aging.map(a => a.label))
      .map(a => `<option ${a === state.age ? 'selected' : ''}>${UI.esc(a)}</option>`).join('');
    age.addEventListener('change', (e) => { state.age = e.target.value; refresh(); });

    toolbar.append(search, fund, age);
    card.appendChild(toolbar);

    card.appendChild(UI.tabs(data.tabs, state.status, (label) => { state.status = label; refresh(); }));

    const cols = [
      { label: 'Claim', key: 'no' },
      { label: 'Donor', render: (r) => `${UI.esc(r.donor)}<div class="muted" style="font-size:11px;">${UI.esc(r.subtitle)}</div>` },
      { label: 'Programme', key: 'program' },
      { label: 'Fund', key: 'fund' },
      { label: 'Due', render: (r) => `${UI.esc(r.due)}<div class="muted" style="font-size:11px;">${UI.esc(r.age)}</div>` },
      {
        label: 'Status',
        render: (r) => UI.badge(r.overdue ? 'Overdue' : r.status,
          r.status === 'Received' ? 'calm'
            : r.overdue ? 'urgent'
              : r.status === 'Written off' ? 'plain' : 'warn'),
      },
      { label: 'Claimed', num: true, key: 'amount' },
      { label: 'Received', num: true, key: 'received' },
      { label: 'Outstanding', num: true, key: 'outstanding' },
    ];

    if (data.rows.length) {
      card.appendChild(UI.table(cols, data.rows, (r) => openClaim(r.no)));
    } else {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = 'No claims match your filters.';
      card.appendChild(empty);
    }

    const footer = document.createElement('div');
    footer.className = 'card-footer';
    footer.textContent = `${data.rows.length} of ${data.total} claims`;
    card.appendChild(footer);
    app.appendChild(card);

    // Ageing runs on the outstanding balance, not the claim value — a grant
    // tranche can be part received and only the remainder is still owed.
    const aging = document.createElement('div');
    aging.className = 'card';
    aging.innerHTML = `<div class="card-head"><span class="card-title">Ageing on outstanding balance</span></div>`;
    const body = document.createElement('div');
    body.style.cssText = 'display:grid;grid-template-columns:repeat(5,1fr);gap:1px;background:#EEEDE8;';
    data.aging.forEach(b => {
      const cell = document.createElement('div');
      cell.style.cssText = 'background:#fff;padding:12px 16px;cursor:pointer;';
      cell.innerHTML = `<div class="muted" style="font-size:11px;">${UI.esc(b.label)}</div>`
        + `<div style="font-family:'IBM Plex Mono',monospace;font-weight:600;margin-top:4px;">${UI.esc(b.value)}</div>`
        + `<div class="muted" style="font-size:11px;">${b.count} ${b.count === 1 ? 'claim' : 'claims'}</div>`;
      cell.addEventListener('click', () => { state.age = b.label; refresh(); });
      body.appendChild(cell);
    });
    aging.appendChild(body);
    app.appendChild(aging);
  }

  async function openClaim(no) {
    const c = await UI.fetchJSON('/api/receivables/' + encodeURIComponent(no));
    const owed = c.outstanding;

    const rows = [
      ['Donor', c.donor], ['Award', c.grantRef], ['Type', c.type],
      ['Programme', c.program], ['Fund', c.fund],
      ['Issued', c.issue], ['Due', c.due], ['Status', c.status],
      ['Claimed', UI.fmtMoney(c.amount)], ['Received', UI.fmtMoney(c.received)],
      ['Outstanding', UI.fmtMoney(owed)],
    ];
    if (c.fx) rows.splice(3, 0, ['Billed in', `${c.ccy} ${c.amountFc.toLocaleString()} at ${c.fx}`]);

    const lines = c.lines.map(l => `<div class="detail-row"><span class="k">${UI.esc(l.code)} · ${UI.esc(l.desc)}</span><span class="v">${UI.fmtMoney(l.amount)}</span></div>`).join('');
    const receipts = c.receipts.length
      ? c.receipts.map(r => `<div class="detail-row"><span class="k">${UI.esc(r.when)} · ${UI.esc(r.ref)}<div class="muted" style="font-size:11px;">${UI.esc(r.note)}</div></span><span class="v">${UI.fmtMoney(r.amount)}</span></div>`).join('')
      : '<div class="empty-state" style="padding:16px;">Nothing received against this claim yet.</div>';
    const trail = c.trail.map(t => `<div class="detail-row"><span class="k">${UI.esc(t.when)}</span><span class="v" style="font-weight:400;text-align:left;">${UI.esc(t.what)}</span></div>`).join('');

    // A receipt is only offered where one can actually be posted: a draft has
    // not reached the donor, and a closed claim has nothing left to bank.
    const canReceipt = owed > 0 && c.status !== 'Draft' && c.status !== 'Written off';
    const form = canReceipt ? `
      <div style="border-top:1px solid #E4E2DB;padding:14px 18px;background:#FBFAF7;">
        <div style="font-weight:600;font-size:12.5px;margin-bottom:9px;">Record a receipt</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
          <input id="rc-amt" type="number" min="1" step="1" placeholder="Amount" value="${owed}"
                 style="border:1px solid #DDDAD2;border-radius:6px;padding:7px 8px;font-size:12px;text-align:right;">
          <input id="rc-ref" placeholder="Bank / M-Pesa reference"
                 style="border:1px solid #DDDAD2;border-radius:6px;padding:7px 8px;font-size:12px;">
        </div>
        <input id="rc-note" placeholder="Note (optional)"
               style="width:100%;box-sizing:border-box;margin-top:8px;border:1px solid #DDDAD2;border-radius:6px;padding:7px 8px;font-size:12px;">
        <div style="margin-top:10px;display:flex;gap:8px;align-items:center;">
          <button id="rc-post" style="border:1px solid #0F5C4A;background:#0F5C4A;color:#fff;border-radius:6px;padding:7px 14px;font-size:12px;font-weight:600;cursor:pointer;">Post receipt</button>
          <span class="muted" style="font-size:11px;">${UI.fmtMoney(owed)} outstanding</span>
        </div>
      </div>` : '';

    UI.drawer(`${c.no} · ${c.donor}`, `
      <div style="padding:14px 18px;">
        <div class="muted" style="font-size:11.5px;line-height:1.5;margin-bottom:10px;">${UI.esc(c.basis)}</div>
        ${rows.map(([k, v]) => `<div class="detail-row"><span class="k">${UI.esc(k)}</span><span class="v">${UI.esc(v)}</span></div>`).join('')}
        <div style="margin-top:16px;font-weight:600;font-size:12.5px;">Claim lines</div>${lines}
        <div style="margin-top:16px;font-weight:600;font-size:12.5px;">Receipts</div>${receipts}
        <div style="margin-top:16px;font-weight:600;font-size:12.5px;">Audit trail</div>${trail}
      </div>${form}`);

    const post = document.getElementById('rc-post');
    if (!post) return;
    post.addEventListener('click', async () => {
      post.disabled = true;
      try {
        const res = await UI.postJSON(`/api/receivables/${encodeURIComponent(no)}/receipt`, {
          amount: Number(document.getElementById('rc-amt').value),
          ref: document.getElementById('rc-ref').value,
          note: document.getElementById('rc-note').value,
        });
        UI.closeDrawer();
        UI.toast(`Receipt posted · ${res.invoice.status}`);
        refresh();
      } catch (err) {
        UI.toast(err.message);
        post.disabled = false;
      }
    });
  }

  async function refresh() {
    render(await load());
  }

  refresh();
})();
