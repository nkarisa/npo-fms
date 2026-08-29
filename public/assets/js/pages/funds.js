(async function () {
  const app = document.getElementById('app');
  let state = { class: 'All', q: '' };

  async function load() {
    const p = new URLSearchParams({ class: state.class, q: state.q });
    return UI.fetchJSON('/api/funds?' + p.toString());
  }

  function render(data) {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Funds and grants',
      title: 'Funds',
      blurb: 'Unrestricted, restricted and endowment balances — each with its own rules on use.',
    });
    app.appendChild(UI.statGrid(data.stats));

    const card = document.createElement('div');
    card.className = 'card';
    const toolbar = document.createElement('div');
    toolbar.className = 'toolbar';
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Search funds…';
    search.value = state.q;
    search.addEventListener('input', (e) => { state.q = e.target.value; refresh(); });
    toolbar.appendChild(search);
    card.appendChild(toolbar);

    card.appendChild(UI.tabs(data.tabs, data.tabs.find(t => t.label === state.class)?.label, (label) => { state.class = label; refresh(); }));

    const cols = [
      { label: 'Fund', key: 'name' },
      { label: 'Class', key: 'cls' },
      { label: 'Funder', key: 'funder' },
      { label: 'Opening', num: true, render: (r) => UI.fmtMoney(r.opening) },
      { label: 'Income', num: true, render: (r) => UI.fmtMoney(r.income) },
      { label: 'Spend', num: true, render: (r) => UI.fmtMoney(r.spend) },
      { label: 'Closing', num: true, key: 'closingFmt' },
      { label: 'Utilised', num: true, render: (r) => r.pct + '%' },
    ];
    if (data.rows.length) {
      card.appendChild(UI.table(cols, data.rows, (r) => { window.location.href = `/api/funds/${r.code}`; }));
    } else {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = 'No funds match your filters.';
      card.appendChild(empty);
    }
    const footer = document.createElement('div');
    footer.className = 'card-footer';
    footer.textContent = `${data.rows.length} of ${data.total} funds`;
    card.appendChild(footer);
    app.appendChild(card);
  }

  async function refresh() {
    render(await load());
  }

  refresh();
})();
