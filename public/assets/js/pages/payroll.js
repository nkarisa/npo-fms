(async function () {
  const app = document.getElementById('app');
  let state = { filter: 'All', q: '' };

  async function load() {
    const p = new URLSearchParams({ filter: state.filter, q: state.q });
    return UI.fetchJSON('/api/payroll?' + p.toString());
  }

  function render(data) {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: data.period + ' payroll',
      title: 'Payroll',
      blurb: 'Gross pay, statutory deductions and net pay for every member of staff.',
    });
    app.appendChild(UI.statGrid(data.stats));

    const card = document.createElement('div');
    card.className = 'card';
    const toolbar = document.createElement('div');
    toolbar.className = 'toolbar';
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Search staff…';
    search.value = state.q;
    search.addEventListener('input', (e) => { state.q = e.target.value; refresh(); });
    toolbar.appendChild(search);
    card.appendChild(toolbar);

    card.appendChild(UI.tabs(data.tabs, state.filter, (label) => { state.filter = label; refresh(); }));

    const cols = [
      { label: 'No.', key: 'no' },
      { label: 'Name', key: 'name' },
      { label: 'Role', key: 'role' },
      { label: 'Grade', key: 'grade' },
      { label: 'Allocation', key: 'alloc' },
      { label: 'Gross', num: true, key: 'gross' },
      { label: 'PAYE', num: true, key: 'paye' },
      { label: 'Statutory', num: true, key: 'stat' },
      { label: 'Net', num: true, key: 'net' },
    ];
    if (data.rows.length) {
      card.appendChild(UI.table(cols, data.rows));
    } else {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = 'No staff match your filters.';
      card.appendChild(empty);
    }
    const footer = document.createElement('div');
    footer.className = 'card-footer';
    footer.textContent = `${data.rows.length} of ${data.total} staff`;
    card.appendChild(footer);
    app.appendChild(card);
  }

  async function refresh() {
    render(await load());
  }

  refresh();
})();
