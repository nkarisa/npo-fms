(async function () {
  const app = document.getElementById('app');
  const params = new URLSearchParams(window.location.search);
  let state = { account: params.get('account') || '', period: 'FY2026 · Jan – Aug', fund: 'All funds', q: '' };

  async function load() {
    const p = new URLSearchParams({ account: state.account, period: state.period, fund: state.fund, q: state.q });
    return UI.fetchJSON('/api/gl?' + p.toString());
  }

  function render(data) {
    state.account = data.account.code;
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: data.account.code + ' · ' + data.account.name,
      title: 'General ledger',
      blurb: 'Every posting behind a single account, in date order, with a running balance.',
      actions: '<button class="btn btn-primary" id="new-journal">New journal</button>',
    });
    app.appendChild(UI.statGrid(data.summary));

    document.getElementById('new-journal').addEventListener('click', () => {
      UI.openNewJournalDrawer({
        defaultLine: { code: data.account.code, fund: data.account.fund === 'All funds' ? undefined : data.account.fund, program: data.account.program },
        onSaved: refresh,
      });
    });

    const card = document.createElement('div');
    card.className = 'card';
    const toolbar = document.createElement('div');
    toolbar.className = 'toolbar';
    const accountSelect = document.createElement('select');
    accountSelect.innerHTML = data.accountOptions.map(o => `<option value="${o.code}" ${o.code === state.account ? 'selected' : ''}>${UI.esc(o.label)}</option>`).join('');
    accountSelect.addEventListener('change', (e) => { state.account = e.target.value; refresh(); });
    const periodSelect = document.createElement('select');
    periodSelect.innerHTML = data.periodOptions.map(p => `<option ${p === state.period ? 'selected' : ''}>${UI.esc(p)}</option>`).join('');
    periodSelect.addEventListener('change', (e) => { state.period = e.target.value; refresh(); });
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Search postings…';
    search.value = state.q;
    search.addEventListener('input', (e) => { state.q = e.target.value; refresh(); });
    toolbar.append(accountSelect, periodSelect, search);
    card.appendChild(toolbar);

    const cols = [
      { label: 'Date', key: 'date' },
      { label: 'Ref', key: 'ref' },
      { label: 'Narration', key: 'narration' },
      { label: 'Fund', key: 'fund' },
      { label: 'Programme', key: 'program' },
      { label: 'Contra', key: 'contra' },
      { label: 'Debit', num: true, key: 'debit' },
      { label: 'Credit', num: true, key: 'credit' },
      { label: 'Balance', num: true, key: 'balance' },
    ];
    if (data.rows.length) {
      const table = UI.table(cols, data.rows);
      const openingRow = table.querySelector('tbody tr');
      if (openingRow && data.rows[0].isOpening) {
        openingRow.style.cssText = 'background:#FAF9F6;font-weight:600;cursor:default;';
        openingRow.querySelectorAll('td').forEach(td => { td.style.cursor = 'default'; });
      }
      card.appendChild(table);
    } else {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = 'No postings in this period.';
      card.appendChild(empty);
    }
    const footer = document.createElement('div');
    footer.className = 'card-footer';
    const postingCount = data.rows.filter(r => !r.isOpening).length;
    footer.textContent = `${postingCount} postings · ${data.account.type.toLowerCase()} account · ${data.account.fund}`;
    card.appendChild(footer);
    app.appendChild(card);
  }

  async function refresh() {
    render(await load());
  }

  refresh();
})();
