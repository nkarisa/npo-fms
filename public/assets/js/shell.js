/**
 * The shell's top bar and sidebar: collapse, language, search, notifications and
 * the user menu. Runs on every page, before the page script.
 */
(function () {
  const $ = (id) => document.getElementById(id);

  // ---- Popovers: one open at a time, closed by an outside click or Escape ----

  const menus = ['lang-menu', 'notif-menu', 'user-menu'];

  function closeMenus(except) {
    menus.forEach((id) => { if (id !== except && $(id)) $(id).hidden = true; });
  }

  async function toggleMenu(id, render) {
    const menu = $(id);
    if (!menu) return;
    const opening = menu.hidden;
    closeMenus(id);
    if (!opening) { menu.hidden = true; return; }
    menu.hidden = false;
    menu.innerHTML = '<div class="tb-menu-empty">Loading…</div>';
    try {
      await render(menu);
    } catch (err) {
      menu.innerHTML = `<div class="tb-menu-empty">${UI.esc(err.message)}</div>`;
    }
  }

  document.addEventListener('click', (e) => {
    if (!e.target.closest('.tb-pop')) closeMenus();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { closeMenus(); closePalette(); }
  });

  // ---- Sidebar collapse, remembered per browser ----

  const toggle = $('nav-toggle');
  function syncToggle() {
    const collapsed = document.documentElement.classList.contains('nav-collapsed');
    toggle.textContent = collapsed ? '›' : '‹';
    toggle.title = collapsed ? 'Expand the menu' : 'Collapse the menu';
    toggle.setAttribute('aria-label', toggle.title);
  }
  if (toggle) {
    syncToggle();
    toggle.addEventListener('click', () => {
      const collapsed = document.documentElement.classList.toggle('nav-collapsed');
      hideTip();
      try { localStorage.setItem('elog.nav', collapsed ? 'collapsed' : 'open'); } catch (e) { /* private mode */ }
      syncToggle();
    });
  }

  // ---- Menu tooltips while the sidebar is collapsed ----
  // The tip lives on <body> so the collapsed menu keeps its own scrollbar and the tip is never clipped by it.

  const tip = document.createElement('div');
  tip.id = 'rail-tip';
  tip.setAttribute('role', 'tooltip');
  document.body.appendChild(tip);

  function showTip(el) {
    const label = el.getAttribute('aria-label');
    if (!label || !document.documentElement.classList.contains('nav-collapsed')) return;
    const r = el.getBoundingClientRect();
    const rtl = document.documentElement.dir === 'rtl';
    tip.textContent = label;
    tip.style.top = Math.round(r.top + r.height / 2 - tip.offsetHeight / 2) + 'px';
    tip.style.left = rtl ? Math.round(r.left - 8 - tip.offsetWidth) + 'px' : Math.round(r.right + 8) + 'px';
    tip.dataset.on = '1';
  }
  const hideTip = () => { delete tip.dataset.on; };
  const railItem = (e) => (e.target.closest ? e.target.closest('[data-rail]') : null);

  document.addEventListener('mouseover', (e) => { const el = railItem(e); el ? showTip(el) : hideTip(); });
  document.addEventListener('focusin', (e) => { const el = railItem(e); el ? showTip(el) : hideTip(); });
  document.addEventListener('focusout', hideTip);
  window.addEventListener('scroll', hideTip, true);

  // ---- Interface language ----

  const COOKIE_AGE = 60 * 60 * 24 * 365;
  const setCookie = (name, value) => { document.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=${COOKIE_AGE}; samesite=lax`; };

  $('lang-btn')?.addEventListener('click', () => toggleMenu('lang-menu', async (menu) => {
    const data = await UI.fetchJSON('/api/i18n');
    const covColour = (c) => (c >= 95 ? '#2C6B58' : c >= 80 ? '#8A6A2E' : '#A45B3E');

    menu.innerHTML = `
      <div class="tb-menu-head">
        <div class="tb-menu-title">Interface language</div>
        <div class="tb-menu-sub">Changes what you read. Amounts, dates and account codes stay as posted.</div>
      </div>
      <div class="tb-menu-list">
        ${data.locales.map((l) => `
          <button type="button" class="tb-lang-item ${l.current ? 'is-current' : ''}" data-code="${UI.esc(l.code)}">
            <span class="tb-lang-tick">${l.current ? '✓' : ''}</span>
            <span class="tb-lang-names">
              <span class="tb-lang-native">${UI.esc(l.native)}</span>
              <span class="tb-lang-sub">${UI.esc(l.source ? 'Source language · ' + l.label : l.label + (l.dir === 'rtl' ? ' · right to left' : '') + ' · ' + l.status.toLowerCase())}</span>
            </span>
            <span class="tb-lang-pct" style="color:${covColour(l.coverage)}">${l.coverage}%</span>
          </button>`).join('')}
      </div>
      <div class="tb-menu-foot">
        <a class="tb-menu-link" href="/settings?section=Language+and+translation">⚙ Language and translation settings</a>
      </div>`;

    menu.querySelectorAll('.tb-lang-item').forEach((btn) => btn.addEventListener('click', () => {
      // The API reads this cookie, and the shell sets lang/dir from it, so a
      // reload renders every endpoint and the layout direction in the new language.
      setCookie('elog_locale', btn.dataset.code);
      window.location.reload();
    }));
  }));

  // ---- Notifications ----

  async function refreshBadge() {
    try {
      const data = await UI.fetchJSON('/api/notifications');
      paintBadge(data.unread);
    } catch (e) { /* the bell is secondary; a failed count must not break the page */ }
  }

  function paintBadge(unread) {
    const badge = $('notif-badge');
    if (!badge) return;
    badge.hidden = unread === 0;
    badge.textContent = unread;
  }

  let unreadOnly = false;

  async function renderNotifications(menu) {
    const data = await UI.fetchJSON('/api/notifications?unread=' + unreadOnly);
    paintBadge(data.unread);
    const dot = (n) => (!n.unread ? '#E4E2DB' : n.tone === 'alert' ? '#A6412F' : n.tone === 'action' ? '#1FA37E' : '#8B948F');
    const kindColour = (n) => (n.tone === 'alert' ? '#A6412F' : n.tone === 'action' ? '#2C6B58' : '#8B948F');

    let lastDay = null;
    const items = data.rows.map((n) => {
      const head = n.day !== lastDay ? `<div class="tb-notif-day">${UI.esc(n.day)}</div>` : '';
      lastDay = n.day;
      return head + `
        <a class="tb-notif ${n.unread ? 'is-unread' : ''}" href="${UI.esc(n.href)}" data-id="${UI.esc(n.id)}">
          <span class="tb-notif-dot" style="background:${dot(n)}"></span>
          <span class="tb-notif-body">
            <span class="tb-notif-meta"><span style="color:${kindColour(n)}">${UI.esc(n.kind)}</span><span class="tb-notif-when">${UI.esc(n.when)}</span></span>
            <span class="tb-notif-title">${UI.esc(n.title)}</span>
            <span class="tb-notif-text">${UI.esc(n.body)}</span>
          </span>
        </a>`;
    }).join('');

    menu.innerHTML = `
      <div class="tb-menu-head tb-menu-head-row">
        <span class="tb-menu-title">Notifications</span>
        <span class="tb-menu-sub">${UI.esc(data.summary)}</span>
        <button type="button" class="tb-textbtn" id="notif-all">Mark all read</button>
      </div>
      <div class="tb-notif-tabs">
        <button type="button" class="tb-pill ${!unreadOnly ? 'is-on' : ''}" data-unread="false">All</button>
        <button type="button" class="tb-pill ${unreadOnly ? 'is-on' : ''}" data-unread="true">Unread ${data.unread}</button>
      </div>
      <div class="tb-notif-list">
        ${items || '<div class="tb-menu-empty">Nothing unread. You\'re caught up.</div>'}
      </div>`;

    menu.querySelector('#notif-all').addEventListener('click', async () => {
      const res = await UI.postJSON('/api/notifications/read', {});
      paintBadge(res.unread);
      renderNotifications(menu);
    });
    menu.querySelectorAll('.tb-pill').forEach((b) => b.addEventListener('click', () => {
      unreadOnly = b.dataset.unread === 'true';
      renderNotifications(menu);
    }));
    // Reading a notification marks it read before following its link.
    menu.querySelectorAll('.tb-notif').forEach((a) => a.addEventListener('click', async (e) => {
      if (!a.classList.contains('is-unread')) return;
      e.preventDefault();
      try { await UI.postJSON('/api/notifications/read', { id: a.dataset.id }); } catch (err) { /* follow the link regardless */ }
      window.location.href = a.getAttribute('href');
    }));
  }

  $('notif-btn')?.addEventListener('click', () => toggleMenu('notif-menu', renderNotifications));

  // ---- User menu ----

  async function loadMe() {
    try {
      const data = await UI.fetchJSON('/api/me');
      if ($('user-initials')) $('user-initials').textContent = data.me.initials;
      return data;
    } catch (e) { return null; }
  }

  $('user-btn')?.addEventListener('click', () => toggleMenu('user-menu', async (menu) => {
    const data = await UI.fetchJSON('/api/me');
    const me = data.me;

    menu.innerHTML = `
      <div class="tb-user-head">
        <div class="tb-user-avatar">${UI.esc(me.initials)}</div>
        <div class="tb-user-id">
          <div class="tb-user-name">${UI.esc(me.name)}</div>
          <div class="tb-user-email">${UI.esc(me.email)}</div>
          <div class="tb-user-role">${UI.esc(me.role)}</div>
        </div>
      </div>
      <div class="tb-actas">
        <div class="tb-menu-label">Act as</div>
        <div class="tb-menu-sub">${UI.esc(data.note)}</div>
        ${data.actors.map((a) => `
          <button type="button" class="tb-actor ${a.current ? 'is-current' : ''}" data-email="${UI.esc(a.email)}">
            <span class="tb-actor-dot">${UI.esc(a.initials)}</span>
            <span class="tb-actor-names"><span class="tb-actor-short">${UI.esc(a.short)}</span><span class="tb-actor-role">${UI.esc(a.role)}</span></span>
            <span class="tb-actor-right">${a.current ? 'Signed in' : a.canApprove ? 'Approves' : a.canPrepare ? 'Prepares' : 'Read only'}</span>
          </button>`).join('')}
      </div>
      <div class="tb-menu-list">
        ${data.menu.map((m) => (m.href
          ? `<a class="tb-menu-item" href="${UI.esc(m.href)}"><span class="tb-menu-icon">${UI.esc(m.icon)}</span>${UI.esc(m.label)}</a>`
          : `<button type="button" class="tb-menu-item" data-soon="${UI.esc(m.label)}"><span class="tb-menu-icon">${UI.esc(m.icon)}</span>${UI.esc(m.label)}</button>`)).join('')}
      </div>
      <div class="tb-menu-foot">
        <button type="button" class="tb-menu-item tb-signout" id="sign-out"><span class="tb-menu-icon">⏻</span>Sign out</button>
      </div>`;

    menu.querySelectorAll('.tb-actor').forEach((b) => b.addEventListener('click', async () => {
      if (b.classList.contains('is-current')) return;
      await UI.postJSON('/api/me/act-as', { email: b.dataset.email });
      window.location.reload();
    }));
    // No profile, entity switching or help pages exist yet — say so plainly
    // rather than opening a dead end.
    menu.querySelectorAll('[data-soon]').forEach((b) => b.addEventListener('click', () => {
      closeMenus();
      UI.toast(`${b.dataset.soon} is not built yet.`);
    }));
    menu.querySelector('#sign-out').addEventListener('click', () => {
      closeMenus();
      UI.toast('Sign-in is not built yet, so there is no session to end.');
    });
  }));

  // ---- Search everything (⌘K) ----

  let palette;
  let searchTimer;
  let searchSeq = 0;

  function buildPalette() {
    palette = document.createElement('div');
    palette.className = 'gs-scrim';
    palette.hidden = true;
    palette.innerHTML = `
      <div class="gs-panel" role="dialog" aria-label="Search everything">
        <div class="gs-input-row">
          <span class="gs-glyph">⌕</span>
          <input class="gs-input" type="search" placeholder="Search journals, accounts, suppliers, donors, awards…" autocomplete="off">
          <span class="tb-kbd">esc</span>
        </div>
        <div class="gs-results"></div>
      </div>`;
    document.body.appendChild(palette);
    palette.addEventListener('click', (e) => { if (e.target === palette) closePalette(); });
    palette.querySelector('.gs-input').addEventListener('input', (e) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => runSearch(e.target.value), 160);
    });
    hint('Type at least two characters.');
  }

  function hint(text) {
    palette.querySelector('.gs-results').innerHTML = `<div class="gs-empty">${UI.esc(text)}</div>`;
  }

  async function runSearch(q) {
    if (q.trim().length < 2) { hint('Type at least two characters.'); return; }
    // Results can arrive out of order when typing fast; only paint the latest.
    const seq = ++searchSeq;
    const data = await UI.fetchJSON('/api/search?q=' + encodeURIComponent(q));
    if (seq !== searchSeq) return;
    if (!data.groups.length) { hint(`Nothing matches "${q}".`); return; }

    palette.querySelector('.gs-results').innerHTML = data.groups.map((g) => `
      <div class="gs-group">
        <div class="gs-group-label">${UI.esc(g.label)}${g.more ? ` <span class="gs-more">+${g.more} more</span>` : ''}</div>
        ${g.items.map((i) => `
          <a class="gs-item" href="${UI.esc(i.href)}">
            <span class="gs-ref">${UI.esc(i.ref)}</span>
            <span class="gs-text"><span class="gs-title">${UI.esc(i.title)}</span><span class="gs-sub">${UI.esc(i.sub)}</span></span>
            <span class="gs-value">${UI.esc(i.value)}</span>
          </a>`).join('')}
      </div>`).join('');
  }

  function openPalette() {
    if (!palette) buildPalette();
    closeMenus();
    palette.hidden = false;
    const input = palette.querySelector('.gs-input');
    input.value = '';
    hint('Type at least two characters.');
    input.focus();
  }

  function closePalette() {
    if (palette) palette.hidden = true;
  }

  $('search-btn')?.addEventListener('click', openPalette);
  document.addEventListener('keydown', (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      palette && !palette.hidden ? closePalette() : openPalette();
    }
  });

  refreshBadge();
  loadMe();
})();
