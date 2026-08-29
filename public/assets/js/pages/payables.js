(async function () {
  const app = document.getElementById('app');
  let state = { status: 'All', q: '' };

  async function load() {
    const p = new URLSearchParams({ status: state.status, q: state.q });
    return UI.fetchJSON('/api/payables?' + p.toString());
  }

  function render(data) {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Accounting',
      title: 'Payables',
      blurb: 'Supplier bills from capture through approval, scheduling and payment.',
    });
    app.appendChild(UI.statGrid(data.stats));

    const card = document.createElement('div');
    card.className = 'card';
    const toolbar = document.createElement('div');
    toolbar.className = 'toolbar';
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Search bills…';
    search.value = state.q;
    search.addEventListener('input', (e) => { state.q = e.target.value; refresh(); });
    toolbar.append(search);
    card.appendChild(toolbar);

    card.appendChild(UI.tabs(data.tabs, data.tabs.find(t => t.label === state.status)?.label, (label) => { state.status = label; refresh(); }));

    const cols = [
      { label: 'Bill', key: 'no' },
      { label: 'Supplier', key: 'supplier' },
      { label: 'Category', key: 'category' },
      { label: 'Due', key: 'dueDate' },
      { label: 'Fund', key: 'fund' },
      { label: 'Status', render: (r) => UI.badge(r.status, r.status === 'Paid' ? 'calm' : r.overdue ? 'urgent' : r.status === 'Rejected' ? 'urgent' : 'warn') },
      { label: 'Gross', num: true, key: 'grossFmt' },
      { label: 'WHT', num: true, key: 'whtFmt' },
      { label: 'Net', num: true, key: 'netFmt' },
    ];
    if (data.rows.length) {
      card.appendChild(UI.table(cols, data.rows, (r) => { window.location.href = `/api/payables/${r.no}`; }));
    } else {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = 'No bills match your filters.';
      card.appendChild(empty);
    }
    const footer = document.createElement('div');
    footer.className = 'card-footer';
    footer.textContent = `${data.rows.length} of ${data.total} bills`;
    card.appendChild(footer);
    app.appendChild(card);

    const agingCard = document.createElement('div');
    agingCard.className = 'card';
    agingCard.innerHTML = `<div class="card-head"><span class="card-title">Aging</span></div>`;
    const agingBody = document.createElement('div');
    agingBody.style.cssText = 'display:grid;grid-template-columns:repeat(5,1fr);gap:1px;background:#EEEDE8;';
    data.aging.forEach(b => {
      const cell = document.createElement('div');
      cell.style.cssText = 'background:#fff;padding:12px 16px;';
      cell.innerHTML = `<div class="muted" style="font-size:11px;">${UI.esc(b.label)}</div><div style="font-family:'IBM Plex Mono',monospace;font-weight:600;margin-top:4px;">${UI.esc(b.value)}</div><div class="muted" style="font-size:11px;">${b.count} bills</div>`;
      agingBody.appendChild(cell);
    });
    agingCard.appendChild(agingBody);
    app.appendChild(agingCard);
  }

  async function refresh() {
    render(await load());
  }

  refresh();
})();
