(async function () {
  const app = document.getElementById('app');
  let state = { period: 'Aug 2026' };

  async function load() {
    const p = new URLSearchParams({ period: state.period });
    return UI.fetchJSON('/api/period-close?' + p.toString());
  }

  function render(data) {
    state.period = data.period;
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Overview',
      title: 'Period close',
      blurb: 'The checklist that has to settle before ' + data.period + ' can be locked to further posting.',
    });

    const card = document.createElement('div');
    card.className = 'card';
    const toolbar = document.createElement('div');
    toolbar.className = 'toolbar';
    const select = document.createElement('select');
    select.innerHTML = data.periods.map(p => `<option value="${p.label}" ${p.label === state.period ? 'selected' : ''}>${p.label} — ${p.state}</option>`).join('');
    select.addEventListener('change', (e) => { state.period = e.target.value; refresh(); });
    toolbar.appendChild(select);
    card.appendChild(toolbar);

    const progress = document.createElement('div');
    progress.className = 'card-body';
    progress.innerHTML = `
      <div class="bar-track" style="height:8px;margin-bottom:8px;"><div class="bar-fill" style="width:${data.pct}%;"></div></div>
      <div class="muted" style="font-size:12px;">${data.pct}% of checklist settled</div>`;
    card.appendChild(progress);

    const list = document.createElement('div');
    data.tasks.forEach(t => {
      const row = document.createElement('div');
      row.style.cssText = 'display:flex;align-items:center;gap:12px;padding:10px 16px;border-bottom:1px solid #F2F1EC;';
      row.innerHTML = `${UI.badge(t.ok ? 'Settled' : 'Open', t.ok ? 'calm' : 'warn')}<div><div>${UI.esc(t.label)}</div><div class="muted" style="font-size:11.5px;">${UI.esc(t.note)}</div></div>`;
      list.appendChild(row);
    });
    card.appendChild(list);
    app.appendChild(card);

    app.appendChild(UI.statGrid(data.totals.map(t => ({ label: t.label, value: t.value }))));
  }

  async function refresh() {
    render(await load());
  }

  refresh();
})();
