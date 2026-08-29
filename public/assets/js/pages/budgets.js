(async function () {
  const app = document.getElementById('app');
  let state = { group: 'Account group', q: '' };

  async function load() {
    const p = new URLSearchParams({ group: state.group, q: state.q });
    return UI.fetchJSON('/api/budgets?' + p.toString());
  }

  function render(data) {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Funds and grants',
      title: 'Budgets',
      blurb: 'Annual budget phased against elapsed time, and actuals posted to the ledger.',
    });
    app.appendChild(UI.statGrid(data.stats));

    const card = document.createElement('div');
    card.className = 'card';
    const toolbar = document.createElement('div');
    toolbar.className = 'toolbar';
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Search budget lines…';
    search.value = state.q;
    search.addEventListener('input', (e) => { state.q = e.target.value; refresh(); });
    const groupSelect = document.createElement('select');
    groupSelect.innerHTML = data.groupOptions.map(g => `<option ${g === state.group ? 'selected' : ''}>${UI.esc(g)}</option>`).join('');
    groupSelect.addEventListener('change', (e) => { state.group = e.target.value; refresh(); });
    toolbar.append(search, groupSelect);
    card.appendChild(toolbar);

    const cols = [
      { label: 'Code', key: 'code' },
      { label: 'Name', key: 'name' },
      { label: 'Fund', key: 'fund' },
      { label: 'Programme', key: 'program' },
      { label: 'Annual', num: true, key: 'annualFmt' },
      { label: 'Phased', num: true, key: 'phasedFmt' },
      { label: 'Actual', num: true, key: 'actualFmt' },
      { label: 'Variance', num: true, key: 'varianceFmt' },
      { label: 'Status', render: (r) => UI.badge(r.status, r.status === 'Over' ? 'urgent' : r.status === 'Watch' ? 'warn' : r.status === 'Underspent' ? 'plain' : 'calm') },
    ];

    data.groups.forEach(g => {
      const groupHead = document.createElement('div');
      groupHead.style.cssText = 'padding:9px 16px;background:#FAF9F6;border-bottom:1px solid #EEEDE8;font-weight:600;font-size:12px;display:flex;justify-content:space-between;';
      groupHead.innerHTML = `<span>${UI.esc(g.label)}</span><span style="font-family:'IBM Plex Mono',monospace;">${UI.esc(g.annual)} annual · ${UI.esc(g.actual)} actual (${g.pct}%)</span>`;
      card.appendChild(groupHead);
      card.appendChild(UI.table(cols, g.lines));
    });

    if (!data.groups.length) {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = 'No budget lines match your search.';
      card.appendChild(empty);
    }

    app.appendChild(card);
  }

  async function refresh() {
    render(await load());
  }

  refresh();
})();
