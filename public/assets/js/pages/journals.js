/**
 * Journals (v5): the register of double-entry batches, filtered by status, type
 * and search, ten rows a page. A row opens the entry in the journal editor
 * (UI.openJournal); /journals/<ref> opens it straight away. Recurring templates
 * live in their own drawer. Figures come from /api/journals.
 */
(async function () {
  const app = document.getElementById('app');
  const esc = UI.esc;
  const state = { status: 'All', type: 'All types', q: '', page: 1 };
  let data = null;

  async function refresh() {
    const p = new URLSearchParams({ status: state.status, type: state.type, q: state.q, page: state.page });
    try {
      data = await UI.fetchJSON('/api/journals?' + p.toString());
    } catch (err) {
      app.querySelector('#jr-table').innerHTML = `<div class="coa-empty">${esc(err.message)}</div>`;
      return;
    }
    state.page = data.page;
    render();
  }

  function shell() {
    app.innerHTML = `
      <div class="page-head" style="margin-bottom:0;">
        <div>
          <h1 class="page-title" style="margin-top:0;">Journals</h1>
          <p class="page-blurb" style="max-width:640px;">Double-entry batches awaiting preparation, approval and posting. Nothing reaches the general ledger until it balances and a second person approves it.</p>
        </div>
        <div class="page-actions" style="margin-left:0;margin-inline-start:auto;">
          <button type="button" class="btn" id="jr-recurring">Recurring templates</button>
          <button type="button" class="btn btn-primary" id="jr-new">+ New journal</button>
        </div>
      </div>
      <div class="stat-grid" id="jr-stats" style="margin:18px 0 0;"></div>
      <div class="jr-filters">
        <div class="coa-seg" id="jr-tabs"></div>
        <label class="coa-search" style="flex:1 1 220px;min-width:190px;max-width:300px;width:auto;">⌕
          <input type="search" id="jr-q" placeholder="Reference, narration or preparer">
        </label>
        <label class="jr-type">Type <select id="jr-type"></select></label>
        <div class="jr-hint" id="jr-hint"></div>
      </div>
      <div class="coa-card">
        <div style="overflow-x:auto;"><div style="min-width:1476px;" id="jr-table"></div></div>
        <div id="jr-pager"></div>
        <div class="coa-foot">
          <span id="jr-footer"></span>
          <span style="margin-inline-start:auto;" id="jr-policy"></span>
        </div>
      </div>`;

    let searchTimer;
    app.querySelector('#jr-q').addEventListener('input', (e) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => { state.q = e.target.value; state.page = 1; refresh(); }, 200);
    });
    app.querySelector('#jr-type').addEventListener('change', (e) => { state.type = e.target.value; state.page = 1; refresh(); });
    app.querySelector('#jr-tabs').addEventListener('click', (e) => {
      const tab = e.target.closest('[data-status]');
      if (!tab) return;
      state.status = tab.dataset.status;
      state.page = 1;
      refresh();
    });
    app.querySelector('#jr-pager').addEventListener('click', (e) => {
      const b = e.target.closest('[data-page]');
      if (!b || b.disabled) return;
      state.page = +b.dataset.page;
      refresh();
    });
    const table = app.querySelector('#jr-table');
    table.addEventListener('click', (e) => {
      const row = e.target.closest('[data-ref]');
      if (row) open(row.dataset.ref);
    });
    table.addEventListener('keydown', (e) => {
      const row = e.target.closest('[data-ref]');
      if (row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); open(row.dataset.ref); }
    });
    app.querySelector('#jr-new').addEventListener('click', () => UI.openNewJournalDrawer({ onSaved: refresh }));
    app.querySelector('#jr-recurring').addEventListener('click', () => Recurring.open());
  }

  function render() {
    app.querySelector('#jr-stats').innerHTML = data.stats.map(s => `
      <div class="stat">
        <div class="stat-label">${esc(s.label)}</div>
        <div class="stat-value">${esc(s.value)}</div>
        <div class="stat-note">${esc(s.note)}</div>
      </div>`).join('');

    app.querySelector('#jr-tabs').innerHTML = data.tabs.map(t => `
      <button type="button" class="coa-seg-btn ${t.label === state.status ? 'on' : ''}" data-status="${esc(t.label)}">${esc(t.label)} (${t.count})</button>`).join('');

    app.querySelector('#jr-type').innerHTML = data.typeOptions.map(t => `<option ${t === state.type ? 'selected' : ''}>${esc(t)}</option>`).join('');
    app.querySelector('#jr-hint').textContent = data.hint;
    app.querySelector('#jr-footer').textContent = data.footer;
    app.querySelector('#jr-policy').textContent = data.policy;

    app.querySelector('#jr-table').innerHTML = `
      <div class="jr-grid coa-head">
        <div>Reference</div><div>Date</div><div>Type</div><div>Narration</div><div>Fund</div><div>Programme</div>
        <div>Grant / award</div><div style="text-align:end;">Lines</div><div style="text-align:end;">Amount</div><div>Status</div><div>Prepared by</div>
      </div>
      ${data.rows.map(j => `
        <div class="jr-grid jr-row" data-ref="${esc(j.ref)}" tabindex="0">
          <div class="jr-ref">${esc(j.ref)}</div>
          <div class="jr-date">${esc(j.date)}</div>
          <div class="coa-cell">${esc(j.type)}</div>
          <div class="jr-narration">${esc(j.narration)}</div>
          <div class="coa-cell">${esc(j.fund)}</div>
          <div class="coa-cell">${esc(j.program)}</div>
          <div style="display:flex;align-items:center;gap:5px;">
            <span class="coa-cell">${esc(j.grant)}</span>
            ${j.grantMissing ? '<span class="gl-flag" title="Restricted line without an award">!</span>' : ''}
          </div>
          <div class="coa-amount" style="font-size:11.5px;color:#6E7873;">${j.lines}</div>
          <div class="coa-amount" style="color:#28352F;">${esc(j.amount)}</div>
          <div>${UI.statusPill(j.status)}</div>
          <div class="coa-cell" style="color:#6E7873;">${esc(j.preparer)}</div>
        </div>`).join('')}
      ${data.rows.length === 0 ? '<div class="coa-empty">No journals match this filter.</div>' : ''}`;

    app.querySelector('#jr-pager').innerHTML = data.pages > 1 ? pager() : '';
  }

  function pager() {
    const p = data.page;
    const pages = data.pages;
    const from = pages > 9 ? Math.min(Math.max(p - 5, 1), pages - 8) : 1;
    const to = pages > 9 ? from + 8 : pages;
    const buttons = [];
    for (let k = from; k <= to; k++) buttons.push(`<button type="button" class="coa-page ${k === p ? 'on' : ''}" data-page="${k}">${k}</button>`);
    return `
      <div class="coa-pager">
        <span>Showing ${(p - 1) * data.pageSize + 1}–${Math.min(data.filtered, p * data.pageSize)} of ${data.filtered} entries</span>
        <div style="margin-inline-start:auto;display:flex;align-items:center;gap:4px;">
          <button type="button" class="coa-page" data-page="${p - 1}" ${p <= 1 ? 'disabled' : ''}>‹</button>
          ${buttons.join('')}
          <button type="button" class="coa-page" data-page="${p + 1}" ${p >= pages ? 'disabled' : ''}>›</button>
        </div>
      </div>`;
  }

  /** Opens an entry in the editor, keeping its address in the location bar while it is open. */
  function open(ref) {
    history.replaceState(null, '', '/journals/' + encodeURIComponent(ref));
    UI.openJournal(ref, {
      onSaved: refresh,
      onClose: () => history.replaceState(null, '', '/journals'),
    });
  }

  // ---- Recurring templates drawer ----

  const Recurring = (() => {
    let el;
    let rec = null; // { templates, journals, summary }
    let selected = null;

    function build() {
      el = document.createElement('div');
      el.className = 'rt';
      el.hidden = true;
      el.innerHTML = `
        <div class="jd-backdrop" data-rt-close style="background:rgba(13,27,24,.28);"></div>
        <div class="rt-panel" role="dialog" aria-modal="true" aria-label="Recurring templates">
          <div class="rt-head">
            <div style="display:flex;flex-direction:column;gap:4px;min-width:0;">
              <div class="jd-caps" style="letter-spacing:.1em;">Journals</div>
              <div style="font-size:16px;font-weight:600;letter-spacing:-.015em;" id="rt-title"></div>
              <div style="font-size:11.5px;color:#7A857F;" id="rt-summary"></div>
            </div>
            <button type="button" class="rt-close" data-rt-close aria-label="Close">×</button>
          </div>
          <div class="rt-body" id="rt-body"></div>
          <div class="rt-foot" id="rt-foot" hidden></div>
        </div>`;
      document.body.appendChild(el);

      el.addEventListener('click', (e) => {
        if (e.target.closest('[data-rt-close]')) return close();
        const t = e.target.closest('[data-template]');
        if (t) { selected = t.dataset.template; return render(); }
        if (e.target.closest('[data-rt-back]')) { selected = null; return render(); }
        const from = e.target.closest('[data-from]');
        if (from) return act(() => UI.postJSON('/api/journals/recurring', { ref: from.dataset.from }), (d) => {
          selected = d.template.id;
          return `${d.template.name} saved as recurring template ${d.template.id}, next due ${d.template.next}.`;
        });
        const run = e.target.closest('[data-run-ref]');
        if (run) {
          close();
          return open(run.dataset.runRef);
        }
        if (e.target.closest('[data-rt-toggle]')) {
          const t = find();
          return act(() => UI.postJSON(`/api/journals/recurring/${encodeURIComponent(t.id)}/toggle`), (d) => d.template.status === 'Paused'
            ? `${t.name} paused — it will not generate on ${t.next}.`
            : `${t.name} resumed — next entry due ${t.next}.`);
        }
        if (e.target.closest('[data-rt-run]')) {
          const t = find();
          return act(() => UI.postJSON(`/api/journals/recurring/${encodeURIComponent(t.id)}/run`), (d) => {
            refresh();
            return `${d.journal.ref} generated from ${t.name} and ${d.journal.status === 'Draft' ? 'saved as a draft' : 'submitted for approval'}. It reaches the ledger only once approved.`;
          });
        }
      });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !el.hidden) close(); });
    }

    const find = () => rec.templates.find(t => t.id === selected);

    async function load() {
      rec = await UI.fetchJSON('/api/journals/recurring');
    }

    async function act(request, message) {
      const buttons = el.querySelectorAll('#rt-body button, #rt-foot button');
      buttons.forEach(b => { b.disabled = true; });
      try {
        const d = await request();
        const text = message(d);
        await load();
        render();
        UI.toast(text);
      } catch (err) {
        UI.toast(err.message);
        render();
      }
    }

    function render() {
      const t = selected ? find() : null;
      const $ = (id) => el.querySelector('#' + id);
      $('rt-title').textContent = t ? t.name : 'Recurring templates';
      $('rt-summary').textContent = t ? `${t.frequency} · ${t.rule} · owned by ${t.owner}` : rec.summary;
      const amount = (x) => x.lines.reduce((a, l) => a + (+l.dr || 0), 0);

      if (!t) {
        $('rt-foot').hidden = true;
        $('rt-foot').innerHTML = '';
        $('rt-body').style.gap = '10px';
        $('rt-body').innerHTML = rec.templates.map(x => {
          const active = x.status === 'Active';
          return `
            <button type="button" class="rt-card" data-template="${esc(x.id)}">
              <span style="gap:9px;">
                <span style="font-size:13px;font-weight:600;color:#16211E;">${esc(x.name)}</span>
                ${active ? '' : '<span class="jr-pill draft" style="padding:1px 7px;font-size:10px;">Paused</span>'}
                <span style="margin-inline-start:auto;font-family:'IBM Plex Mono',monospace;font-size:11px;color:#9AA39E;">${esc(x.id)}</span>
              </span>
              <span style="font-size:11.5px;color:#7A857F;">
                <span>${esc(x.frequency)} · ${esc(x.rule)}</span>
                <span class="pc-mono" style="margin-inline-start:auto;color:#28352F;">${UI.fmtMoney(amount(x))}</span>
              </span>
              <span style="font-size:11px;">
                <span style="color:#9AA39E;">${x.lines.length} lines · ${esc(x.type.toLowerCase())}</span>
                <span style="margin-inline-start:auto;color:${active ? 'var(--calm-ink)' : '#9AA39E'};">${active ? 'Next ' : 'Was due '}${esc(x.next)}</span>
              </span>
            </button>`;
        }).join('') + `
          <div style="margin-top:8px;border-top:1px solid #EEEDE8;padding-top:14px;display:flex;flex-direction:column;gap:8px;">
            <div class="jd-caps">Create from a recent entry</div>
            <div style="font-size:11.5px;color:#7A857F;line-height:1.5;">An entry you raise every month is better held as a template — the lines, coding and narration carry forward and only the date changes.</div>
            <div style="display:flex;flex-direction:column;gap:5px;">
              ${rec.journals.map(j => `<button type="button" class="rt-option" data-from="${esc(j.ref)}">${esc(j.label)}</button>`).join('')}
            </div>
          </div>`;
        return;
      }

      const field = (label, value, mono) => `
        <div style="display:flex;flex-direction:column;gap:3px;">
          <span class="jd-caps">${esc(label)}</span>
          <span style="${mono ? "font-family:'IBM Plex Mono',monospace;font-size:11.5px;" : 'font-size:12.5px;'}color:#3E4A44;">${esc(value)}</span>
        </div>`;
      const runColour = (s) => s === 'Posted' ? 'var(--calm-ink)' : s === 'Pending approval' ? '#8A5B2E' : '#7A857F';

      $('rt-body').style.gap = '18px';
      $('rt-body').innerHTML = `
        <button type="button" class="rt-back" data-rt-back>‹ All templates</button>
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 16px;">
          ${field('Next due', t.next)}${field('Last generated', t.last)}${field('Entry type', t.type)}${field('Document series', t.doc, true)}
        </div>
        <div style="display:flex;flex-direction:column;gap:6px;">
          <span class="jd-caps">Narration and basis</span>
          <span style="font-size:12.5px;color:#28352F;">${esc(t.narration)}</span>
          <span style="font-size:11.5px;color:#7A857F;line-height:1.55;">${esc(t.memo)}</span>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px;">
          <span class="jd-caps">Lines generated at each run</span>
          <div style="border:1px solid #EEEDE8;border-radius:7px;overflow:hidden;">
            ${t.lines.map(l => `
              <div class="rt-line">
                <span style="padding:0 12px;font-size:10.5px;font-weight:600;color:${l.dr ? 'var(--accent-ink)' : '#8A5B2E'};">${l.dr ? 'Dr' : 'Cr'}</span>
                <span style="font-family:'IBM Plex Mono',monospace;font-size:11px;color:var(--accent);">${esc(l.code)}</span>
                <span style="padding:0 10px;display:flex;flex-direction:column;gap:1px;min-width:0;">
                  <span class="coa-cell" style="color:#28352F;">${esc(l.desc)}</span>
                  <span class="coa-cell" style="font-size:10.5px;color:#9AA39E;">${esc(l.fund)} · ${esc(l.program)}</span>
                </span>
                <span class="coa-amount" style="padding:0 12px;font-size:11.5px;color:#28352F;">${UI.fmtMoney(l.dr || l.cr)}</span>
              </div>`).join('')}
            <div style="display:grid;grid-template-columns:minmax(0,1fr) 112px;align-items:center;height:34px;background:#FAF9F6;">
              <span style="padding:0 12px;font-size:11px;color:#7A857F;">${t.autoSubmit ? 'Generated entries go straight to Pending approval.' : 'Generated entries are saved as drafts for the owner to review first.'}</span>
              <span class="coa-amount" style="padding:0 12px;font-size:11.5px;font-weight:600;color:#16211E;">${UI.fmtMoney(amount(t))}</span>
            </div>
          </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px;">
          <span class="jd-caps">Run history</span>
          ${t.runs.map(r => `
            <button type="button" class="rt-run" data-run-ref="${esc(r.ref)}">
              <span style="font-family:'IBM Plex Mono',monospace;font-size:11px;color:#9AA39E;min-width:88px;">${esc(r.when)}</span>
              <span style="font-family:'IBM Plex Mono',monospace;font-size:11.5px;color:var(--accent);">${esc(r.ref)}</span>
              <span style="margin-inline-start:auto;font-size:11px;color:${runColour(r.status)};">${esc(r.status)}</span>
            </button>`).join('')}
          ${t.runs.length ? '' : '<span style="font-size:11.5px;color:#8B948F;">Not yet run.</span>'}
        </div>`;

      const active = t.status === 'Active';
      $('rt-foot').hidden = false;
      $('rt-foot').innerHTML = `
        <button type="button" class="btn" data-rt-toggle>${active ? 'Pause template' : 'Resume template'}</button>
        ${active
          ? '<button type="button" class="btn btn-primary" data-rt-run style="margin-inline-start:auto;">Generate entry now</button>'
          : `<span style="margin-inline-start:auto;font-size:11.5px;color:#8A5B2E;">Paused — no entry will be generated on ${esc(t.next)}.</span>`}`;
    }

    /** Opens the drawer on the list, or on one template when `code` names it. */
    async function openDrawer(code) {
      if (!el) build();
      try {
        await load();
      } catch (err) {
        UI.toast('Recurring templates could not be loaded.');
        return;
      }
      selected = typeof code === 'string' && rec.templates.some(t => t.id === code) ? code : null;
      render();
      el.hidden = false;
    }

    function close() {
      if (el) el.hidden = true;
    }

    return { open: openDrawer };
  })();

  shell();
  await refresh();

  const openRef = app.getAttribute('data-open');
  if (openRef) open(openRef);
  const template = new URLSearchParams(location.search).get('template');
  if (template) {
    history.replaceState(null, '', '/journals');
    Recurring.open(template);
  }
})();
