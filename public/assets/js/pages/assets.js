(async function () {
  const app = document.getElementById('app');
  let state = { filter: 'All', q: '' };

  async function load() {
    const p = new URLSearchParams({ filter: state.filter, q: state.q });
    return UI.fetchJSON('/api/assets?' + p.toString());
  }

  function render(data) {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Accounting',
      title: 'Asset register',
      blurb: 'Every asset ELOG holds, its cost, depreciation to date and net book value.',
    });
    app.appendChild(UI.statGrid(data.stats));

    const card = document.createElement('div');
    card.className = 'card';
    const toolbar = document.createElement('div');
    toolbar.className = 'toolbar';
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Search assets…';
    search.value = state.q;
    search.addEventListener('input', (e) => { state.q = e.target.value; refresh(); });
    toolbar.appendChild(search);
    card.appendChild(toolbar);

    card.appendChild(UI.tabs(data.tabs, data.tabs.find(t => t.label === state.filter)?.label, (label) => { state.filter = label; refresh(); }));

    const cols = [
      { label: 'Tag', key: 'tag' },
      { label: 'Name', key: 'name' },
      { label: 'Class', key: 'cls' },
      { label: 'Funder', key: 'funder' },
      { label: 'Status', render: (r) => UI.badge(r.status, r.status === 'In use' ? 'calm' : r.status === 'Disposed' ? 'urgent' : 'plain') },
      { label: 'Cost', num: true, key: 'costFmt' },
      { label: 'Accum. dep.', num: true, key: 'accumFmt' },
      { label: 'NBV', num: true, key: 'nbv' },
      { label: 'Monthly', num: true, key: 'monthly' },
    ];
    if (data.rows.length) {
      card.appendChild(UI.table(cols, data.rows, (r) => { window.location.href = `/api/assets/${r.tag}`; }));
    } else {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = 'No assets match your filters.';
      card.appendChild(empty);
    }
    const footer = document.createElement('div');
    footer.className = 'card-footer';
    footer.textContent = `${data.rows.length} of ${data.total} assets`;
    card.appendChild(footer);
    app.appendChild(card);
  }

  async function refresh() {
    render(await load());
  }

  refresh();
})();
