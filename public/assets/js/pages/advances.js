(async function () {
  const app = document.getElementById('app');
  let state = { filter: 'Outstanding', age: 'All', q: '' };

  async function load() {
    const p = new URLSearchParams(state);
    return UI.fetchJSON('/api/advances?' + p.toString());
  }

  function render(data) {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Accounting',
      title: 'Staff advances',
      blurb: 'Staff and observer float carried on account 1220 until it is surrendered with receipts or recovered through payroll.',
    });
    app.appendChild(UI.statGrid(data.stats));

    // The register has to agree to the control account. A difference means
    // expenditure was coded straight out of 1220 without a surrender.
    const tie = document.createElement('div');
    tie.className = 'card';
    const ok = data.control.reconciled;
    tie.style.cssText = ok ? 'background:#E7F1EC;border-color:#D3E5DC;' : 'background:#FBF1E1;border-color:#EEE2CB;';
    tie.innerHTML = `<div style="padding:12px 16px;font-size:12px;line-height:1.55;color:${ok ? '#2C6B58' : '#8A5B2E'};">`
      + `<strong>${ok ? 'Register agrees to 1220' : 'Register leads the ledger'}</strong> — `
      + `register ${UI.esc(data.control.register)} against ${UI.esc(data.control.balance)} posted.`
      + `<div style="margin-top:5px;">${UI.esc(data.control.note)}</div></div>`;
    app.appendChild(tie);

    const card = document.createElement('div');
    card.className = 'card';

    const toolbar = document.createElement('div');
    toolbar.className = 'toolbar';
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Search holder, purpose, programme or award…';
    search.value = state.q;
    search.addEventListener('input', (e) => { state.q = e.target.value; refresh(); });
    const age = document.createElement('select');
    age.innerHTML = ['All'].concat(data.aging.map(a => a.label))
      .map(a => `<option ${a === state.age ? 'selected' : ''}>${UI.esc(a)}</option>`).join('');
    age.addEventListener('change', (e) => { state.age = e.target.value; refresh(); });
    toolbar.append(search, age);
    card.appendChild(toolbar);

    card.appendChild(UI.tabs(data.tabs, state.filter, (label) => { state.filter = label; refresh(); }));

    if (data.rows.length) {
      card.appendChild(UI.table([
        { label: 'Reference', key: 'ref' },
        {
          label: 'Holder',
          render: (r) => `${UI.esc(r.holder)}${r.isObserver ? ' ' + UI.badge('Observer', 'plain') : ''}`
            + `<div class="muted" style="font-size:11px;">${UI.esc(r.role)}</div>`,
        },
        { label: 'Purpose', render: (r) => `<span class="muted" style="font-size:11.5px;">${UI.esc(r.purpose)}</span>` },
        { label: 'Programme', key: 'program' },
        { label: 'Surrender due', render: (r) => `${UI.esc(r.dueDate)}<div class="muted" style="font-size:11px;">${UI.esc(r.age)}</div>` },
        { label: 'Status', render: (r) => UI.badge(r.status, statusTone(r)) },
        { label: 'Advanced', num: true, key: 'amount' },
        { label: 'Accounted', num: true, key: 'accounted' },
        {
          label: 'Outstanding',
          num: true,
          render: (r) => `<span style="${r.overdue ? 'color:#A6412F;font-weight:600;' : ''}">${UI.esc(r.outstanding)}</span>`,
        },
      ], data.rows, (r) => openAdvance(r.ref)));
    } else {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = 'No advances match your filters.';
      card.appendChild(empty);
    }

    const footer = document.createElement('div');
    footer.className = 'card-footer';
    footer.textContent = `${data.rows.length} of ${data.total} advances`;
    card.appendChild(footer);
    app.appendChild(card);

    const aging = document.createElement('div');
    aging.className = 'card';
    aging.innerHTML = `<div class="card-head"><span class="card-title">Ageing past the surrender date</span></div>`;
    const body = document.createElement('div');
    body.style.cssText = 'display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:#EEEDE8;';
    data.aging.forEach(b => {
      const cell = document.createElement('div');
      cell.style.cssText = 'background:#fff;padding:12px 16px;cursor:pointer;';
      const heavy = b.label === '60+ days' && b.count > 0;
      cell.innerHTML = `<div class="muted" style="font-size:11px;">${UI.esc(b.label)}</div>`
        + `<div style="font-family:'IBM Plex Mono',monospace;font-weight:600;margin-top:4px;${heavy ? 'color:#A6412F;' : ''}">${UI.esc(b.value)}</div>`
        + `<div class="muted" style="font-size:11px;">${b.count} ${b.count === 1 ? 'advance' : 'advances'}</div>`;
      cell.addEventListener('click', () => { state.age = b.label; refresh(); });
      body.appendChild(cell);
    });
    aging.appendChild(body);
    app.appendChild(aging);
  }

  function statusTone(r) {
    if (r.status === 'Rejected') return 'urgent';
    if (r.status === 'Surrendered' || r.status === 'Recovered') return 'calm';
    if (r.overdue) return 'urgent';
    return r.status === 'Issued' ? 'warn' : 'plain';
  }

  async function openAdvance(ref) {
    const a = await UI.fetchJSON('/api/advances/' + encodeURIComponent(ref));

    const facts = [
      ['Holder', `${a.holder} · ${a.kind.toLowerCase()}`], ['Role', a.role],
      ['Programme', a.program], ['Award', `${a.grant} · ${a.fund}`],
      ['Advanced', UI.fmtMoney(a.amount)],
      ['Accounted for', a.accounted > 0 ? UI.fmtMoney(a.accounted) : '—'],
      ['Recovered', a.recovered > 0 ? UI.fmtMoney(a.recovered) : '—'],
      ['Outstanding', a.outstanding > 0 ? UI.fmtMoney(a.outstanding) : '—'],
      ['Requested', a.reqDate], ['Issued', a.issueDate || '—'],
      ['Paid by', a.method || '—'], ['Surrender due', a.dueDate],
    ];

    const receipts = a.receipts.length
      ? a.receipts.map(r => `<div class="detail-row"><span class="k">${UI.esc(r.code)} · ${UI.esc(r.desc)}</span><span class="v">${UI.fmtMoney(r.amount)}</span></div>`).join('')
      : '<div class="empty-state" style="padding:16px;">No receipts surrendered yet.</div>';
    const trail = a.trail.map(t => `<div class="detail-row"><span class="k">${UI.esc(t.when)}</span><span class="v" style="font-weight:400;text-align:left;">${UI.esc(t.what)}</span></div>`).join('');

    const form = a.canSurrender ? `
      <div style="border-top:1px solid #E4E2DB;padding:14px 18px;background:#FBFAF7;">
        <div style="font-weight:600;font-size:12.5px;margin-bottom:4px;">Surrender with receipts</div>
        <div class="muted" style="font-size:11px;margin-bottom:9px;line-height:1.5;">Receipts under the advance leave a balance; over it, the overspend is owed back to the holder.</div>
        <div id="ad-lines"></div>
        <button id="ad-add" style="border:1px solid #DDDAD2;background:#fff;border-radius:6px;padding:5px 10px;font-size:11.5px;cursor:pointer;">+ Add line</button>
        <label style="display:flex;align-items:center;gap:7px;margin-top:10px;font-size:11.5px;color:#5C665F;">
          <input id="ad-outstanding" type="checkbox"> Leave any balance outstanding rather than refunding it
        </label>
        <button id="ad-post" style="margin-top:10px;border:1px solid #0F5C4A;background:#0F5C4A;color:#fff;border-radius:6px;padding:7px 14px;font-size:12px;font-weight:600;cursor:pointer;">Post surrender</button>
      </div>` : '';

    UI.drawer(`${a.ref} · ${a.holder}`, `
      <div style="padding:14px 18px;">
        <div class="muted" style="font-size:11.5px;line-height:1.5;margin-bottom:10px;">${UI.esc(a.purpose)}</div>
        ${facts.map(([k, v]) => `<div class="detail-row"><span class="k">${UI.esc(k)}</span><span class="v">${UI.esc(v)}</span></div>`).join('')}
        <div style="margin-top:16px;font-weight:600;font-size:12.5px;">Receipts</div>${receipts}
        <div style="margin-top:16px;font-weight:600;font-size:12.5px;">Audit trail</div>${trail}
      </div>${form}`);

    if (!a.canSurrender) return;

    const lines = document.getElementById('ad-lines');
    const addLine = () => {
      const row = document.createElement('div');
      row.style.cssText = 'display:grid;grid-template-columns:96px 1fr 96px 22px;gap:6px;margin-bottom:6px;';
      row.innerHTML = `
        <input class="ad-code" placeholder="Account" style="border:1px solid #DDDAD2;border-radius:6px;padding:6px 7px;font-size:12px;">
        <input class="ad-desc" placeholder="What it was spent on" style="border:1px solid #DDDAD2;border-radius:6px;padding:6px 7px;font-size:12px;">
        <input class="ad-amt" type="number" min="1" step="1" placeholder="Amount" style="border:1px solid #DDDAD2;border-radius:6px;padding:6px 7px;font-size:12px;text-align:right;">
        <button type="button" class="ad-del" style="border:none;background:transparent;color:#8B948F;cursor:pointer;">&times;</button>`;
      row.querySelector('.ad-del').addEventListener('click', () => row.remove());
      lines.appendChild(row);
    };
    addLine();
    document.getElementById('ad-add').addEventListener('click', addLine);

    document.getElementById('ad-post').addEventListener('click', async (e) => {
      e.target.disabled = true;
      try {
        const receiptLines = Array.from(lines.querySelectorAll('div')).map(row => ({
          code: row.querySelector('.ad-code').value,
          desc: row.querySelector('.ad-desc').value,
          amount: Number(row.querySelector('.ad-amt').value),
        }));
        const res = await UI.postJSON(`/api/advances/${encodeURIComponent(ref)}/surrender`, {
          receipts: receiptLines,
          mode: document.getElementById('ad-outstanding').checked ? 'outstanding' : 'refund',
        });
        UI.closeDrawer();
        UI.toast(res.advance.verdict);
        refresh();
      } catch (err) {
        UI.toast(err.message);
        e.target.disabled = false;
      }
    });
  }

  async function refresh() {
    render(await load());
  }

  refresh();
})();
