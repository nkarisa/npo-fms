(async function () {
  const app = document.getElementById('app');
  let state = { status: 'All', q: '' };

  async function load() {
    const p = new URLSearchParams({ status: state.status, q: state.q });
    return UI.fetchJSON('/api/donor-reports?' + p.toString());
  }

  function render(data) {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Funds and grants',
      title: 'Donor reports',
      blurb: 'Financial reports to funders, reconciled against what is actually posted in the ledger.',
    });
    app.appendChild(UI.statGrid(data.stats));

    const card = document.createElement('div');
    card.className = 'card';
    const toolbar = document.createElement('div');
    toolbar.className = 'toolbar';
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Search reports…';
    search.value = state.q;
    search.addEventListener('input', (e) => { state.q = e.target.value; refresh(); });
    toolbar.appendChild(search);
    card.appendChild(toolbar);

    card.appendChild(UI.tabs(data.tabs, data.tabs.find(t => t.label === state.status)?.label, (label) => { state.status = label; refresh(); }));

    const cols = [
      { label: 'Ref', key: 'ref' },
      { label: 'Title', key: 'title' },
      { label: 'Funder', key: 'funder' },
      { label: 'Period', key: 'period' },
      { label: 'Due', key: 'due' },
      { label: 'Status', render: (r) => UI.badge(r.status, r.status === 'Submitted' || r.status === 'Accepted' ? 'calm' : r.status === 'Overdue' || r.status === 'Queried' ? 'urgent' : 'warn') },
      { label: 'Reported', num: true, key: 'reported' },
      { label: 'Ledger actual', num: true, key: 'actual' },
      { label: 'Ties?', render: (r) => r.tied ? UI.badge('Ties', 'calm') : UI.badge('Does not tie', 'urgent') },
    ];
    if (data.rows.length) {
      card.appendChild(UI.table(cols, data.rows, (r) => { window.location.href = `/api/donor-reports/${encodeURIComponent(r.ref)}`; }));
    } else {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = 'No reports match your filters.';
      card.appendChild(empty);
    }
    const footer = document.createElement('div');
    footer.className = 'card-footer';
    footer.textContent = `${data.rows.length} of ${data.total} reports`;
    card.appendChild(footer);
    app.appendChild(card);
  }

  async function refresh() {
    render(await load());
  }

  refresh();
})();
