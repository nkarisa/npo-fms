(async function () {
  const app = document.getElementById('app');
  let state = { status: 'All', q: '' };

  async function load() {
    const p = new URLSearchParams({ status: state.status, q: state.q });
    return UI.fetchJSON('/api/grants?' + p.toString());
  }

  function render(data) {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Funds and grants',
      title: 'Grants and awards',
      blurb: 'Every donor agreement — value, receipts, spend and the burn rate against elapsed time.',
    });
    app.appendChild(UI.statGrid(data.stats));

    const card = document.createElement('div');
    card.className = 'card';
    const toolbar = document.createElement('div');
    toolbar.className = 'toolbar';
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Search grants…';
    search.value = state.q;
    search.addEventListener('input', (e) => { state.q = e.target.value; refresh(); });
    toolbar.appendChild(search);
    card.appendChild(toolbar);

    card.appendChild(UI.tabs(data.tabs, data.tabs.find(t => t.label === state.status)?.label, (label) => { state.status = label; refresh(); }));

    const rowsWrap = document.createElement('div');
    data.rows.forEach(g => {
      const row = document.createElement('a');
      row.href = `/api/grants/${encodeURIComponent(g.ref)}`;
      row.style.cssText = 'display:block;padding:12px 16px;border-bottom:1px solid #F2F1EC;text-decoration:none;color:inherit;';
      row.innerHTML = `
        <div style="display:flex;align-items:baseline;gap:10px;margin-bottom:6px;">
          <strong>${UI.esc(g.funder)}</strong><span class="muted" style="flex:1;">${UI.esc(g.title)}</span>
          ${UI.badge(g.status, g.status === 'Active' ? 'calm' : g.status === 'Suspended' ? 'urgent' : 'warn')}
        </div>
        <div class="muted" style="font-size:11.5px;margin-bottom:6px;">${UI.esc(g.program)} · ${UI.esc(g.period)} · ${UI.esc(g.spent)} of ${UI.esc(g.value)} spent</div>
        <div class="bar-track"><div class="bar-fill" style="width:${g.burnPct}%;"></div><div class="bar-mark" style="left:${g.elapsed}%;"></div></div>`;
      rowsWrap.appendChild(row);
    });
    if (!data.rows.length) {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = 'No grants match your filters.';
      rowsWrap.appendChild(empty);
    }
    card.appendChild(rowsWrap);
    const footer = document.createElement('div');
    footer.className = 'card-footer';
    footer.textContent = `${data.rows.length} of ${data.total} awards`;
    card.appendChild(footer);
    app.appendChild(card);
  }

  async function refresh() {
    render(await load());
  }

  refresh();
})();
