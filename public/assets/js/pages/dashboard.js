(async function () {
  const app = document.getElementById('app');
  const data = await UI.fetchJSON('/api/dashboard');

  // ---- Closed for maintenance, or a window coming ----
  //
  // Said at the top of the overview as well as in the shell bar, because this is
  // the page people open first.
  if (data.maintenance) {
    const bar = document.createElement('div');
    bar.className = 'mt-state ' + (data.maintenance.tone === 'closed' ? 'is-closed' : '');
    bar.style.marginBottom = '18px';
    bar.innerHTML = `
      <div class="mt-state-head"><span class="mt-state-dot"></span>${UI.esc(data.maintenance.title)}</div>
      <div class="mt-state-note">${UI.esc(data.maintenance.note)}</div>`;
    app.appendChild(bar);
  }

  // ---- Page head ----

  UI.pageHead(app, {
    kicker: data.date,
    title: 'Finance overview',
    blurb: data.subtitle,
    actions: `
      <button type="button" class="btn" id="dash-pack">Board pack</button>
      <button type="button" class="btn btn-primary" id="dash-journal">New journal</button>`,
  });
  document.getElementById('dash-pack').addEventListener('click', openBoardPack);
  document.getElementById('dash-journal').addEventListener('click', () => {
    // Posting from the dashboard lands the user on Journals, where the entry lives.
    UI.openNewJournalDrawer({ onSaved: () => { window.location.href = '/journals'; } });
  });

  app.appendChild(UI.statGrid(data.stats));

  const grid = document.createElement('div');
  grid.className = 'two-col';
  const left = document.createElement('div');
  left.className = 'dash-col';
  const right = document.createElement('div');
  right.className = 'dash-col';

  const card = (title, hint) => {
    const el = document.createElement('div');
    el.className = 'card';
    el.innerHTML = `<div class="card-head"><span class="card-title">${UI.esc(title)}</span>`
      + (hint ? `<span class="card-hint">${UI.esc(hint)}</span>` : '') + `</div>`;
    return el;
  };

  // ---- Needs a decision from you ----

  const queue = card('Needs a decision from you', data.queueHint);
  if (data.queue.length) {
    data.queue.forEach((q) => {
      const row = document.createElement('a');
      row.href = q.href || '#';
      row.className = 'dash-row dash-queue';
      row.innerHTML = `
        <div class="dash-queue-text">
          <div class="dash-queue-title"><span class="dot ${UI.esc(q.tone)}"></span><span class="dash-queue-label">${UI.esc(q.title)}</span></div>
          <div class="dash-sub">${UI.esc(q.detail)}</div>
        </div>
        <div class="dash-queue-value">${UI.esc(q.value)}</div>
        <div class="dash-cta">${UI.esc(q.cta)} →</div>`;
      queue.appendChild(row);
    });
  } else {
    queue.insertAdjacentHTML('beforeend', '<div class="empty-state">Nothing is waiting on you.</div>');
  }
  left.appendChild(queue);

  // ---- Grant burn against elapsed time ----

  const grants = card('Grant burn against elapsed time', 'Spend rate below the marker means the award is running behind');
  data.grants.forEach((g) => {
    const row = document.createElement('a');
    row.href = `/grants?grant=${encodeURIComponent(g.ref)}`;
    row.className = 'dash-row dash-grant';
    row.innerHTML = `
      <div class="dash-grant-head">
        <span class="dash-grant-funder">${UI.esc(g.funder)}</span>
        <span class="dash-grant-prog">${UI.esc(g.program)}</span>
        <span class="dash-grant-money">${UI.esc(g.money)}</span>
      </div>
      <div class="bar-track">
        <div class="bar-fill" style="width:${g.burnPct}%;background:${UI.esc(g.barColour)};"></div>
        <div class="bar-mark" style="inset-inline-start:${g.elapsed}%;"></div>
      </div>
      <div class="dash-grant-foot">
        <span>${UI.esc(g.burnLabel)}</span>
        <span class="dash-grant-pace">${UI.esc(g.paceLabel)}</span>
      </div>`;
    grants.appendChild(row);
  });
  grants.insertAdjacentHTML('beforeend', `<div class="dash-foot">${UI.esc(data.grantFooter)}</div>`);
  left.appendChild(grants);

  // ---- Where the money sits ----

  const funds = card('Where the money sits', data.fundHint);
  data.funds.forEach((f) => {
    const row = document.createElement('a');
    row.href = f.href;
    row.className = 'dash-row dash-fund';
    row.innerHTML = `
      <div class="dash-fund-head">
        <span class="dash-fund-name">${UI.esc(f.name)}</span>
        <span class="dash-fund-value">${UI.esc(f.value)}</span>
      </div>
      <div class="dash-fund-track"><div style="width:${f.pct}%;background:${UI.esc(f.colour)};"></div></div>`;
    funds.appendChild(row);
  });
  right.appendChild(funds);

  // ---- Does the book hold together ----

  const checks = card('Does the book hold together');
  data.checks.forEach((c) => {
    const row = document.createElement('a');
    row.href = c.href;
    row.className = 'dash-row dash-check';
    row.innerHTML = `
      <span class="dash-check-mark ${c.ok ? 'is-ok' : 'is-bad'}">${c.ok ? '✓' : '!'}</span>
      <div class="dash-check-text">
        <span class="dash-check-label">${UI.esc(c.label)}</span>
        <span class="dash-sub">${UI.esc(c.note)}</span>
      </div>`;
    checks.appendChild(row);
  });
  right.appendChild(checks);

  // ---- Recent changes to the rules ----

  const rules = card('Recent changes to the rules', 'Audit log');
  data.activity.forEach((a) => {
    rules.insertAdjacentHTML('beforeend', `
      <div class="dash-row dash-rule">
        <span class="dash-rule-what">${UI.esc(a.what)}</span>
        <span class="dash-rule-meta">${UI.esc(a.meta)}</span>
      </div>`);
  });
  rules.insertAdjacentHTML('beforeend', `<div class="dash-foot"><a href="/settings?section=Audit+log">Open the full audit log →</a></div>`);
  right.appendChild(rules);

  grid.append(left, right);
  app.appendChild(grid);

  // ---- Board pack ----

  async function openBoardPack() {
    const bp = await UI.fetchJSON('/api/dashboard/board-pack');

    UI.drawer(bp.title, `
      <div class="bp">
        <div class="bp-meta">${UI.esc(bp.meta)}</div>
        <p class="bp-intro">${UI.esc(bp.intro)}</p>
        <div class="bp-headline">
          ${bp.headline.map((h) => `
            <div class="bp-cell">
              <span class="bp-cell-label">${UI.esc(h.label)}</span>
              <span class="bp-cell-value">${UI.esc(h.value)}</span>
              <span class="bp-cell-note">${UI.esc(h.note)}</span>
            </div>`).join('')}
        </div>
        <div class="bp-head"><span>Contents</span><span class="bp-head-note">${UI.esc(bp.attention)}</span></div>
        ${bp.sections.map((s) => `
          <div class="bp-section">
            <span class="bp-no">${UI.esc(s.no)}</span>
            <span class="bp-name">${UI.esc(s.name)}</span>
            <span class="bp-figure">${UI.esc(s.figure)}</span>
          </div>`).join('')}
        <div class="bp-head bp-head-tint"><span>Matters for the board</span></div>
        ${bp.risks.map((r) => `
          <div class="bp-risk">
            <div class="bp-risk-text">
              <span class="bp-risk-area">${UI.esc(r.area)}</span>
              <span class="bp-risk-note">${UI.esc(r.note)}</span>
            </div>
            <span class="bp-state ${r.ok ? 'is-ok' : 'is-att'}">${r.ok ? 'In order' : 'Attention'}</span>
          </div>`).join('')}
      </div>
      <div class="bp-foot">
        <span class="bp-foot-note">${UI.esc(bp.footer)}</span>
        <button type="button" class="btn btn-primary" id="bp-print">Print</button>
      </div>`, { wide: true });

    // The browser's print dialog also saves to PDF, which is what "Export PDF"
    // needs until a server-side renderer exists.
    document.getElementById('bp-print').addEventListener('click', () => window.print());
  }
})();
