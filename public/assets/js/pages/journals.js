(async function () {
  const app = document.getElementById('app');
  let state = { status: 'All', type: 'All types', q: '' };

  async function load() {
    const p = new URLSearchParams({ status: state.status, type: state.type, q: state.q });
    return UI.fetchJSON('/api/journals?' + p.toString());
  }

  function render(data) {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Accounting',
      title: 'Journals',
      blurb: 'Every manual entry to the ledger — drafted, approved and posted here.',
      actions: '<button class="btn btn-primary" id="new-journal">New journal</button>',
    });
    app.appendChild(UI.statGrid(data.stats));

    const card = document.createElement('div');
    card.className = 'card';
    const toolbar = document.createElement('div');
    toolbar.className = 'toolbar';
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Search journals…';
    search.value = state.q;
    search.addEventListener('input', (e) => { state.q = e.target.value; refresh(); });
    const typeSelect = document.createElement('select');
    typeSelect.innerHTML = data.typeOptions.map(t => `<option ${t === state.type ? 'selected' : ''}>${UI.esc(t)}</option>`).join('');
    typeSelect.addEventListener('change', (e) => { state.type = e.target.value; refresh(); });
    toolbar.append(search, typeSelect);
    card.appendChild(toolbar);

    card.appendChild(UI.tabs(data.tabs, data.tabs.find(t => t.label === state.status)?.label, (label) => { state.status = label; refresh(); }));

    const cols = [
      { label: 'Ref', key: 'ref' },
      { label: 'Date', key: 'date' },
      { label: 'Type', key: 'type' },
      { label: 'Narration', key: 'narration' },
      { label: 'Fund', key: 'fund' },
      { label: 'Programme', key: 'program' },
      { label: 'Status', render: (r) => UI.badge(r.status, r.status === 'Posted' ? 'calm' : r.status === 'Draft' ? 'plain' : r.status === 'Reversed' ? 'urgent' : 'warn') },
      { label: 'Amount', num: true, key: 'amount' },
    ];
    if (data.rows.length) {
      card.appendChild(UI.table(cols, data.rows, (r) => { window.location.href = `/api/journals/${r.ref}`; }));
    } else {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = 'No journals match your filters.';
      card.appendChild(empty);
    }
    const footer = document.createElement('div');
    footer.className = 'card-footer';
    footer.textContent = `${data.rows.length} of ${data.total} journals`;
    card.appendChild(footer);
    app.appendChild(card);

    document.getElementById('new-journal').addEventListener('click', () => {
      UI.openNewJournalDrawer({ onSaved: refresh });
    });
  }

  async function refresh() {
    render(await load());
  }

  refresh();
})();
