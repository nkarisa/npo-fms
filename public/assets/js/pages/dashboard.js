(async function () {
  const app = document.getElementById('app');
  const data = await UI.fetchJSON('/api/dashboard');

  UI.pageHead(app, {
    kicker: data.date,
    title: 'Finance overview',
    blurb: 'One reconciling book — drawn from the ledger as it stands this morning.',
  });

  app.appendChild(UI.statGrid(data.stats));

  const grid = document.createElement('div');
  grid.className = 'two-col';

  // Needs a decision from you
  const queueCard = document.createElement('div');
  queueCard.className = 'card';
  queueCard.innerHTML = `<div class="card-head"><span class="card-title">Needs a decision from you</span><span class="card-hint">${data.queue.length} items open</span></div>`;
  if (data.queue.length) {
    const body = document.createElement('div');
    data.queue.forEach(q => {
      const row = document.createElement('a');
      row.href = q.href || '#';
      row.style.cssText = 'display:grid;grid-template-columns:minmax(0,1fr) auto auto;align-items:center;gap:14px;padding:12px 16px;border-bottom:1px solid #F2F1EC;text-decoration:none;color:inherit;';
      row.innerHTML = `
        <div>
          <div><span class="dot ${q.tone}"></span><strong>${UI.esc(q.title)}</strong></div>
          <div class="muted" style="font-size:11.5px;margin-top:2px;">${UI.esc(q.detail)}</div>
        </div>
        <div style="font-family:'IBM Plex Mono',monospace;font-weight:600;">${UI.esc(q.value)}</div>
        <div style="font-size:11px;color:#0F5C4A;font-weight:600;white-space:nowrap;">Open →</div>`;
      body.appendChild(row);
    });
    queueCard.appendChild(body);
  } else {
    queueCard.innerHTML += `<div class="empty-state">Nothing is waiting on you.</div>`;
  }

  // Grant burn
  const grantCard = document.createElement('div');
  grantCard.className = 'card';
  grantCard.innerHTML = `<div class="card-head"><span class="card-title">Grant burn against elapsed time</span></div>`;
  const gBody = document.createElement('div');
  data.grants.forEach(g => {
    const row = document.createElement('a');
    row.href = `/grants?grant=${encodeURIComponent(g.ref)}`;
    row.style.cssText = 'display:block;padding:12px 16px;border-bottom:1px solid #F2F1EC;text-decoration:none;color:inherit;';
    row.innerHTML = `
      <div style="display:flex;align-items:baseline;gap:10px;margin-bottom:8px;">
        <strong>${UI.esc(g.funder)}</strong><span class="muted" style="flex:1;">${UI.esc(g.program)}</span>
        <span style="font-family:'IBM Plex Mono',monospace;">${UI.esc(g.money)}</span>
      </div>
      <div class="bar-track">
        <div class="bar-fill" style="width:${g.burnPct}%;"></div>
        <div class="bar-mark" style="left:${g.elapsed}%;"></div>
      </div>`;
    gBody.appendChild(row);
  });
  grantCard.appendChild(gBody);

  const left = document.createElement('div');
  left.appendChild(queueCard);
  left.appendChild(grantCard);

  const right = document.createElement('div');
  const fundCard = document.createElement('div');
  fundCard.className = 'card';
  fundCard.innerHTML = `<div class="card-head"><span class="card-title">Top funds</span></div>`;
  const fBody = document.createElement('div');
  fBody.style.padding = '12px 16px';
  data.funds.forEach(f => {
    const row = document.createElement('div');
    row.style.cssText = 'display:flex;justify-content:space-between;gap:10px;padding:6px 0;font-size:12px;';
    row.innerHTML = `<span>${UI.esc(f.name)}</span><span style="font-family:'IBM Plex Mono',monospace;">${UI.esc(f.value)}</span>`;
    fBody.appendChild(row);
  });
  fundCard.appendChild(fBody);

  const activityCard = document.createElement('div');
  activityCard.className = 'card';
  activityCard.innerHTML = `<div class="card-head"><span class="card-title">Recent activity</span></div>`;
  const aBody = document.createElement('div');
  aBody.style.padding = '12px 16px';
  data.activity.forEach(a => {
    const row = document.createElement('div');
    row.style.cssText = 'padding:6px 0;border-bottom:1px solid #F4F3EE;font-size:12px;';
    row.innerHTML = `<div>${UI.esc(a.what)}</div><div class="muted" style="font-size:11px;">${UI.esc(a.when)} · ${UI.esc(a.who)} · ${UI.esc(a.area)}</div>`;
    aBody.appendChild(row);
  });
  activityCard.appendChild(aBody);

  right.appendChild(fundCard);
  right.appendChild(activityCard);

  grid.appendChild(left);
  grid.appendChild(right);
  app.appendChild(grid);
})();
