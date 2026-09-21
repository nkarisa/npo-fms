(async function () {
  const app = document.getElementById('app');
  // ?q= arrives from an award's drawer, filtered to that award.
  let state = { status: 'All', q: new URLSearchParams(location.search).get('q') || '' };

  async function load() {
    const p = new URLSearchParams({ status: state.status, q: state.q });
    return UI.fetchJSON('/api/donor-reports?' + p.toString());
  }

  function render(data) {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Funds and grants',
      title: 'Donor reports',
      blurb: 'Financial reports to funders, reconciled against what is actually posted in the ledger.',
    });
    app.appendChild(UI.statGrid(data.stats));

    const card = document.createElement('div');
    card.className = 'card';
    const toolbar = document.createElement('div');
    toolbar.className = 'toolbar';
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Search reports…';
    search.value = state.q;
    search.addEventListener('input', (e) => { state.q = e.target.value; refresh(); });
    toolbar.appendChild(search);
    card.appendChild(toolbar);

    card.appendChild(UI.tabs(data.tabs, data.tabs.find(t => t.label === state.status)?.label, (label) => { state.status = label; refresh(); }));

    const cols = [
      { label: 'Ref', key: 'ref' },
      { label: 'Title', key: 'title' },
      { label: 'Funder', key: 'funder' },
      { label: 'Period', key: 'period' },
      { label: 'Due', key: 'due' },
      { label: 'Status', render: (r) => UI.badge(r.status, r.status === 'Submitted' || r.status === 'Accepted' ? 'calm' : r.status === 'Overdue' || r.status === 'Queried' ? 'urgent' : 'warn') },
      { label: 'Reported', num: true, key: 'reported' },
      { label: 'Ledger actual', num: true, key: 'actual' },
      { label: 'Ties?', render: (r) => r.tied ? UI.badge('Ties', 'calm') : UI.badge('Does not tie', 'urgent') },
    ];
    if (data.rows.length) {
      card.appendChild(UI.table(cols, data.rows, (r) => openReport(r.ref)));
    } else {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = 'No reports match your filters.';
      card.appendChild(empty);
    }
    const footer = document.createElement('div');
    footer.className = 'card-footer';
    footer.textContent = `${data.rows.length} of ${data.total} reports`;
    card.appendChild(footer);
    app.appendChild(card);
  }

  /** A report's position against the ledger, and the documents filed with it. */
  async function openReport(ref) {
    let r;
    try {
      r = await UI.fetchJSON('/api/donor-reports/' + ref.split('/').map(encodeURIComponent).join('/'));
    } catch (err) {
      return UI.toast(err.message);
    }
    const esc = UI.esc;
    const facts = [
      ['Award', r.grant + (r.grantRef ? ' · ' + r.grantRef : '')], ['Funder', r.funder], ['Type', r.type], ['Period', r.period],
      ['Due', r.due], ['Status', r.status], ['Prepared by', r.preparer],
      ['Reported to the donor', UI.fmtMoney(r.reported)], ['In the ledger', UI.fmtMoney(r.cumulative)],
    ];
    UI.drawer(`${r.ref} · ${r.title}`, `
      <div style="padding:14px 18px;display:flex;flex-direction:column;gap:14px;">
        ${r.reported !== r.cumulative ? `<div class="fd-alert">The report does not tie to the ledger: ${esc(UI.fmtMoney(r.reported))} reported against ${esc(UI.fmtMoney(r.cumulative))} posted. It cannot be submitted until it does.</div>` : ''}
        <div>${facts.map(([k, v]) => `<div class="detail-row"><span class="k">${esc(k)}</span><span class="v">${esc(v)}</span></div>`).join('')}</div>
        ${r.note ? `<div class="muted" style="font-size:11.5px;line-height:1.5;">${esc(r.note)}</div>` : ''}
        <div>
          <div class="jd-caps" style="margin-bottom:8px;">Documents</div>
          <div id="dr-docs"></div>
        </div>
        ${r.queries.length ? `<div>
          <div class="jd-caps" style="margin-bottom:8px;">Donor queries</div>
          ${r.queries.map(q => `<div style="font-size:12px;line-height:1.5;margin-bottom:6px;"><b>${esc(q.ref)}</b> · ${esc(q.when)} — ${esc(q.text)}${q.response ? `<div class="muted">Response: ${esc(q.response)}</div>` : ''}</div>`).join('')}
        </div>` : ''}
      </div>`, { wide: true });
    UI.docPanel(document.getElementById('dr-docs'), {
      kind: 'donor_report', ref: r.ref, docs: r.attachments, canAdd: r.canAttach, recommended: true,
      empty: 'Nothing attached. Recommended: the report as submitted, and the donor\'s acknowledgement or acceptance.',
      label: 'Attach a document',
    });
  }

  async function refresh() {
    render(await load());
  }

  refresh();
})();
