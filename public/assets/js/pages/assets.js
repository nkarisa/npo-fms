/**
 * Asset register (v5): every capitalised item, what it cost, what it has been
 * written down by and who holds title. The stats, the register (status tabs,
 * search; ten a page), the monthly depreciation run, the asset drawer with its
 * depreciation schedule and history, assets coming onto the register (purchases
 * capitalised, donated and found assets proposed and approved), disposals
 * proposed and approved, and the register against the ledger. /asset-register?asset=<tag> opens an asset straight
 * away. Figures and rules come from /api/assets; the API applies every rule again.
 */
(async function () {
  const app = document.getElementById('app');
  const esc = UI.esc;
  const fmt = UI.fmtMoney;
  const params = new URLSearchParams(location.search);
  const state = { filter: 'All', q: '', page: 1 };
  let data = null;

  const STATUS = {
    'In use': '<span class="as-status in-use">● In use</span>',
    'Fully depreciated': '<span class="as-status spent">◐ Fully depreciated</span>',
    Disposed: '<span class="as-status gone">○ Disposed</span>',
  };

  async function refresh() {
    const p = new URLSearchParams({ filter: state.filter, q: state.q, page: state.page });
    try {
      data = await UI.fetchJSON('/api/assets?' + p.toString());
    } catch (err) {
      app.querySelector('#as-table').innerHTML = `<div class="coa-empty">${esc(err.message)}</div>`;
      return;
    }
    state.page = data.page;
    render();
  }

  function shell() {
    app.innerHTML = `
      <div class="page-head" style="margin-bottom:0;">
        <div>
          <div class="page-kicker" id="as-kicker"></div>
          <h1 class="page-title">Asset register</h1>
          <p class="page-blurb">Every capitalised item, what it cost, what it has been written down by, and who holds title. Depreciation is calculated here and posted to the ledger in one monthly run — the register is the subsidiary record behind accounts 1310, 1320 and 1390.</p>
        </div>
        <div class="page-actions" style="margin-left:0;margin-inline-start:auto;white-space:nowrap;">
          <a class="btn" href="/asset-verification">Asset count sheet</a>
          <button type="button" class="btn" id="as-add">+ Add donated or found asset</button>
          <button type="button" class="btn btn-primary" id="as-run"></button>
        </div>
      </div>
      <div class="stat-grid" id="as-stats" style="margin:18px 0 0;"></div>
      <div class="jr-filters">
        <div class="coa-seg" id="as-tabs"></div>
        <label class="coa-search" style="flex:1 1 220px;min-width:190px;max-width:300px;width:auto;">⌕
          <input type="search" id="as-q" placeholder="Search tag, description, funder or custodian">
        </label>
        <div class="jr-hint" id="as-hint"></div>
      </div>
      <div class="coa-card">
        <div style="overflow-x:auto;"><div style="min-width:1080px;" id="as-table"></div></div>
        <div id="as-pager"></div>
        <div class="coa-foot">
          <span id="as-footer"></span>
          <span style="margin-inline-start:auto;">Straight line over useful life · nil residual · charged from the month after acquisition</span>
        </div>
      </div>
      <div class="as-card as-section" id="as-incoming"></div>
      <div class="as-card as-section" id="as-disposals" hidden></div>
      <div class="as-card as-section" id="as-tie" style="margin-bottom:22px;"></div>`;

    let searchTimer;
    app.querySelector('#as-q').addEventListener('input', (e) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => { state.q = e.target.value; state.page = 1; refresh(); }, 200);
    });
    app.querySelector('#as-tabs').addEventListener('click', (e) => {
      const tab = e.target.closest('[data-filter]');
      if (!tab) return;
      state.filter = tab.dataset.filter;
      state.page = 1;
      refresh();
    });
    app.querySelector('#as-pager').addEventListener('click', (e) => {
      const b = e.target.closest('[data-page]');
      if (!b || b.disabled) return;
      state.page = +b.dataset.page;
      refresh();
    });
    const table = app.querySelector('#as-table');
    table.addEventListener('click', (e) => {
      const row = e.target.closest('[data-tag]');
      if (row) Asset.open(row.dataset.tag);
    });
    table.addEventListener('keydown', (e) => {
      const row = e.target.closest('[data-tag]');
      if (row && e.target === row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); Asset.open(row.dataset.tag); }
    });
    app.querySelector('#as-run').addEventListener('click', () => act('/api/assets/depreciation-run', {}));
    app.querySelector('#as-add').addEventListener('click', () => Addition.open(null));
    app.querySelector('#as-incoming').addEventListener('click', (e) => {
      const cap = e.target.closest('[data-capitalise]');
      if (cap) return Capitalise.open(data.capitalise.rows.find(r => String(r.line) === cap.dataset.capitalise));
      const found = e.target.closest('[data-found]');
      if (found) return Addition.open(found.dataset.found);
      const b = e.target.closest('[data-addition]');
      if (b) act('/api/assets/additions/' + b.dataset.addition, { tag: b.dataset.tag }, b);
    });
    app.querySelector('#as-disposals').addEventListener('click', (e) => {
      const b = e.target.closest('[data-disp]');
      if (b) act('/api/assets/disposals/' + b.dataset.disp, { tag: b.dataset.tag }, b);
    });
  }

  function render() {
    app.querySelector('#as-kicker').textContent = data.kicker;
    const run = app.querySelector('#as-run');
    run.textContent = data.run.label;
    run.disabled = !data.run.can;
    run.title = data.run.done ? `Posted as ${data.run.ref}` : (data.can.prepare ? '' : 'Only a preparer can post the depreciation run');

    app.querySelector('#as-stats').innerHTML = data.stats.map(s => `
      <div class="stat">
        <div class="stat-label">${esc(s.label)}</div>
        <div class="stat-value">${esc(s.value)}</div>
        <div class="stat-note">${esc(s.note)}</div>
      </div>`).join('');
    app.querySelector('#as-tabs').innerHTML = data.tabs.map(t => `
      <button type="button" class="coa-seg-btn ${t.key === data.filter ? 'on' : ''}" data-filter="${esc(t.key)}">${esc(t.label)}</button>`).join('');
    app.querySelector('#as-hint').textContent = data.hint;
    app.querySelector('#as-footer').textContent = data.footer;

    const selected = Asset.current();
    app.querySelector('#as-table').innerHTML = `
      <div class="as-grid coa-head">
        <div>Tag</div><div>Asset</div><div>Funder</div><div style="text-align:end;">Cost</div><div style="text-align:end;">Depn to date</div>
        <div style="text-align:end;">Net book value</div><div style="text-align:end;">Monthly</div><div>Status</div>
      </div>
      ${data.rows.map(a => `
        <div class="as-grid as-row ${a.tag === selected ? 'on' : ''}" data-tag="${esc(a.tag)}" tabindex="0">
          <div class="as-tag">${esc(a.tag)}</div>
          <div><span class="as-name">${esc(a.name)}</span><span class="as-sub">${esc(a.sub)}</span></div>
          <div class="as-funder">${esc(a.funder)}</div>
          <div class="coa-amount">${fmt(a.cost)}</div>
          <div class="coa-amount">${fmt(a.accum)}</div>
          <div class="coa-amount" style="font-weight:600;color:#16211E;">${fmt(a.nbv)}</div>
          <div class="coa-amount" style="font-size:11.5px;color:#6E7873;">${fmt(a.monthly)}</div>
          <div>${STATUS[a.status] || esc(a.status)}</div>
        </div>`).join('')}
      ${data.rows.length === 0 ? '<div class="coa-empty">No asset matches that search.</div>' : ''}`;
    app.querySelector('#as-pager').innerHTML = data.pages > 1 ? pager() : '';

    const add = app.querySelector('#as-add');
    add.disabled = !data.can.prepare;
    add.title = data.can.prepare ? '' : 'Only a preparer can propose an asset';

    renderIncoming();
    renderDisposals();
    renderTie();
  }

  /** Purchases waiting to be capitalised, assets found in the count, and additions waiting for approval. */
  function renderIncoming() {
    const c = data.capitalise;
    const found = data.uncapitalised;
    const pending = data.additions;
    const prep = data.can.prepare;
    app.querySelector('#as-incoming').innerHTML = `
      <div class="as-section-head"><b>Coming onto the register</b><span>Purchases posted to ${esc([...new Set(data.forms.classes.map(k => k.account))].sort().join(' and '))} · ${esc(c.hint)}</span></div>
      ${c.rows.length ? c.rows.map(r => `
        <div class="as-disp">
          <div style="display:flex;flex-direction:column;gap:3px;min-width:0;">
            <span style="font-size:12.5px;font-weight:600;color:#16211E;"><span class="jr-ref">${esc(r.ref)}</span> · ${esc(r.desc)}</span>
            <span style="font-size:11px;color:#7A857F;">${esc(r.date)} · ${esc(r.account)} ${esc(r.accountName)}${r.doc ? ' · ' + esc(r.doc) : ''}</span>
          </div>
          <div class="as-figure"><span>Purchase</span><span>${fmt(r.amount)}</span></div>
          <div class="as-figure"><span>Capitalised</span><span>${fmt(r.amount - r.remaining)}</span></div>
          <div class="as-figure"><span>Waiting</span><span style="font-weight:600;color:#16211E;">${fmt(r.remaining)}</span></div>
          <div style="display:flex;justify-content:flex-end;">
            <button type="button" class="btn btn-primary" data-capitalise="${r.line}" ${prep ? '' : 'disabled title="Only a preparer can capitalise"'}>Capitalise</button>
          </div>
        </div>`).join('')
        : '<div style="padding:12px 16px;border-bottom:1px solid #F2F1EC;font-size:11.5px;color:#7A857F;">Every purchase posted to the asset cost accounts is on the register. A bill or journal charged to them appears here to be capitalised.</div>'}
      ${pending.map(r => `
        <div class="as-disp">
          <div style="display:flex;flex-direction:column;gap:3px;min-width:0;">
            <span style="font-size:12.5px;font-weight:600;color:#16211E;">${esc(r.title)}</span>
            <span style="font-size:11px;color:#7A857F;">${esc(r.detail)}</span>
          </div>
          <div class="as-figure"><span>Value</span><span>${esc(r.amount)}</span></div>
          <div class="as-figure"><span>Credited to</span><span>${esc(r.credit)}</span></div>
          <div class="as-figure"><span>Status</span><span style="font-family:inherit;color:#8A5B2E;">Awaiting approval</span></div>
          <div style="display:flex;align-items:center;gap:8px;justify-content:flex-end;">
            <button type="button" class="btn" data-addition="withdraw" data-tag="${esc(r.tag)}" ${r.canWithdraw ? '' : 'disabled'}>Withdraw</button>
            <button type="button" class="btn btn-primary" data-addition="approve" data-tag="${esc(r.tag)}" ${r.canApprove ? '' : 'disabled title="Only an approver can post an addition"'}>Approve and post</button>
          </div>
        </div>`).join('')}
      ${found.length ? `
        <details class="as-found">
          <summary>${found.length} ${found.length === 1 ? 'item' : 'items'} found in the count but never recorded — not on the register or in the ledger</summary>
          ${found.map(a => `
            <div class="as-found-row">
              <span class="as-tag">${esc(a.tag)}</span>
              <span style="min-width:0;"><span class="as-name">${esc(a.name)}</span><span class="as-sub">${esc(a.cls)} · ${esc(a.location)}</span></span>
              <span class="as-mono" style="text-align:end;">${fmt(a.value)}</span>
              <button type="button" class="btn" data-found="${esc(a.tag)}" ${prep ? '' : 'disabled'}>Add to register</button>
            </div>`).join('')}
        </details>` : ''}`;
  }

  function pager() {
    const p = data.page;
    const buttons = [];
    for (let k = 1; k <= data.pages; k++) buttons.push(`<button type="button" class="coa-page ${k === p ? 'on' : ''}" data-page="${k}">${k}</button>`);
    return `
      <div class="coa-pager">
        <span>Showing ${(p - 1) * data.pageSize + 1}–${Math.min(data.filtered, p * data.pageSize)} of ${data.filtered} assets</span>
        <div style="margin-inline-start:auto;display:flex;align-items:center;gap:4px;">
          <button type="button" class="coa-page" data-page="${p - 1}" ${p <= 1 ? 'disabled' : ''}>‹</button>
          ${buttons.join('')}
          <button type="button" class="coa-page" data-page="${p + 1}" ${p >= data.pages ? 'disabled' : ''}>›</button>
        </div>
      </div>`;
  }

  function renderDisposals() {
    const d = data.disposals;
    const el = app.querySelector('#as-disposals');
    el.hidden = d.rows.length === 0;
    el.innerHTML = `
      <div class="as-section-head"><b>Disposals</b><span>${esc(d.hint)}</span></div>
      ${d.rows.map(r => `
        <div class="as-disp">
          <div style="display:flex;flex-direction:column;gap:3px;min-width:0;">
            <span style="font-size:12.5px;font-weight:600;color:#16211E;">${esc(r.title)}</span>
            <span style="font-size:11px;color:#7A857F;">${esc(r.detail)}</span>
          </div>
          <div class="as-figure"><span>Book value</span><span>${esc(r.nbv)}</span></div>
          <div class="as-figure"><span>Proceeds</span><span>${esc(r.proceeds)}</span></div>
          <div class="as-figure"><span>${esc(r.resultLabel)}</span><span class="${r.gain ? 'as-gain' : 'as-loss'}">${esc(r.result)}</span></div>
          <div style="display:flex;align-items:center;gap:8px;justify-content:flex-end;">
            ${r.pending ? `
              <button type="button" class="btn" data-disp="withdraw" data-tag="${esc(r.tag)}" ${r.canWithdraw ? '' : 'disabled'}>Withdraw</button>
              <button type="button" class="btn btn-primary" data-disp="approve" data-tag="${esc(r.tag)}" ${r.canApprove ? '' : 'disabled title="Only an approver can post a disposal"'}>Approve and post</button>` : ''}
            ${r.posted ? `<span style="font-size:11px;color:var(--calm-ink);white-space:nowrap;">✓ Posted ${esc(r.ref)}</span>` : ''}
          </div>
        </div>`).join('')}`;
  }

  function renderTie() {
    const t = data.tie;
    app.querySelector('#as-tie').innerHTML = `
      <div class="as-section-head"><b>Register against the ledger</b><span>${esc(t.hint)}</span></div>
      <div class="as-tie coa-head" style="position:static;">
        <div style="padding:8px 16px;">Control account</div><div style="padding:8px 16px;">Account</div>
        <div style="padding:8px 16px;text-align:end;">Per register</div><div style="padding:8px 16px;text-align:end;">Per ledger</div><div style="padding:8px 16px;text-align:end;">Difference</div>
      </div>
      ${t.rows.map(r => `
        <div class="as-tie as-tie-row">
          <div>${esc(r.label)}</div>
          <div class="as-mono" style="font-size:11px;color:#9AA39E;">${esc(r.acct)}</div>
          <div class="as-mono" style="text-align:end;color:#3E4A44;">${esc(r.register)}</div>
          <div class="as-mono" style="text-align:end;color:#3E4A44;">${esc(r.ledger)}</div>
          <div class="as-mono ${r.agrees ? 'as-gain' : 'as-loss'}" style="text-align:end;">${esc(r.diff)}</div>
        </div>`).join('')}
      <div class="as-tie-foot">
        ${t.tied
          ? `<span style="color:var(--calm-ink);">✓ The register agrees to the control accounts. ${esc(t.note)}</span>`
          : `<span style="color:#A5442F;">! The register does not agree to the control accounts — ${t.awaiting ? esc(t.awaiting) : 'a capitalisation or a disposal has not been posted.'}</span>`}
      </div>`;
  }

  /** Posts an action; every write answers with the register as it now stands. */
  async function act(url, body, button) {
    const buttons = button ? [button] : [...app.querySelectorAll('#as-run')];
    buttons.forEach(b => { b.disabled = true; });
    try {
      const res = await UI.postJSON(url + '?' + new URLSearchParams({ filter: state.filter, q: state.q, page: state.page }), body);
      data = res;
      state.page = data.page;
      render();
      UI.toast(res.message);
      return true;
    } catch (err) {
      UI.toast(err.message);
      render();
      if (button) button.disabled = false;
      return false;
    }
  }

  // ---- Asset drawer ----

  const Asset = (() => {
    let el;
    let tag = null;
    let asset = null;

    function build() {
      el = document.createElement('div');
      el.className = 'rt';
      el.hidden = true;
      el.innerHTML = `
        <div class="jd-backdrop" data-as-close></div>
        <div class="rt-panel" style="width:620px;background:#FAF9F6;" role="dialog" aria-modal="true" aria-label="Asset">
          <div class="rt-body" style="padding:18px;gap:18px;" id="asd-body"></div>
        </div>`;
      document.body.appendChild(el);
      el.addEventListener('click', (e) => {
        if (e.target.closest('[data-as-close]')) return close();
        if (e.target.closest('[data-as-dispose]')) return Disposal.open(asset);
      });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !el.hidden && !Disposal.isOpen()) close(); });
    }

    async function open(t) {
      if (!el) build();
      tag = t;
      history.replaceState(null, '', '/asset-register?asset=' + encodeURIComponent(t));
      try {
        asset = await UI.fetchJSON('/api/assets/' + encodeURIComponent(t));
      } catch (err) {
        UI.toast(t + ' was not found on the asset register.');
        return close();
      }
      renderDrawer();
      el.hidden = false;
      if (data) render();
    }

    function renderDrawer() {
      const a = asset;
      el.querySelector('#asd-body').innerHTML = `
        <div class="as-card">
          <div style="padding:12px 16px;border-bottom:1px solid #EEEDE8;display:flex;align-items:flex-start;gap:12px;">
            <div style="display:flex;flex-direction:column;gap:3px;min-width:0;">
              <span style="font-family:'IBM Plex Mono',monospace;font-size:10.5px;color:#9AA39E;">${esc(a.tag)}${a.doc ? ' · ' + esc(a.doc) : ''}</span>
              <span style="font-size:13px;font-weight:600;color:#16211E;">${esc(a.name)}</span>
            </div>
            <button type="button" class="rt-close" data-as-close aria-label="Close">✕</button>
          </div>
          ${a.facts.map(f => `<div class="as-fact"><span>${esc(f.label)}</span><span>${esc(f.value)}</span></div>`).join('')}
          <div class="as-drill">
            <a class="btn" href="/gl?account=${esc(a.costAcct)}">Cost account ${esc(a.costAcct)}</a>
            ${a.canDispose ? '<button type="button" class="btn btn-primary" style="margin-inline-start:auto;" data-as-dispose>Propose disposal</button>' : ''}
            ${a.disposalNote ? `<span style="margin-inline-start:auto;font-size:11px;color:#8A5B2E;">${esc(a.disposalNote)}</span>` : ''}
          </div>
        </div>
        <div class="as-card">
          <div class="as-section-head"><b>Depreciation schedule</b><span>${esc(a.scheduleHint)}</span></div>
          <div class="as-sched coa-head" style="position:static;">
            <div style="padding:8px 14px;">Year</div><div style="padding:8px 14px;text-align:end;">Opening</div>
            <div style="padding:8px 14px;text-align:end;">Charge</div><div style="padding:8px 14px;text-align:end;">Closing</div>
          </div>
          ${a.schedule.map(y => `
            <div class="as-sched as-sched-row ${y.current ? 'now' : ''}">
              <div style="font-size:12px;color:#28352F;display:flex;align-items:center;gap:8px;">${esc(y.year)}${y.current ? '<span style="font-size:10px;color:var(--accent-ink);">current</span>' : ''}</div>
              <div class="as-mono" style="text-align:end;font-size:11.5px;color:#6E7873;">${esc(y.opening)}</div>
              <div class="as-mono" style="text-align:end;font-size:11.5px;color:#3E4A44;">${esc(y.charge)}</div>
              <div class="as-mono" style="text-align:end;font-size:11.5px;font-weight:600;color:#16211E;">${esc(y.closing)}</div>
            </div>`).join('')}
        </div>
        ${ledgerCard(a.ledger)}
        <div class="as-card">
          <div class="as-section-head"><b>Documents</b></div>
          <div data-asd-docs style="padding:12px 14px;"></div>
        </div>
        <div class="as-card">
          <div class="as-section-head"><b>History</b></div>
          ${a.trail.length ? a.trail.map(t => `<div class="as-trail"><span>${esc(t.when)}</span><span>${esc(t.what)}</span></div>`).join('')
            : '<div class="as-trail"><span></span><span style="color:#8B948F;">Nothing recorded yet.</span></div>'}
        </div>`;
      const more = el.querySelector('[data-asd-earlier]');
      if (more) more.addEventListener('click', () => { a.ledger.expanded = true; renderDrawer(); });
      UI.docPanel(el.querySelector('[data-asd-docs]'), {
        kind: 'asset', ref: a.tag, docs: a.documents, canAdd: a.canAttach, recommended: true,
        empty: 'Nothing attached. Recommended: the purchase invoice or deed of gift, and title documents for vehicles and land.',
        label: 'Attach a document',
        onAdded: (documents) => { a.documents = documents; },
      });
    }

    /**
     * This asset's share of 1390 and 5350, read from the depreciation runs it was
     * charged in. The latest charges show; earlier months fold into one line.
     */
    function ledgerCard(l) {
      const SHOWN = 6;
      const runs = l.rows.filter(r => r.kind === 'run');
      const folded = l.expanded || runs.length <= SHOWN + 1 ? [] : runs.slice(0, runs.length - SHOWN);
      const line = (r) => `
        <div class="as-ledger as-ledger-row ${r.kind}">
          <div class="as-mono">${esc(r.when)}</div>
          <div>${esc(r.what)}${r.journal ? ` <a class="as-mono" href="/journals/${encodeURIComponent(r.journal)}">${esc(r.journal)}</a>` : ''}</div>
          <div class="as-mono end">${esc(r.expense) || '—'}</div>
          <div class="as-mono end">${esc(r.accum)}</div>
          <div class="as-mono end strong">${esc(r.balance)}</div>
        </div>`;
      const rows = l.rows.filter(r => !folded.includes(r));
      const at = rows.findIndex(r => r.kind === 'run');
      const fold = folded.length ? `
        <button type="button" class="as-ledger as-ledger-row as-ledger-fold" data-asd-earlier>
          <div></div><div>${folded.length} earlier monthly charges, ${esc(folded[0].when)} to ${esc(folded[folded.length - 1].when)} — show</div>
          <div></div><div></div><div class="as-mono end strong">${esc(folded[folded.length - 1].balance)}</div>
        </button>` : '';
      return `
        <div class="as-card">
          <div class="as-section-head"><b>Depreciation in the ledger</b></div>
          <div class="as-ledger-stats">
            ${[l.accumulated, l.expense].map(s => `
              <div><span>${esc(s.account)}</span><b class="as-mono">${esc(s.value)}</b><em>${esc(s.note)}</em></div>`).join('')}
          </div>
          <div class="as-ledger coa-head" style="position:static;">
            <div>Date</div><div>Entry</div><div class="end">5350 Dr</div><div class="end">1390</div><div class="end">1390 balance</div>
          </div>
          ${rows.map((r, i) => (i === at ? fold : '') + line(r)).join('')}
          <div class="as-ledger-hint">${esc(l.hint)}</div>
        </div>`;
    }

    function close() {
      if (el) el.hidden = true;
      tag = null;
      history.replaceState(null, '', '/asset-register');
      if (data) render();
    }

    /** Reloads the open asset after a write, so its status and history are current. */
    async function reload() {
      if (tag && el && !el.hidden) await open(tag);
    }

    return { open, close, reload, current: () => tag };
  })();

  // ---- Disposal proposal ----

  const Disposal = (() => {
    let el;
    let asset = null;
    let form = null;

    const WRITE_OFF = 'Write-off — beyond repair';
    const DONATION = 'Donation to a partner';

    function build() {
      el = document.createElement('div');
      el.className = 'ap-modal';
      el.hidden = true;
      el.innerHTML = `
        <div class="ap-modal-scrim" data-dp-close></div>
        <div class="ap-modal-box" style="width:940px;" role="dialog" aria-modal="true" aria-label="Propose disposal">
          <div style="padding:12px 16px;border-bottom:1px solid #EEEDE8;display:flex;align-items:flex-start;gap:12px;">
            <div style="display:flex;flex-direction:column;gap:3px;">
              <span style="font-size:10.5px;letter-spacing:.1em;text-transform:uppercase;color:#8B948F;">Propose disposal</span>
              <span style="font-size:13px;font-weight:600;color:#16211E;" id="dp-label"></span>
            </div>
            <button type="button" class="rt-close" data-dp-close aria-label="Close">✕</button>
          </div>
          <div style="overflow-y:auto;" id="dp-body"></div>
        </div>`;
      document.body.appendChild(el);
      el.addEventListener('click', (e) => {
        if (e.target.closest('[data-dp-close]')) return close();
        if (e.target.closest('[data-dp-submit]')) return submit();
      });
      el.addEventListener('input', (e) => {
        const f = e.target.closest('[data-dp]');
        if (!f) return;
        form[f.dataset.dp] = f.value;
        if (f.dataset.dp === 'method') renderForm();
        renderSide();
      });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !el.hidden) close(); });
    }

    function open(a) {
      if (!el) build();
      asset = a;
      form = { method: a.dispose.method, date: a.dispose.date, proceeds: '', buyer: '', minute: '', consent: '', reason: '' };
      el.querySelector('#dp-label').textContent = a.tag + ' · ' + a.name;
      el.querySelector('#dp-body').innerHTML = '<div class="as-dp"><div class="as-dp-form" id="dp-form"></div><div class="as-dp-side" id="dp-side"></div></div>';
      renderForm();
      renderSide();
      el.hidden = false;
    }

    function close() {
      if (el) el.hidden = true;
    }

    const proceeds = () => Math.max(0, parseInt(String(form.proceeds).replace(/[^0-9]/g, ''), 10) || 0);

    /** The same rules the API applies, in the same order, so the button says why before it is pressed. */
    function block() {
      const d = asset.dispose;
      const writeOff = form.method === WRITE_OFF;
      const p = proceeds();
      const period = d.periods.find(x => x.min <= form.date && form.date <= x.max);
      if (!form.minute.trim()) return 'Every disposal needs the board minute that approved it.';
      if (d.needsConsent && !form.consent.trim()) return `Title on this asset reverts to ${d.funder}. Written donor consent must be referenced before it can leave the register.`;
      if (writeOff && p > 0) return 'A write-off cannot raise proceeds — record a sale instead.';
      if (!writeOff && p === 0 && form.method !== DONATION) return 'Enter the proceeds, or change the method to a write-off or a donation.';
      if (!/^\d{4}-\d{2}-\d{2}$/.test(form.date) || !period) return 'Enter the disposal date as a date in the financial year.';
      if (form.date < d.acquiredOn) return 'The disposal cannot be dated before the asset was acquired.';
      if (period.closed) return `${period.name} is closed to posting. Reopen it or date the disposal in an open period.`;
      if (!form.reason.trim()) return 'Record why the asset is being disposed of and the condition it is in.';
      return '';
    }

    function renderForm() {
      const d = asset.dispose;
      const buyerLabel = form.method === WRITE_OFF ? 'Disposal contractor' : form.method === DONATION ? 'Receiving partner' : 'Buyer';
      el.querySelector('#dp-form').innerHTML = `
        <div class="as-pair">
          <label class="as-field"><span>Method</span>
            <select data-dp="method">${d.methods.map(m => `<option ${m === form.method ? 'selected' : ''}>${esc(m)}</option>`).join('')}</select>
          </label>
          <label class="as-field"><span>Disposal date</span><input type="date" data-dp="date" value="${esc(form.date)}"></label>
        </div>
        <div class="as-pair">
          <label class="as-field"><span>Proceeds (KES)</span><input data-dp="proceeds" inputmode="numeric" placeholder="0" value="${esc(form.proceeds)}" style="font-family:'IBM Plex Mono',monospace;"></label>
          <label class="as-field"><span>${esc(buyerLabel)}</span><input data-dp="buyer" placeholder="Name of the buyer or recipient" value="${esc(form.buyer)}"></label>
        </div>
        <div class="as-pair">
          <label class="as-field"><span>Board minute</span><input data-dp="minute" placeholder="e.g. BM/2026/08/14" value="${esc(form.minute)}"></label>
          ${d.needsConsent ? `<label class="as-field consent"><span>Donor consent reference</span><input data-dp="consent" placeholder="Written consent from the donor" value="${esc(form.consent)}"></label>` : ''}
        </div>
        <label class="as-field"><span>Reason and condition</span>
          <textarea data-dp="reason" rows="3" placeholder="Why the asset is being disposed of and the condition it is in">${esc(form.reason)}</textarea>
        </label>
        ${d.consentNote ? `<div class="as-note warn">${esc(d.consentNote)}</div>` : ''}`;
    }

    function renderSide() {
      const a = asset;
      const d = a.dispose;
      const p = proceeds();
      const result = p - a.nbv;
      const lines = [['Dr', d.accounts.accum, a.accum]]
        .concat(p > 0 ? [['Dr', d.accounts.bank, p]] : [])
        .concat(result < 0 ? [['Dr', d.accounts.loss, -result]] : [])
        .concat([['Cr', d.costAccount, a.cost]])
        .concat(result > 0 ? [['Cr', d.accounts.gain, result]] : []);
      const why = block();
      el.querySelector('#dp-side').innerHTML = `
        <div class="as-sum">
          <div><span>Cost</span><span class="as-mono">${fmt(a.cost)}</span></div>
          <div><span>Depreciation to date</span><span class="as-mono">${fmt(a.accum)}</span></div>
          <div class="total"><span>Net book value at disposal</span><span class="as-mono">${fmt(a.nbv)}</span></div>
          <div><span>Proceeds</span><span class="as-mono">${fmt(p)}</span></div>
          <div class="final"><span>${result >= 0 ? 'Gain on disposal' : 'Loss on disposal'}</span><span class="as-mono ${result >= 0 ? 'as-gain' : 'as-loss'}" style="font-size:13px;">${fmt(Math.abs(result))}</span></div>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px;">
          <span style="font-size:10px;letter-spacing:.09em;text-transform:uppercase;color:#8B948F;">Journal on approval</span>
          <div style="border:1px solid #EEEDE8;border-radius:7px;overflow:hidden;">
            ${lines.map(([side, account, amount]) => `
              <div class="as-jl">
                <span style="font-size:10.5px;font-weight:600;color:${side === 'Dr' ? 'var(--accent-ink)' : '#A5442F'};">${side}</span>
                <span style="color:#28352F;">${esc(account)}</span>
                <span class="as-mono" style="text-align:end;font-size:11.5px;color:#16211E;">${fmt(amount)}</span>
              </div>`).join('')}
            <div class="as-jl" style="background:#FAF9F6;border-bottom:none;height:auto;min-height:30px;">
              <span></span><span style="font-size:11px;color:#7A857F;white-space:normal;padding-block:6px;">Balanced · ${fmt(a.accum + p + Math.max(0, -result))} each side, asset derecognised</span><span></span>
            </div>
          </div>
        </div>
        ${why ? `<div class="as-note block">${esc(why)}</div>` : ''}
        <div style="display:flex;align-items:center;gap:8px;">
          <button type="button" class="btn" data-dp-close>Cancel</button>
          <button type="button" class="btn btn-primary" data-dp-submit ${why ? 'disabled' : ''}>Submit for approval</button>
        </div>`;
    }

    async function submit() {
      if (block()) return;
      const ok = await act('/api/assets/disposals', Object.assign({ tag: asset.tag }, form, { proceeds: proceeds() }), el.querySelector('[data-dp-submit]'));
      if (ok) {
        close();
        Asset.reload();
      }
    }

    return { open, isOpen: () => el && !el.hidden };
  })();

  // ---- Shared modal for the capitalise and add forms ----

  function modal(label) {
    const el = document.createElement('div');
    el.className = 'ap-modal';
    el.hidden = true;
    el.innerHTML = `
      <div class="ap-modal-scrim" data-m-close></div>
      <div class="ap-modal-box" style="width:940px;" role="dialog" aria-modal="true" aria-label="${esc(label)}">
        <div style="padding:12px 16px;border-bottom:1px solid #EEEDE8;display:flex;align-items:flex-start;gap:12px;">
          <div style="display:flex;flex-direction:column;gap:3px;">
            <span style="font-size:10.5px;letter-spacing:.1em;text-transform:uppercase;color:#8B948F;">${esc(label)}</span>
            <span style="font-size:13px;font-weight:600;color:#16211E;" data-m-title></span>
          </div>
          <button type="button" class="rt-close" data-m-close aria-label="Close">✕</button>
        </div>
        <div style="overflow-y:auto;"><div class="as-dp"><div class="as-dp-form" data-m-form></div><div class="as-dp-side" data-m-side></div></div></div>
      </div>`;
    document.body.appendChild(el);
    el.addEventListener('click', (e) => { if (e.target.closest('[data-m-close]')) el.hidden = true; });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') el.hidden = true; });
    return el;
  }

  const money = (v) => Math.max(0, Math.round((parseFloat(String(v).replace(/[^0-9.]/g, '')) || 0) * 100) / 100);
  const field = (label, html, cls) => `<label class="as-field ${cls || ''}"><span>${esc(label)}</span>${html}</label>`;
  const input = (key, value, attrs) => `<input data-f="${key}" value="${esc(value)}" ${attrs || ''}>`;
  const monthAfter = (iso) => {
    const d = new Date(iso + 'T00:00:00');
    d.setMonth(d.getMonth() + 1, 1);
    return d.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
  };
  const locationList = () => `<datalist id="as-locations">${data.forms.locations.map(l => `<option value="${esc(l)}">`).join('')}</datalist>`;

  /** Bind inputs to a form object; re-render the side on every change and the form when `rerender` keys change. */
  function bind(el, form, renderForm, renderSide, rerender) {
    el.addEventListener('input', (e) => {
      const f = e.target.closest('[data-f]');
      if (!f || el.hidden) return;
      form()[f.dataset.f] = f.value;
      if (rerender.includes(f.dataset.f)) renderForm(f.dataset.f);
      renderSide();
    });
  }

  // ---- Capitalise a purchase ----

  const Capitalise = (() => {
    let el;
    let line = null;
    let form = null;

    const classOf = () => data.forms.classes.find(c => c.name === form.class) || null;

    function open(l) {
      if (!l) return;
      if (!el) {
        el = modal('Capitalise purchase');
        bind(el, () => form, renderForm, renderSide, ['class']);
        el.addEventListener('click', (e) => { if (e.target.closest('[data-m-submit]')) submit(); });
      }
      line = l;
      const onAccount = data.forms.classes.filter(c => c.account === l.account);
      const first = onAccount[0] || data.forms.classes[0];
      form = { class: first.name, description: l.desc, units: '1', amount: String(l.remaining), life: String(first.life), location: '', custodian: '', title: '', serial: '' };
      el.querySelector('[data-m-title]').textContent = `${l.ref} · ${l.desc}`;
      renderForm();
      renderSide();
      el.hidden = false;
    }

    function renderForm(changed) {
      if (changed === 'class') form.life = String((classOf() || {}).life || form.life);
      const onAccount = data.forms.classes.filter(c => c.account === line.account);
      el.querySelector('[data-m-form]').innerHTML = `
        <div class="as-pair">
          ${field('Asset class', `<select data-f="class">${(onAccount.length ? onAccount : data.forms.classes).map(c => `<option ${c.name === form.class ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select>`)}
          ${field('Useful life (years)', input('life', form.life, 'inputmode="numeric"'))}
        </div>
        ${field('Description on the register', input('description', form.description))}
        <div class="as-pair">
          ${field('Cost to capitalise (KES)', input('amount', form.amount, 'inputmode="decimal" style="font-family:\'IBM Plex Mono\',monospace;"'))}
          ${field('Assets to tag', input('units', form.units, 'inputmode="numeric"'))}
        </div>
        <div class="as-pair">
          ${field('Location', input('location', form.location, 'list="as-locations" placeholder="Where it is kept"') + locationList())}
          ${field('Custodian', input('custodian', form.custodian, 'placeholder="Who holds it"'))}
        </div>
        <div class="as-pair">
          ${field('Serial number', input('serial', form.serial, 'placeholder="Optional"'))}
          ${field('Title', input('title', form.title, 'placeholder="e.g. Title reverts to the donor at grant close"'))}
        </div>`;
    }

    function block() {
      const c = classOf();
      const amount = money(form.amount);
      const units = parseInt(form.units, 10) || 0;
      const life = parseInt(form.life, 10) || 0;
      if (!c) return 'Choose the asset class.';
      if (c.account !== line.account) return `${c.name} is carried on ${c.account}, but the purchase was charged to ${line.account}.`;
      if (!form.description.trim()) return 'Describe the asset as it should appear on the register.';
      if (units < 1 || units > 500) return 'Enter how many assets to tag, between 1 and 500.';
      if (amount <= 0) return 'Enter the cost to capitalise.';
      if (amount > line.remaining) return `Only ${fmt(line.remaining)} of ${line.ref} is left to capitalise.`;
      if (life < 1 || life > 50) return 'Enter a useful life of 1 to 50 years.';
      if (!form.location.trim()) return 'Record where the asset is kept.';
      return '';
    }

    function renderSide() {
      const c = classOf() || { prefix: '', account: line.account };
      const amount = money(form.amount);
      const units = Math.max(1, parseInt(form.units, 10) || 1);
      const life = Math.max(1, parseInt(form.life, 10) || 1);
      const each = Math.floor(amount / units * 100) / 100;
      const why = block();
      el.querySelector('[data-m-side]').innerHTML = `
        <div class="as-sum">
          <div><span>Purchase</span><span class="as-mono">${esc(line.ref)} · ${esc(line.date)}</span></div>
          <div><span>Charged to</span><span>${esc(line.account)} · ${esc(line.accountName)}</span></div>
          <div><span>Amount on the purchase</span><span class="as-mono">${fmt(line.amount)}</span></div>
          <div><span>Already capitalised</span><span class="as-mono">${fmt(line.amount - line.remaining)}</span></div>
          <div class="total"><span>Capitalised now</span><span class="as-mono">${fmt(amount)}</span></div>
          <div><span>Left waiting</span><span class="as-mono">${fmt(Math.max(0, line.remaining - amount))}</span></div>
        </div>
        <div class="as-sum">
          <div class="final"><span>On the register</span><span>${units === 1 ? `1 × ${esc(c.prefix)}-###` : `${units} × ${esc(c.prefix)}-###, tagged in sequence`}</span></div>
          <div><span>Cost of each</span><span class="as-mono">${fmt(each)}</span></div>
          <div><span>Monthly depreciation of each</span><span class="as-mono">${fmt(Math.round(each / (life * 12)))}</span></div>
          <div><span>Depreciation starts</span><span>${esc(monthAfter(line.iso))}</span></div>
        </div>
        <div class="as-note warn" style="color:var(--calm-ink);background:var(--calm-tint);border-color:var(--calm-line);">Nothing new posts: ${esc(line.ref)} already charged the cost to ${esc(line.account)}. The asset takes its date, fund, programme and grant from the purchase.</div>
        ${why ? `<div class="as-note block">${esc(why)}</div>` : ''}
        <div style="display:flex;align-items:center;gap:8px;">
          <button type="button" class="btn" data-m-close>Cancel</button>
          <button type="button" class="btn btn-primary" data-m-submit ${why ? 'disabled' : ''}>Put on the register</button>
        </div>`;
    }

    async function submit() {
      if (block()) return;
      const ok = await act('/api/assets/capitalise', Object.assign({ line: line.line }, form, { amount: money(form.amount) }), el.querySelector('[data-m-submit]'));
      if (ok) el.hidden = true;
    }

    return { open };
  })();

  // ---- Donated, or found in a count and never recorded ----

  const Addition = (() => {
    let el;
    let form = null;

    const foundAsset = () => data.uncapitalised.find(a => a.tag === form.tag) || null;
    const classOf = () => data.forms.classes.find(c => c.name === (form.basis === 'found' ? (foundAsset() || {}).cls : form.class)) || null;

    function open(tag) {
      if (!el) {
        el = modal('Add an asset with no purchase behind it');
        bind(el, () => form, renderForm, renderSide, ['basis', 'tag', 'class']);
        el.addEventListener('click', (e) => { if (e.target.closest('[data-m-submit]')) submit(); });
      }
      const first = data.forms.classes[0];
      form = { basis: tag ? 'found' : 'donation', tag: tag || '', class: first.name, description: '', amount: '', date: data.forms.today,
        source: '', reference: '', reason: '', life: String(first.life), location: '', custodian: '', title: '', files: [] };
      if (tag) setFound();
      renderForm();
      renderSide();
      el.hidden = false;
    }

    function setFound() {
      const a = foundAsset();
      form.amount = a ? String(a.value) : '';
      form.source = data.forms.round ? 'Asset count ' + data.forms.round : '';
    }

    function renderForm(changed) {
      if (changed === 'basis' || changed === 'tag') {
        if (form.basis === 'found') {
          form.tag = form.tag || (data.uncapitalised[0] || {}).tag || '';
          setFound();
        } else {
          form.amount = '';
          form.source = '';
        }
      }
      if (changed === 'class') form.life = String((classOf() || {}).life || form.life);
      const found = form.basis === 'found';
      el.querySelector('[data-m-title]').textContent = found ? 'Found in a count, never recorded' : 'Donated in kind';
      el.querySelector('[data-m-form]').innerHTML = `
        <div class="as-pair">
          ${field('Basis', `<select data-f="basis">${Object.entries(data.forms.bases).map(([k, v]) => `<option value="${k}" ${k === form.basis ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select>`)}
          ${field(found ? 'Recognised on' : 'Received on', `<input type="date" data-f="date" value="${esc(form.date)}">`)}
        </div>
        ${found ? `
          ${field('Asset found in the count', data.uncapitalised.length
            ? `<select data-f="tag">${data.uncapitalised.map(a => `<option value="${esc(a.tag)}" ${a.tag === form.tag ? 'selected' : ''}>${esc(a.tag)} · ${esc(a.name)}</option>`).join('')}</select>`
            : '<input disabled value="Every item found in the count is on the register">')}
          <div class="as-pair">
            ${field('Deemed cost (KES)', input('amount', form.amount, 'inputmode="decimal" style="font-family:\'IBM Plex Mono\',monospace;"'))}
            ${field('Found in', input('source', form.source, 'placeholder="The count that found it"'))}
          </div>
          ${field('Reference', input('reference', form.reference, 'placeholder="Count sheet or valuation reference (optional)"'))}`
        : `
          <div class="as-pair">
            ${field('Asset class', `<select data-f="class">${data.forms.classes.map(c => `<option ${c.name === form.class ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select>`)}
            ${field('Useful life (years)', input('life', form.life, 'inputmode="numeric"'))}
          </div>
          ${field('Description on the register', input('description', form.description))}
          <div class="as-pair">
            ${field('Fair value (KES)', input('amount', form.amount, 'inputmode="decimal" style="font-family:\'IBM Plex Mono\',monospace;"'))}
            ${field('Donor', input('source', form.source, 'placeholder="Who gave it"'))}
          </div>
          <div class="as-pair">
            ${field('Deed of gift or donor letter', input('reference', form.reference, 'placeholder="Reference"'))}
            ${field('Location', input('location', form.location, 'list="as-locations" placeholder="Where it is kept"') + locationList())}
          </div>
          <div class="as-pair">
            ${field('Custodian', input('custodian', form.custodian, 'placeholder="Who holds it"'))}
            ${field('Title', input('title', form.title, 'placeholder="ELOG holds title — donated in kind"'))}
          </div>`}
        ${field(found ? 'Why it was never recorded, and how the value was set' : 'What was donated, and how the fair value was set',
          `<textarea data-f="reason" rows="3">${esc(form.reason)}</textarea>`)}
        <div data-m-docs></div>`;
      UI.docPicker(el.querySelector('[data-m-docs]'), {
        label: found ? 'Count sheet or photo' : 'Deed of gift, donor letter or valuation', recommended: true, files: form.files,
        hint: found ? 'What the count recorded, and a photo of the asset with its tag.' : 'The document behind the fair value, and the donor\'s letter.',
      });
    }

    function block() {
      const found = form.basis === 'found';
      const c = classOf();
      const amount = money(form.amount);
      if (found && !foundAsset()) return 'Choose an asset found in the count that is not yet on the register.';
      if (!found && !c) return 'Choose the asset class.';
      if (!found && !form.description.trim()) return 'Describe the asset as it should appear on the register.';
      if (amount <= 0) return found ? 'Enter the deemed cost the asset comes onto the register at.' : 'Enter the fair value of the donated asset.';
      const purchase = c && data.capitalise.rows.find(r => r.account === c.account && r.remaining === amount);
      if (purchase) return `${purchase.ref} put ${fmt(amount)} on ${c.account} and is waiting to be capitalised. An asset that was bought is capitalised from its purchase, not added here.`;
      if (!form.source.trim()) return found ? 'Name the count that found the asset.' : 'Name the donor.';
      if (!found && !form.reference.trim()) return 'Reference the deed of gift or the donor’s letter.';
      if (!/^\d{4}-\d{2}-\d{2}$/.test(form.date)) return 'Enter the date.';
      if (!found && ((parseInt(form.life, 10) || 0) < 1 || (parseInt(form.life, 10) || 0) > 50)) return 'Enter a useful life of 1 to 50 years.';
      if (!found && !form.location.trim()) return 'Record where the asset is kept.';
      if (!form.reason.trim()) return found ? 'Record why the asset was never recorded and how its value was set.' : 'Record what was donated and how its fair value was set.';
      return '';
    }

    function renderSide() {
      const found = form.basis === 'found';
      const c = classOf();
      const amount = money(form.amount);
      const why = block();
      el.querySelector('[data-m-side]').innerHTML = `
        <div style="display:flex;flex-direction:column;gap:6px;">
          <span style="font-size:10px;letter-spacing:.09em;text-transform:uppercase;color:#8B948F;">Journal on approval</span>
          <div style="border:1px solid #EEEDE8;border-radius:7px;overflow:hidden;">
            <div class="as-jl"><span style="font-size:10.5px;font-weight:600;color:var(--accent-ink);">Dr</span><span>${c ? esc(c.account + ' · ' + c.accountName) : 'Class cost account'}</span><span class="as-mono" style="text-align:end;font-size:11.5px;">${fmt(amount)}</span></div>
            <div class="as-jl"><span style="font-size:10.5px;font-weight:600;color:#A5442F;">Cr</span><span>${esc(data.forms.credits[form.basis])}</span><span class="as-mono" style="text-align:end;font-size:11.5px;">${fmt(amount)}</span></div>
          </div>
        </div>
        <div class="as-note warn">${found
          ? 'The asset should have been on the register already, so its value is a correction to the fund balance brought forward, not income this year. It is depreciated from the month after it is recognised.'
          : 'A donated asset is income in kind at its fair value when it is received, and is depreciated like a purchase from the month after.'}
          Anything that was bought is capitalised from its purchase instead.</div>
        ${why ? `<div class="as-note block">${esc(why)}</div>` : ''}
        <div style="display:flex;align-items:center;gap:8px;">
          <button type="button" class="btn" data-m-close>Cancel</button>
          <button type="button" class="btn btn-primary" data-m-submit ${why ? 'disabled' : ''}>Submit for approval</button>
        </div>`;
    }

    async function submit() {
      if (block()) return;
      if (form.files.some(f => !f.id && !f.error)) return UI.toast('Wait for the document to finish uploading.');
      const { files, ...fields } = form;
      const ok = await act('/api/assets/additions', Object.assign({}, fields, { amount: money(form.amount), documents: files.filter(f => f.id).map(f => f.id) }), el.querySelector('[data-m-submit]'));
      if (ok) el.hidden = true;
    }

    return { open };
  })();

  shell();
  await refresh();
  if (params.get('asset')) Asset.open(params.get('asset'));

})();
