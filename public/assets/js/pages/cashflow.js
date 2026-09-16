(async function () {
  const app = document.getElementById('app');
  let state = { scenario: 'Base case', hold: false };

  async function load() {
    const p = new URLSearchParams({ scenario: state.scenario, hold: String(state.hold) });
    return UI.fetchJSON('/api/cashflow?' + p.toString());
  }

  function render(data) {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Insight',
      title: 'Cashflow forecast',
      blurb: 'Thirteen weeks ahead. The unrestricted line is the one that answers whether core costs can be met.',
    });
    app.appendChild(UI.statGrid(data.stats));

    if (data.warning) {
      const warn = document.createElement('div');
      warn.className = 'card';
      warn.style.cssText = 'background:#FBEAE5;border-color:#F0D9D1;';
      warn.innerHTML = `<div style="padding:12px 16px;color:#A6412F;font-size:12px;line-height:1.55;">${UI.esc(data.warning)}</div>`;
      app.appendChild(warn);
    }

    const card = document.createElement('div');
    card.className = 'card';

    const toolbar = document.createElement('div');
    toolbar.className = 'toolbar';
    const scenario = document.createElement('select');
    scenario.innerHTML = data.scenarioOptions.map(s => `<option ${s === state.scenario ? 'selected' : ''}>${UI.esc(s)}</option>`).join('');
    scenario.addEventListener('change', (e) => { state.scenario = e.target.value; refresh(); });

    // The one lever finance actually controls, so it is a toggle not a scenario.
    const hold = document.createElement('label');
    hold.style.cssText = 'display:flex;align-items:center;gap:7px;font-size:12px;color:#5C665F;cursor:pointer;';
    hold.innerHTML = `<input type="checkbox" ${state.hold ? 'checked' : ''}> ${UI.esc(data.holdLabel)}`;
    hold.querySelector('input').addEventListener('change', (e) => { state.hold = e.target.checked; refresh(); });

    toolbar.append(scenario, hold);
    card.appendChild(toolbar);

    card.appendChild(UI.table([
      { label: 'Week commencing', key: 'wc' },
      { label: 'Expected', render: (r) => `${UI.esc(r.note || '—')}${r.grantWeek ? ' ' + UI.badge('Grant receipt', 'plain') : ''}` },
      { label: 'Opening', num: true, key: 'opening' },
      { label: 'In', num: true, key: 'inflow' },
      { label: 'Out', num: true, key: 'outflow' },
      { label: 'Net', num: true, render: (r) => `<span style="color:${r.positive ? '#2C6B58' : '#A6412F'};">${UI.esc(r.net)}</span>` },
      { label: 'Closing', num: true, key: 'closing' },
      {
        label: 'Of which unrestricted',
        num: true,
        // Restricted cash cannot lawfully cover core costs, so a negative here
        // is the real signal even when closing cash looks healthy.
        render: (r) => `<span style="${r.tight ? 'color:#A6412F;font-weight:600;' : ''}">${UI.esc(r.unrestricted)}</span>`,
      },
    ], data.rows));

    const footer = document.createElement('div');
    footer.className = 'card-footer';
    footer.textContent = data.hint;
    card.appendChild(footer);
    app.appendChild(card);
  }

  async function refresh() {
    render(await load());
  }

  refresh();
})();
