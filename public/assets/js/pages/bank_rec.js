(async function () {
  const app = document.getElementById('app');
  let state = { account: '' };

  async function load() {
    const p = new URLSearchParams({ account: state.account });
    return UI.fetchJSON('/api/bank-rec?' + p.toString());
  }

  function render(data) {
    state.account = data.account.code;
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: data.account.stmtRef,
      title: 'Bank reconciliation',
      blurb: 'Match the bank statement to the cash book, account by account.',
    });
    app.appendChild(UI.statGrid(data.stats));

    const card = document.createElement('div');
    card.className = 'card';
    const toolbar = document.createElement('div');
    toolbar.className = 'toolbar';
    const select = document.createElement('select');
    select.innerHTML = data.accountOptions.map(o => `<option value="${o.code}" ${o.code === state.account ? 'selected' : ''}>${UI.esc(o.label)}</option>`).join('');
    select.addEventListener('change', (e) => { state.account = e.target.value; refresh(); });
    toolbar.appendChild(select);
    card.appendChild(toolbar);

    const two = document.createElement('div');
    two.className = 'two-col';

    const stmtCard = document.createElement('div');
    stmtCard.innerHTML = `<div class="card-head"><span class="card-title">Bank statement</span></div>`;
    stmtCard.appendChild(UI.table(
      [{ label: 'Date', key: 'date' }, { label: 'Ref', key: 'ref' }, { label: 'Description', key: 'desc' }, { label: 'Amount', num: true, key: 'amt', render: (r) => UI.fmtMoney(r.amt) }],
      data.stmtRows
    ));

    const bookCard = document.createElement('div');
    bookCard.innerHTML = `<div class="card-head"><span class="card-title">Cash book</span></div>`;
    bookCard.appendChild(UI.table(
      [{ label: 'Date', key: 'date' }, { label: 'Ref', key: 'ref' }, { label: 'Description', key: 'desc' }, { label: 'Amount', num: true, key: 'amt', render: (r) => UI.fmtMoney(r.amt) }],
      data.bookRows
    ));

    two.append(stmtCard, bookCard);
    card.appendChild(two);
    app.appendChild(card);
  }

  async function refresh() {
    render(await load());
  }

  refresh();
})();
