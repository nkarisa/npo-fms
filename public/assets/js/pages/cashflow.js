/**
 * Cashflow forecast (v5).
 *
 * Thirteen weeks ahead, one row a week. The unrestricted column is the one that
 * answers whether core costs can be met: restricted cash cannot lawfully cover
 * them, so a negative there is the real signal even when closing cash looks
 * healthy. Scenarios and the discretionary hold re-run the projection on the API.
 */
(async function () {
  const app = document.getElementById('app');
  const esc = UI.esc;
  const BASE = { scenario: 'Base case', hold: false };
  const state = { ...BASE };
  let data = null;

  const query = () => new URLSearchParams({ scenario: state.scenario, hold: String(state.hold) }).toString();

  async function refresh() {
    data = await UI.fetchJSON('/api/cashflow?' + query());
    render();
  }

  function render() {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Insight',
      title: 'Cashflow forecast',
      blurb: data.blurb || 'Thirteen weeks ahead. The unrestricted line is the one that answers whether core costs can be met.',
      actions: data.rows.length
        ? '<button type="button" class="btn" data-act="reset">Reset</button>'
          + '<button type="button" class="btn btn-primary" data-act="export">Export for Board</button>'
        : '',
    });
    app.querySelectorAll('[data-act]').forEach((b) => b.addEventListener('click', () => ({
      reset: () => { Object.assign(state, BASE); refresh(); }, export: exportForBoard,
    })[b.dataset.act]()));

    if (!data.rows.length) {
      const empty = document.createElement('div');
      empty.className = 'card empty-state';
      empty.textContent = data.empty;
      app.appendChild(empty);
      return;
    }

    app.appendChild(UI.statGrid(data.stats));
    app.appendChild(controls());
    if (data.warning) {
      const warn = document.createElement('div');
      warn.className = 'cf-warn';
      warn.innerHTML = `<span class="cf-warn-mark">!</span><div>${esc(data.warning)}</div>`;
      app.appendChild(warn);
    }
    app.appendChild(table());
  }

  /** The scenario switch, and the one lever finance actually controls — a toggle, not a scenario. */
  function controls() {
    const div = document.createElement('div');
    div.className = 'cf-controls';
    div.innerHTML = `
      <div class="coa-seg">${data.scenarioOptions.map(s => `<button type="button" class="coa-seg-btn ${s === data.scenario ? 'on' : ''}" data-scenario="${esc(s)}">${esc(s)}</button>`).join('')}</div>
      <label class="cf-hold"><input type="checkbox" ${data.hold ? 'checked' : ''}> ${esc(data.holdLabel)}</label>`;
    div.querySelectorAll('[data-scenario]').forEach((b) => b.addEventListener('click', () => {
      state.scenario = b.dataset.scenario;
      refresh();
    }));
    div.querySelector('.cf-hold input').addEventListener('change', (e) => { state.hold = e.target.checked; refresh(); });
    return div;
  }

  function table() {
    const card = document.createElement('div');
    card.className = 'coa-card';
    card.innerHTML = `
      <div class="cf-scroll"><div class="cf-inner">
        <div class="cf-cols cf-head">
          <div>Week</div><div class="end">Receipts</div><div class="end">Payments</div><div class="end">Net</div>
          <div>Shape and driver</div><div class="end">Closing cash</div><div class="end">Of which unrestricted</div>
        </div>
        ${data.rows.map(week).join('')}
      </div></div>
      <div class="cf-foot"><span>${esc(data.hint)}</span><span class="cf-foot-rule">${esc(data.legend)}</span></div>`;
    return card;
  }

  function week(w) {
    return `
      <div class="cf-cols cf-week">
        <div class="cf-mono">${esc(w.wc)}</div>
        <div class="end cf-mono in">${esc(w.inflow)}</div>
        <div class="end cf-mono out">${esc(w.outflow)}</div>
        <div class="end cf-mono ${w.positive ? 'up' : 'down'}">${esc(w.net)}</div>
        <div class="cf-shape">
          <div class="cf-bars"><i class="in" style="width:${w.inflowPct}%"></i><i class="out" style="width:${w.outflowPct}%"></i></div>
          <span class="cf-note" title="${esc(w.note)}">${esc(w.note)}</span>
        </div>
        <div class="end cf-mono strong">${esc(w.closing)}</div>
        <div class="end cf-mono ${w.tight ? 'tight' : 'ok'}">${esc(w.unrestricted)}</div>
      </div>`;
  }

  async function exportForBoard() {
    try {
      const res = await fetch('/api/cashflow/export?' + query());
      if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'The forecast could not be exported.');
      UI.download(await res.blob(), `${UI.brand()} cashflow forecast.csv`, res.headers.get('Content-Disposition'));
      UI.toast(data.weeks + '-week forecast exported for the Board finance committee.');
    } catch (err) {
      UI.toast(err.message);
    }
  }

  refresh();
})();
