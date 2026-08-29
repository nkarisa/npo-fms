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

  const FUND_OPTIONS = ['General Fund', 'Grant Fund', 'Capital Fund', 'Endowment Fund'];
  const RESTRICTION_OPTIONS = ['Unrestricted', 'Restricted', 'Endowment'];
  const PROGRAM_OPTIONS = ['Shared', 'Shared services', 'Election Observation', 'Civic Education', 'Governance Advocacy', 'Youth and Gender Inclusion'];
  const TYPE_OPTIONS = ['Asset', 'Liability', 'Equity', 'Income', 'Expense'];
  const GRANT_OPTIONS = ['Unassigned', 'USAID / Uraia 2026', 'DANIDA CE-2025/27', 'UNDP Basket 2026', 'Ford Foundation GA-24', 'EU Delegation KE-2026'];
  const FUNDER_OPTIONS = ['—', 'USAID / Uraia', 'DANIDA', 'UNDP Kenya', 'Ford Foundation', 'EU Delegation', 'Multiple'];
  const CURRENCY_OPTIONS = [['KES', 'KES — Kenyan Shilling'], ['USD', 'USD — US Dollar'], ['EUR', 'EUR — Euro']];

  const field = (label, control) => `<label style="display:flex;flex-direction:column;gap:5px;font-size:11px;color:#6E7873;">${label}${control}</label>`;
  const selectEl = (name, options) => `<select name="${name}" style="border:1px solid #DDDAD2;border-radius:6px;padding:7px 9px;font-size:12.5px;background:#fff;margin-top:4px;">${options}</select>`;
  const inputEl = (name, placeholder, extra) => `<input name="${name}" type="text" placeholder="${placeholder || ''}" style="border:1px solid #DDDAD2;border-radius:6px;padding:7px 9px;font-size:12.5px;margin-top:4px;" ${extra || ''}>`;
  const sectionLabel = (text, note) => `<div style="display:flex;align-items:baseline;gap:8px;"><div style="font-size:10px;letter-spacing:0.09em;text-transform:uppercase;color:#8B948F;">${text}</div>${note ? `<div style="font-size:10.5px;color:#A3ABA7;">${note}</div>` : ''}</div>`;
  const divider = '<div style="height:1px;background:#EEEDE8;"></div>';

  let drawer;
  let drawerState = { mode: 'create', code: null };

  function buildDrawer() {
    const parentOptions = ['— (top level)'].concat(
      data.accounts.filter(a => a.level < 2).map(a => `${a.code} · ${a.name}`)
    );

    drawer = document.createElement('div');
    drawer.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(13,27,24,.28);z-index:1000;align-items:flex-start;justify-content:flex-end;';
    drawer.innerHTML = `
      <div style="background:#fff;width:480px;max-width:100%;height:100%;overflow-y:auto;box-shadow:-16px 0 40px rgba(13,27,24,.12);">
        <div style="padding:18px 22px 14px;border-bottom:1px solid #EEEDE8;display:flex;align-items:flex-start;gap:12px;">
          <div>
            <div id="coa-drawer-kicker" style="font-size:10px;letter-spacing:0.09em;text-transform:uppercase;color:#8B948F;">New account</div>
            <div id="coa-drawer-title" style="font-size:16px;font-weight:600;letter-spacing:-0.015em;">Add an account to the chart</div>
          </div>
          <button type="button" id="coa-drawer-close" style="margin-left:auto;border:none;background:transparent;color:#8B948F;font-size:16px;cursor:pointer;">✕</button>
        </div>
        <form id="coa-new-form" style="padding:18px 22px 24px;display:flex;flex-direction:column;gap:18px;">
          <div id="coa-form-error" style="color:#A5442F;font-size:12px;display:none;"></div>

          ${sectionLabel('Identification')}
          <div style="display:grid;grid-template-columns:118px 1fr;gap:10px;margin-top:-8px;">
            ${field('Account code', inputEl('code', 'e.g. 5350', 'required'))}
            ${field('Account name', inputEl('name', 'Account name', 'required'))}
          </div>
          ${field('Parent account', selectEl('parent', parentOptions.map(p => `<option>${p}</option>`).join('')))}

          ${divider}
          ${sectionLabel('Classification (IFRS)')}
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:-8px;">
            ${field('Account type', selectEl('type', TYPE_OPTIONS.map(t => `<option>${t}</option>`).join('')))}
            ${field('Normal balance', selectEl('normal', '<option>Debit</option><option>Credit</option>'))}
            ${field('Restriction class', selectEl('restriction', RESTRICTION_OPTIONS.map(o => `<option>${o}</option>`).join('')))}
            ${field('Currency', selectEl('currency', CURRENCY_OPTIONS.map(([v, l]) => `<option value="${v}">${l}</option>`).join('')))}
          </div>

          ${divider}
          ${sectionLabel('Dimensions', 'required at posting')}
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:-8px;">
            ${field('Fund', selectEl('fund', FUND_OPTIONS.map(o => `<option>${o}</option>`).join('')))}
            ${field('Programme', selectEl('program', PROGRAM_OPTIONS.map(o => `<option>${o}</option>`).join('')))}
            ${field('Grant / award', selectEl('grant', GRANT_OPTIONS.map(o => `<option>${o}</option>`).join('')))}
            ${field('Funder', selectEl('funder', FUNDER_OPTIONS.map(o => `<option>${o}</option>`).join('')))}
          </div>

          ${divider}
          ${sectionLabel('Posting rules')}
          <div style="display:flex;flex-direction:column;gap:8px;border:1px solid #EEEDE8;border-radius:7px;padding:12px 13px;background:#FBFAF7;margin-top:-8px;">
            <label style="display:flex;align-items:center;gap:9px;font-size:12px;cursor:pointer;"><input type="checkbox" name="postable" checked style="accent-color:#0F5C4A;width:14px;height:14px;">Allow direct posting to this account</label>
            <label style="display:flex;align-items:center;gap:9px;font-size:12px;cursor:pointer;"><input type="checkbox" name="reconcile" style="accent-color:#0F5C4A;width:14px;height:14px;">Require monthly reconciliation</label>
            <label style="display:flex;align-items:center;gap:9px;font-size:12px;cursor:pointer;"><input type="checkbox" name="donorReport" checked style="accent-color:#0F5C4A;width:14px;height:14px;">Include in donor expenditure reports</label>
          </div>
          ${field('Description', '<textarea name="notes" rows="3" style="border:1px solid #DDDAD2;border-radius:6px;padding:8px 9px;font-size:12.5px;resize:vertical;margin-top:4px;"></textarea>')}

          <div style="display:flex;gap:8px;align-items:center;">
            <button type="button" id="coa-drawer-archive" class="btn" style="display:none;border-color:#E0D7D2;color:#8A6A5C;">Archive</button>
            <button type="submit" id="coa-drawer-submit" class="btn btn-primary" style="margin-left:auto;">Create account</button>
            <button type="button" id="coa-drawer-cancel" class="btn">Cancel</button>
          </div>
        </form>
      </div>`;
    document.body.appendChild(drawer);
    drawer.querySelector('#coa-drawer-close').addEventListener('click', closeDrawer);
    drawer.querySelector('#coa-drawer-cancel').addEventListener('click', closeDrawer);
    drawer.addEventListener('click', (e) => { if (e.target === drawer) closeDrawer(); });
    drawer.querySelector('#coa-new-form').addEventListener('submit', onSubmitDrawer);
    drawer.querySelector('#coa-drawer-archive').addEventListener('click', onArchive);
  }

  function openCreateDrawer() {
    drawerState = { mode: 'create', code: null };
    const form = drawer.querySelector('#coa-new-form');
    form.reset();
    form.querySelector('[name=code]').disabled = false;
    drawer.querySelector('#coa-form-error').style.display = 'none';
    drawer.querySelector('#coa-drawer-kicker').textContent = 'New account';
    drawer.querySelector('#coa-drawer-title').textContent = 'Add an account to the chart';
    drawer.querySelector('#coa-drawer-submit').textContent = 'Create account';
    drawer.querySelector('#coa-drawer-archive').style.display = 'none';
    drawer.style.display = 'flex';
  }

  function openEditDrawer(a, isHeader, bal) {
    drawerState = { mode: 'edit', code: a.code };
    const form = drawer.querySelector('#coa-new-form');
    form.reset();
    form.querySelector('[name=code]').value = a.code;
    form.querySelector('[name=code]').disabled = true;
    form.querySelector('[name=name]').value = a.name;
    const pIdx = parentOf(data.accounts, data.accounts.indexOf(a));
    const parentAccount = pIdx !== null ? data.accounts[pIdx] : null;
    form.querySelector('[name=parent]').value = parentAccount ? `${parentAccount.code} · ${parentAccount.name}` : '— (top level)';
    form.querySelector('[name=type]').value = a.type;
    form.querySelector('[name=normal]').value = a.normal;
    form.querySelector('[name=restriction]').value = a.restriction === '—' ? 'Unrestricted' : a.restriction;
    form.querySelector('[name=currency]').value = a.currency || 'KES';
    form.querySelector('[name=fund]').value = a.fund;
    form.querySelector('[name=program]').value = a.program === '—' ? 'Shared' : a.program;
    form.querySelector('[name=grant]').value = a.grant || 'Unassigned';
    form.querySelector('[name=funder]').value = a.funder;
    form.querySelector('[name=notes]').value = a.notes || '';
    form.querySelector('[name=postable]').checked = a.postable !== undefined ? a.postable : !isHeader;
    form.querySelector('[name=reconcile]').checked = !!a.reconcile;
    form.querySelector('[name=donorReport]').checked = a.donorReport !== false;

    drawer.querySelector('#coa-form-error').style.display = 'none';
    drawer.querySelector('#coa-drawer-kicker').textContent = isHeader
      ? `Edit summary account · ${a.code} · rolled-up balance ${UI.fmtMoney(bal)}`
      : `Edit account · ${a.code}`;
    drawer.querySelector('#coa-drawer-title').textContent = a.name;
    drawer.querySelector('#coa-drawer-submit').textContent = 'Save changes';
    drawer.querySelector('#coa-drawer-archive').style.display = a.status === 'Archived' ? 'none' : 'inline-block';
    drawer.style.display = 'flex';
  }

  function closeDrawer() {
    drawer.style.display = 'none';
  }

  async function onArchive() {
    if (!confirm(`Archive ${drawerState.code}? Historical postings are retained.`)) return;
    const res = await fetch(`/api/coa/${encodeURIComponent(drawerState.code)}/archive`, { method: 'POST' });
    const body = await res.json();
    if (!res.ok) {
      UI.toast(body.error || 'Could not archive the account.');
      return;
    }
    closeDrawer();
    UI.toast(`${drawerState.code} archived. Historical postings are retained.`);
    data = await UI.fetchJSON('/api/coa');
    render();
  }

  async function onSubmitDrawer(e) {
    e.preventDefault();
    const form = e.target;
    const errorBox = form.querySelector('#coa-form-error');
    const fd = new FormData(form);
    const payload = Object.fromEntries(fd.entries());
    payload.postable = fd.has('postable');
    payload.reconcile = fd.has('reconcile');
    payload.donorReport = fd.has('donorReport');

    const isEdit = drawerState.mode === 'edit';
    const url = isEdit ? `/api/coa/${encodeURIComponent(drawerState.code)}` : '/api/coa';
    try {
      const res = await fetch(url, {
        method: isEdit ? 'PUT' : 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(payload),
      });
      const body = await res.json();
      if (!res.ok) {
        errorBox.textContent = body.error || 'Could not save the account.';
        errorBox.style.display = 'block';
        return;
      }
      closeDrawer();
      UI.toast(isEdit ? `Changes to ${body.account.code} saved.` : `${body.account.code} ${body.account.name} added to the chart of accounts.`);
      data = await UI.fetchJSON('/api/coa');
      render();
    } catch (err) {
      errorBox.textContent = 'Could not reach the server.';
      errorBox.style.display = 'block';
    }
  }

  function render() {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Shared master chart',
      title: 'Chart of accounts',
      blurb: 'One chart, five entities. Every posting carries a fund, a programme and — where relevant — a grant.',
      actions: '<a class="btn" href="/api/coa/export" download>Export CSV</a><button class="btn btn-primary" id="coa-new-account">New account</button>',
    });
    app.appendChild(UI.statGrid(data.stats));

    document.getElementById('coa-new-account').addEventListener('click', openCreateDrawer);

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
    if (state.view === 'tree') {
      const toggleBtn = document.createElement('button');
      toggleBtn.type = 'button';
      toggleBtn.className = 'btn';
      toggleBtn.id = 'toggle-all';
      toggleBtn.textContent = anyCollapsed() ? 'Expand all' : 'Collapse all';
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
      toolbar.appendChild(toggleBtn);
    }
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
          <td><span style="display:inline-block;width:${indent}px;"></span><span class="coa-chevron" style="display:inline-block;width:14px;color:#7A857F;${isHeader ? 'cursor:pointer;' : ''}">${chev}</span>${UI.esc(a.code)}</td>
          <td>${UI.esc(a.name)}</td>
          <td>${UI.esc(a.type)}</td>
          <td>${UI.esc(a.normal)}</td>
          <td>${UI.esc(a.fund)}</td>
          <td>${UI.esc(a.program)}</td>
          <td>${UI.esc(a.funder)}</td>
          <td class="num">${UI.fmtMoney(bal)}</td>`;
        if (isHeader) {
          tr.querySelector('.coa-chevron').addEventListener('click', (e) => {
            e.stopPropagation();
            state.collapsed = { ...state.collapsed, [a.code]: !state.collapsed[a.code] };
            render();
          });
        }
        tr.addEventListener('click', () => openEditDrawer(a, isHeader, bal));
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
  }

  data = await UI.fetchJSON('/api/coa');
  buildDrawer();
  render();
})();

