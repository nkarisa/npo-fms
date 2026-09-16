(async function () {
  const app = document.getElementById('app');
  let state = { filter: 'All', q: '' };

  async function load() {
    const p = new URLSearchParams(state);
    return UI.fetchJSON('/api/programmes?' + p.toString());
  }

  function render(data) {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Accounting',
      title: 'Programmes',
      blurb: 'The third coding dimension on every posting line, alongside account and fund.',
    });
    app.appendChild(UI.statGrid(data.stats));

    // Shared support costs apportion across programmes by this base; if it does
    // not total 100% they are over- or under-allocated every month.
    const alloc = document.createElement('div');
    alloc.className = 'card';
    const ok = data.allocation.balanced;
    alloc.style.cssText = ok ? 'background:#E7F1EC;border-color:#D3E5DC;' : 'background:#FBF1E1;border-color:#EEE2CB;';
    alloc.innerHTML = `<div style="padding:12px 16px;font-size:12px;line-height:1.55;color:${ok ? '#2C6B58' : '#8A5B2E'};">`
      + `<strong>Shared-cost allocation base ${UI.esc(data.allocation.total)}</strong>`
      + `<div style="margin-top:5px;">${UI.esc(data.allocation.note)}</div></div>`;
    app.appendChild(alloc);

    const card = document.createElement('div');
    card.className = 'card';

    const toolbar = document.createElement('div');
    toolbar.className = 'toolbar';
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Search programme or manager…';
    search.value = state.q;
    search.addEventListener('input', (e) => { state.q = e.target.value; refresh(); });
    toolbar.append(search);
    card.appendChild(toolbar);

    card.appendChild(UI.tabs(data.tabs, state.filter, (label) => { state.filter = label; refresh(); }));

    if (data.rows.length) {
      card.appendChild(UI.table([
        { label: 'Code', key: 'code' },
        { label: 'Programme', render: (r) => `${UI.esc(r.name)}<div class="muted" style="font-size:11px;">${UI.esc(r.manager)} · since ${UI.esc(r.since)}</div>` },
        { label: 'Status', render: (r) => UI.badge(r.status, r.status === 'Active' ? 'calm' : r.status === 'Pipeline' ? 'warn' : 'plain') },
        { label: 'Share', num: true, render: (r) => (r.allocatesOut ? '<span class="muted">allocates out</span>' : UI.esc(r.share)) },
        { label: 'Burn', render: (r) => UI.bar(r.burnPct, r.overspent ? 'urgent' : r.burnPct > 85 ? 'warn' : 'calm') },
        { label: 'Budget', num: true, key: 'budget' },
        { label: 'Actual', num: true, key: 'actual' },
        { label: 'Committed', num: true, key: 'committed' },
        { label: 'Available', num: true, render: (r) => `<span style="${r.overspent ? 'color:#A6412F;font-weight:600;' : ''}">${UI.esc(r.available)}</span>` },
      ], data.rows, (r) => openProgramme(r.code)));
    } else {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = 'No programmes match your filters.';
      card.appendChild(empty);
    }

    const footer = document.createElement('div');
    footer.className = 'card-footer';
    footer.textContent = data.hint;
    card.appendChild(footer);
    app.appendChild(card);
  }

  async function openProgramme(code) {
    const p = await UI.fetchJSON('/api/programmes/' + encodeURIComponent(code));

    const facts = [
      ['Manager', p.manager], ['Status', p.status], ['Running since', p.since],
      ['Share of shared costs', p.share === null ? 'Allocates out' : p.share + '%'],
      ['Budget FY2026', UI.fmtMoney(p.budget)], ['Actual', UI.fmtMoney(p.actual)],
      ['Committed', UI.fmtMoney(p.committed)], ['Available', UI.fmtMoney(p.available)],
      ['Awards', `${p.activeGrants} active of ${p.grants}`], ['Staff allocated', String(p.staff)],
    ];

    const funds = p.funds.length
      ? p.funds.map(f => `<div class="detail-row"><span class="k">${UI.esc(f)}</span></div>`).join('')
      : '<div class="empty-state" style="padding:16px;">No funds charged to this programme.</div>';

    // Deactivation is only safe once nothing further can be coded here.
    const blockers = p.blockers.length
      ? `<div style="background:#FBF1E1;color:#8A5B2E;padding:10px 12px;border-radius:6px;font-size:11.5px;line-height:1.6;margin-top:14px;">`
        + `<strong>Cannot be deactivated yet</strong><ul style="margin:6px 0 0;padding-left:16px;">`
        + p.blockers.map(b => `<li>${UI.esc(b)}</li>`).join('') + `</ul></div>`
      : (p.status === 'Inactive'
        ? ''
        : `<div style="background:#E7F1EC;color:#2C6B58;padding:10px 12px;border-radius:6px;font-size:11.5px;line-height:1.5;margin-top:14px;">Nothing further can be coded here, so this programme can be deactivated.</div>`);

    const history = p.hasHistory
      ? `<div class="muted" style="font-size:11px;margin-top:10px;line-height:1.5;">Carries ${UI.fmtMoney(p.actual)} of posted expenditure, so it can only ever be deactivated — deleting it would orphan those postings.</div>`
      : '';

    UI.drawer(`${p.code} · ${p.name}`, `
      <div style="padding:14px 18px;">
        <div class="muted" style="font-size:11.5px;line-height:1.5;margin-bottom:10px;">${UI.esc(p.purpose)}</div>
        ${facts.map(([k, v]) => `<div class="detail-row"><span class="k">${UI.esc(k)}</span><span class="v">${UI.esc(v)}</span></div>`).join('')}
        <div style="margin-top:16px;font-weight:600;font-size:12.5px;">Funds charged here</div>${funds}
        ${blockers}${history}
      </div>`);
  }

  async function refresh() {
    render(await load());
  }

  refresh();
})();
