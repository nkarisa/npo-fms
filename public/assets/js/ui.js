/** Small render helpers shared by every page script. No framework — just fetch + DOM. */
const UI = (() => {
  const fmtMoney = (n) => {
    if (n === null || n === undefined) return '—';
    if (typeof n === 'string') return n;
    if (n === 0) return '—';
    const s = Math.abs(Math.round(n)).toLocaleString('en-US');
    return n < 0 ? `(${s})` : s;
  };

  async function fetchJSON(url) {
    const res = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error(`Request failed: ${res.status}`);
    return res.json();
  }

  function toast(msg) {
    const el = document.getElementById('toast');
    if (!el) return;
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => el.classList.remove('show'), 2400);
  }

  function statGrid(stats) {
    const div = document.createElement('div');
    div.className = 'stat-grid';
    div.innerHTML = stats.map(s => `
      <div class="stat">
        <div class="stat-label">${esc(s.label)}</div>
        <div class="stat-value">${esc(s.value)}</div>
        <div class="stat-note">${esc(s.note || '')}</div>
      </div>`).join('');
    return div;
  }

  function tabs(items, activeLabel, onClick) {
    const div = document.createElement('div');
    div.className = 'tabs';
    div.innerHTML = items.map(t => {
      const label = typeof t === 'string' ? t : t.label;
      const count = typeof t === 'object' && t.count !== undefined ? ` (${t.count})` : '';
      const active = label === activeLabel ? 'active' : '';
      return `<button type="button" class="tab ${active}" data-tab="${esc(label)}">${esc(label)}${count}</button>`;
    }).join('');
    div.querySelectorAll('.tab').forEach(btn => btn.addEventListener('click', () => onClick(btn.dataset.tab)));
    return div;
  }

  function table(columns, rows, onRowClick) {
    const wrap = document.createElement('table');
    wrap.className = 'data';
    const thead = `<thead><tr>${columns.map(c => `<th class="${c.num ? 'num' : ''}">${esc(c.label)}</th>`).join('')}</tr></thead>`;
    const tbody = rows.length
      ? `<tbody>${rows.map((r, i) => `<tr data-i="${i}">${columns.map(c => `<td class="${c.num ? 'num' : ''}">${c.render ? c.render(r) : esc(r[c.key] ?? '')}</td>`).join('')}</tr>`).join('')}</tbody>`
      : '';
    wrap.innerHTML = thead + tbody;
    if (onRowClick) {
      wrap.querySelectorAll('tbody tr').forEach(tr => tr.addEventListener('click', () => onRowClick(rows[+tr.dataset.i])));
    }
    return wrap;
  }

  function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  }

  function badge(text, tone) {
    return `<span class="badge ${tone || 'plain'}">${esc(text)}</span>`;
  }

  function pageHead(container, { kicker, title, blurb, actions }) {
    const div = document.createElement('div');
    div.className = 'page-head';
    div.innerHTML = `
      <div>
        <div class="page-kicker">${esc(kicker || '')}</div>
        <h1 class="page-title">${esc(title)}</h1>
        <p class="page-blurb">${esc(blurb || '')}</p>
      </div>
      <div class="page-actions">${actions || ''}</div>`;
    container.appendChild(div);
  }

  return { fmtMoney, fetchJSON, toast, statGrid, tabs, table, esc, badge, pageHead };
})();
