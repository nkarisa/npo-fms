(async function () {
  const app = document.getElementById('app');
  let data = null;
  let state = { type: 'All', q: '', view: 'tree', collapsed: {} };

  function parentOf(list, idx) {
    const level = list[idx].level;
    for (let i = idx - 1; i >= 0; i--) {
      if (list[i].level < level) return i;
    }
    return null;
  }

  function hasKids(list, idx) {
    return idx + 1 < list.length && list[idx + 1].level > list[idx].level;
  }

  function matches(a, q, type) {
    if (type !== 'All' && a.type !== type) return false;
    if (!q) return true;
    return `${a.code} ${a.name} ${a.fund} ${a.funder}`.toLowerCase().includes(q);
  }

  function visibleRows() {
    const list = data.accounts;
    const q = state.q.trim().toLowerCase();

    if (state.view === 'flat') {
      return list.filter(a => a.level === 2 && matches(a, q, state.type));
    }

    // Tree view: keep every match plus its ancestors, then hide anything under a collapsed parent.
    const keep = new Set();
    list.forEach((a, i) => {
      if (!matches(a, q, state.type)) return;
      keep.add(a.code);
      let cur = i;
      for (;;) {
        const p = parentOf(list, cur);
        if (p === null) break;
        keep.add(list[p].code);
        cur = p;
      }
    });

    const visible = [];
    list.forEach((a, i) => {
      if (!keep.has(a.code)) return;
      let cur = i, hidden = false;
      for (;;) {
        const p = parentOf(list, cur);
        if (p === null) break;
        if (state.collapsed[list[p].code]) { hidden = true; break; }
        cur = p;
      }
      if (!hidden) visible.push(a);
    });
    return visible;
  }

  function anyCollapsed() {
    return Object.keys(state.collapsed).some(k => state.collapsed[k]);
  }

  function render() {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Shared master chart',
      title: 'Chart of accounts',
      blurb: 'One chart, five entities. Every posting carries a fund, a programme and — where relevant — a grant.',
      actions: state.view === 'tree'
        ? `<button class="btn" id="toggle-all">${anyCollapsed() ? 'Expand all' : 'Collapse all'}</button>`
        : '',
    });
    app.appendChild(UI.statGrid(data.stats));

    const card = document.createElement('div');
    card.className = 'card';

    const toolbar = document.createElement('div');
    toolbar.className = 'toolbar';
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Search accounts…';
    search.value = state.q;
    search.addEventListener('input', (e) => { state.q = e.target.value; render(); });

    const viewToggle = document.createElement('div');
    viewToggle.style.cssText = 'display:flex;border:1px solid #DDDAD2;border-radius:6px;overflow:hidden;margin-left:auto;';
    viewToggle.innerHTML = `
      <button type="button" data-view="tree" class="btn" style="border:none;border-radius:0;${state.view === 'tree' ? 'background:#0F5C4A;color:#fff;' : ''}">Tree</button>
      <button type="button" data-view="flat" class="btn" style="border:none;border-radius:0;border-left:1px solid #DDDAD2;${state.view === 'flat' ? 'background:#0F5C4A;color:#fff;' : ''}">Flat</button>`;
    viewToggle.querySelectorAll('button').forEach(btn => btn.addEventListener('click', () => { state.view = btn.dataset.view; render(); }));

    toolbar.append(search, viewToggle);
    card.appendChild(toolbar);

    card.appendChild(UI.tabs(data.types.map(t => data.typeLabel[t] || t), data.typeLabel[state.type] || state.type, (label) => {
      const found = data.types.find(t => (data.typeLabel[t] || t) === label);
      state.type = found || 'All';
      render();
    }));

    const rows = visibleRows();
    if (rows.length) {
      const table = document.createElement('table');
      table.className = 'data';
      const cols = ['Code', 'Name', 'Type', 'Normal', 'Fund', 'Programme', 'Funder', 'Balance'];
      table.innerHTML = `<thead><tr>${cols.map(c => `<th class="${c === 'Balance' ? 'num' : ''}">${c}</th>`).join('')}</tr></thead>`;
      const tbody = document.createElement('tbody');
      rows.forEach((a) => {
        const idx = data.accounts.indexOf(a);
        const isHeader = state.view === 'tree' && hasKids(data.accounts, idx);
        const bal = isHeader ? (data.rollups[a.code] || 0) : a.balance;
        const indent = state.view === 'flat' ? 14 : 14 + a.level * 18;
        const chev = isHeader ? (state.collapsed[a.code] ? '▶' : '▼') : '';
        const tr = document.createElement('tr');
        if (isHeader) tr.style.fontWeight = '600';
        tr.innerHTML = `
          <td><span style="display:inline-block;width:${indent}px;"></span><span style="display:inline-block;width:14px;color:#7A857F;">${chev}</span>${UI.esc(a.code)}</td>
          <td>${UI.esc(a.name)}</td>
          <td>${UI.esc(a.type)}</td>
          <td>${UI.esc(a.normal)}</td>
          <td>${UI.esc(a.fund)}</td>
          <td>${UI.esc(a.program)}</td>
          <td>${UI.esc(a.funder)}</td>
          <td class="num">${UI.fmtMoney(bal)}</td>`;
        tr.addEventListener('click', () => {
          if (isHeader) {
            state.collapsed = { ...state.collapsed, [a.code]: !state.collapsed[a.code] };
            render();
          } else {
            window.location.href = `/gl?account=${encodeURIComponent(a.code)}`;
          }
        });
        tbody.appendChild(tr);
      });
      table.appendChild(tbody);
      card.appendChild(table);
    } else {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = 'No accounts match your search.';
      card.appendChild(empty);
    }

    const footer = document.createElement('div');
    footer.className = 'card-footer';
    const leafCount = data.accounts.filter(a => a.level === 2).length;
    footer.textContent = `${rows.length} rows · ${leafCount} postable accounts of ${data.accounts.length} total`;
    card.appendChild(footer);

    app.appendChild(card);

    const toggleBtn = document.getElementById('toggle-all');
    if (toggleBtn) {
      toggleBtn.addEventListener('click', () => {
        if (anyCollapsed()) {
          state.collapsed = {};
        } else {
          const c = {};
          data.accounts.forEach(a => { if (a.level < 2) c[a.code] = true; });
          state.collapsed = c;
        }
        render();
      });
    }
  }

  data = await UI.fetchJSON('/api/coa');
  render();
})();

