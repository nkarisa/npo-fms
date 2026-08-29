(async function () {
  const app = document.getElementById('app');
  let state = { report: 'Statement of financial position' };
  const reportOptions = ['Statement of financial position', 'Statement of activities', 'Statement of cash flows', 'Trial balance'];

  async function load() {
    const p = new URLSearchParams({ report: state.report });
    return UI.fetchJSON('/api/reports?' + p.toString());
  }

  function render(data) {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Insight',
      title: data.title,
      blurb: 'Statements drawn directly from the chart of accounts, for the year to date.',
    });

    const card = document.createElement('div');
    card.className = 'card';
    card.appendChild(UI.tabs(reportOptions, state.report, (label) => { state.report = label; refresh(); }));

    if (data.title === 'Trial balance') {
      const cols = [
        { label: 'Code', key: 'code' }, { label: 'Account', key: 'name' },
        { label: 'Debit', num: true, key: 'debit' }, { label: 'Credit', num: true, key: 'credit' },
      ];
      card.appendChild(UI.table(cols, data.rows));
      const totalsRow = document.createElement('div');
      totalsRow.style.cssText = 'display:flex;justify-content:flex-end;gap:24px;padding:10px 16px;background:#FAF9F6;border-top:1px solid #DDDAD2;font-weight:600;font-family:"IBM Plex Mono",monospace;';
      totalsRow.innerHTML = `<span>Debit: ${UI.esc(data.totals.debit)}</span><span>Credit: ${UI.esc(data.totals.credit)}</span>`;
      card.appendChild(totalsRow);
    } else {
      data.sections.forEach(s => {
        const head = document.createElement('div');
        head.style.cssText = 'padding:9px 16px;background:#FAF9F6;border-bottom:1px solid #EEEDE8;font-weight:600;font-size:12px;';
        head.textContent = s.heading;
        card.appendChild(head);
        card.appendChild(UI.table([{ label: 'Account', key: 'name' }, { label: 'Amount', num: true, key: 'amount' }], s.rows));
        const tot = document.createElement('div');
        tot.style.cssText = 'display:flex;justify-content:space-between;padding:9px 16px;background:#FBFAF7;border-bottom:1px solid #DDDAD2;font-weight:600;';
        tot.innerHTML = `<span>${UI.esc(s.total.label)}</span><span style="font-family:'IBM Plex Mono',monospace;">${UI.esc(s.total.amount)}</span>`;
        card.appendChild(tot);
      });
    }

    const notes = document.createElement('div');
    notes.className = 'card-body';
    notes.innerHTML = (data.notes || []).map(n => `<p class="muted" style="font-size:12px;">${UI.esc(n)}</p>`).join('');
    card.appendChild(notes);

    app.appendChild(card);
  }

  async function refresh() {
    render(await load());
  }

  refresh();
})();
