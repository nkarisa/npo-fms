/**
 * Programmes (v5).
 *
 * The activity dimension on every posting line. Two things make this screen
 * different from a plain register: shared costs are recovered across programmes
 * by the percentages held here, so the base must always total 100% and opening a
 * programme is also a re-basing of every other one; and a programme that carries
 * history can only ever be deactivated, never deleted, so the drawer spends more
 * room on what stands in the way of closing one than on closing it.
 */
(async function () {
  const app = document.getElementById('app');
  const esc = UI.esc;
  const params = new URLSearchParams(location.search);
  const state = { filter: 'All', q: '' };
  let data = null;
  let searchTimer;

  async function refresh() {
    const p = new URLSearchParams({ filter: state.filter, q: state.q });
    data = await UI.fetchJSON('/api/programmes?' + p.toString());
    render();
  }

  // ---- The register ----

  function render() {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Accounting',
      title: 'Programmes',
      blurb: 'The activity dimension on every posting line. Shared costs are recovered across programmes by the '
        + 'percentages held here, so the allocation must always total 100%.',
      actions: '<button type="button" class="btn btn-primary" id="pg-new">+ New programme</button>',
    });
    document.getElementById('pg-new').addEventListener('click', openNew);

    app.appendChild(UI.statGrid(data.stats));

    // A base that balances needs no commentary; one that does not is an
    // accounting fault and says so in those terms.
    if (data.shareWarning) {
      const warn = document.createElement('div');
      warn.className = 'pg-warn';
      warn.textContent = data.shareWarning;
      app.appendChild(warn);
    }

    app.appendChild(register());
  }

  function register() {
    const card = document.createElement('div');
    card.className = 'card';

    const toolbar = document.createElement('div');
    toolbar.className = 'toolbar';
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Programme, code or manager';
    search.value = state.q;
    search.addEventListener('input', (e) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(async () => {
        state.q = e.target.value;
        const at = e.target.selectionStart;
        await refresh();
        const again = app.querySelector('.toolbar input[type=search]');
        again.focus();
        again.setSelectionRange(at, at);
      }, 220);
    });
    toolbar.appendChild(search);
    card.appendChild(toolbar);

    card.appendChild(UI.tabs(data.tabs, state.filter, (label) => { state.filter = label; refresh(); }));

    const head = document.createElement('div');
    head.className = 'pg-grid coa-head';
    head.innerHTML = [
      ['Programme', ''], ['Manager', ''], ['Budget', 'end'], ['Actual', 'end'], ['Committed', 'end'],
      ['Remaining', 'end'], ['Burn', ''], ['Shared cost', 'end'], ['Status', ''],
    ].map(([label, align]) => `<div style="padding:9px 12px;${align ? 'text-align:end;' : ''}">${esc(label)}</div>`).join('');
    card.appendChild(head);

    if (data.rows.length) {
      data.rows.forEach((r) => card.appendChild(row(r)));
    } else {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = 'No programmes match this view.';
      card.appendChild(empty);
    }

    const footer = document.createElement('div');
    footer.className = 'card-footer';
    footer.textContent = data.footer;
    card.appendChild(footer);
    return card;
  }

  function row(r) {
    const div = document.createElement('div');
    div.className = 'pg-grid pg-row';
    div.tabIndex = 0;
    div.innerHTML = `
      <div class="pg-who">
        <span>${esc(r.name)}</span>
        <span>${esc(r.code)} · since ${esc(r.since)}</span>
      </div>
      <div class="pg-plain">${esc(r.manager)}</div>
      <div class="pg-mono lead">${esc(r.budget)}</div>
      <div class="pg-mono">${esc(r.actual)}</div>
      <div class="pg-mono">${esc(r.committed)}</div>
      <div class="pg-mono ${r.overspent ? 'over' : ''}">${esc(r.remaining)}</div>
      <div class="pg-burn">
        <span class="pg-track"><span class="${r.burnHeavy ? 'heavy' : ''}" style="width:${r.burnPct}%;"></span></span>
        <span class="pg-burn-pct">${esc(r.burnLabel)}</span>
      </div>
      <div class="pg-mono">${esc(r.share)}</div>
      <div><span class="pg-pill ${r.status.toLowerCase()}">${esc(r.status)}</span></div>`;
    const open = () => openDetail(r.code);
    div.addEventListener('click', open);
    div.addEventListener('keydown', (e) => { if (e.key === 'Enter') open(); });
    return div;
  }

  // ---- One programme ----

  let detail = null;
  let rename = '';

  async function openDetail(code) {
    try {
      detail = await UI.fetchJSON('/api/programmes/' + encodeURIComponent(code));
    } catch (err) {
      UI.toast(err.message);
      return;
    }
    rename = detail.name;
    drawDetail();
  }

  function drawDetail() {
    const d = detail;
    const changed = rename.trim() !== '' && rename.trim() !== d.name;

    const funds = d.funds.length ? `
      <div>
        <div class="pg-section-label">Funds charged to this programme</div>
        <div class="pg-chips">${d.funds.map((f) => `<span>${esc(f)}</span>`).join('')}</div>
      </div>` : '';

    const renames = d.renames.length ? `
      <div>
        <div class="pg-section-label">Previously known as</div>
        ${d.renames.map((r) => `<div class="pg-trail"><span>${esc(r.on)}</span><span>${esc(r.from)} → ${esc(r.to)}, by ${esc(r.by)}</span></div>`).join('')}
      </div>` : '';

    const deactivation = d.isInactive
      ? '<div class="pg-note">Already inactive. Every past posting stays reportable and appears in prior-year comparatives; the programme simply cannot be selected on anything new.</div>'
      : d.blockers.length
        ? `<div class="pg-blockers">
             <div class="pg-blockers-lead">This programme cannot be deactivated yet. Clear the following first:</div>
             ${d.blockers.map((b) => `<div class="pg-blocker"><b>${esc(b.what)}</b><span>${esc(b.detail)}</span></div>`).join('')}
           </div>`
        : '<div class="pg-note ok">Nothing is outstanding. Deactivating keeps every past posting reportable and only stops the programme being selected on new journals, requisitions and budget lines.</div>';

    UI.drawer(`${d.code} · ${d.name}`, `
      <div class="pg-head">
        <span class="pg-head-code">${esc(d.code)} · ${esc(d.status)}</span>
        <b>${esc(d.name)}</b>
        <small>${esc(d.shareText)}</small>
      </div>
      <div class="pg-body">
        <div class="pg-purpose">${esc(d.purpose)}</div>
        <div class="pg-facts">
          ${d.facts.map((f) => `<div class="pg-fact"><span>${esc(f.label)}</span><span>${esc(f.value)}</span></div>`).join('')}
        </div>
        ${funds}
        <div>
          <div class="pg-section-label">Rename</div>
          <div class="pg-rename">
            <input id="pg-rename" value="${esc(rename)}">
            ${changed ? '<button type="button" class="btn btn-primary" data-do="rename">Save</button>' : ''}
          </div>
          <div class="pg-hint">A rename changes the label from now on. Journals already posted keep the name they were
            recorded under, which is what an auditor expects to see.</div>
        </div>
        ${renames}
        <div>
          <div class="pg-section-label">Deactivation</div>
          ${deactivation}
        </div>
      </div>
      <div class="pg-foot">
        ${d.hasShare ? '<button type="button" class="btn" data-do="zero-share">Zero shared-cost share</button>' : ''}
        <button type="button" class="btn pg-close" data-do="close">Close</button>
        ${d.isInactive ? '<button type="button" class="btn btn-primary" data-do="reactivate">Reopen programme</button>' : ''}
        ${d.canDeactivate ? '<button type="button" class="btn pg-danger" data-do="deactivate">Deactivate</button>' : ''}
      </div>`, { wide: true });

    const box = document.getElementById('pg-rename');
    box.addEventListener('input', () => {
      const was = changed;
      rename = box.value;
      // The Save button only exists while the name differs, so the drawer is
      // redrawn when that crosses over — and the caret put back where it was.
      if (was !== (rename.trim() !== '' && rename.trim() !== detail.name)) {
        const at = box.selectionStart;
        drawDetail();
        const again = document.getElementById('pg-rename');
        again.focus();
        again.setSelectionRange(at, at);
      }
    });

    document.querySelectorAll('[data-do]').forEach((b) => b.addEventListener('click', () => act(b, b.dataset.do)));
  }

  async function act(button, what) {
    if (what === 'close') return UI.closeDrawer();

    const url = '/api/programmes/' + encodeURIComponent(detail.code) + '/' + what;
    const body = what === 'rename' ? { name: rename.trim() } : {};

    button.disabled = true;
    try {
      const result = await UI.postJSON(url, body);
      UI.toast(result.message);
      await refresh();
      await openDetail(detail.code);
    } catch (err) {
      UI.toast(err.message);
      button.disabled = false;
    }
  }

  // ---- Opening a programme, and re-basing the shared costs ----

  let form = null;

  function openNew() {
    form = {
      name: '', code: '', manager: data.form.managers[0] || '', since: today(),
      purpose: '', share: '', mode: 'prorate',
      shares: data.form.allocable.map((a) => ({ code: a.code, name: a.name, was: a.was, pct: String(a.share) })),
    };
    drawNew();
  }

  function today() {
    return new Date().toISOString().slice(0, 10);
  }

  const pct = (v) => parseFloat(String(v == null ? '' : v).replace(/[^0-9.]/g, '')) || 0;
  const round1 = (n) => Math.round(n * 1000) / 1000;

  /**
   * What the allocation table shows for the chosen mode. It mirrors the rule the
   * API applies on save — proportional, untouched, or whatever was typed — so the
   * preview and the posted result agree, with the last share taking the remainder.
   */
  function allocation() {
    const share = pct(form.share);
    const existing = data.form.allocable;

    if (form.mode === 'defer') {
      return existing.map((a) => ({ name: a.name, was: a.was, pct: a.share, editable: false }))
        .concat([{ name: form.name.trim() || 'New programme', was: '—', pct: 0, isNew: true }]);
    }

    if (form.mode === 'manual') {
      return form.shares.map((s, i) => ({ name: s.name, was: s.was, pct: s.pct, editable: true, at: i }))
        .concat([{ name: form.name.trim() || 'New programme', was: '—', pct: share, isNew: true }]);
    }

    const base = existing.reduce((t, a) => t + a.share, 0);
    let run = 0;
    const scaled = existing.map((a, i) => {
      const value = i === existing.length - 1
        ? round1(100 - share - run)
        : round1(base > 0 ? (a.share / base) * (100 - share) : (100 - share) / existing.length);
      run = round1(run + value);
      return { name: a.name, was: a.was, pct: value, editable: false };
    });
    return scaled.concat([{ name: form.name.trim() || 'New programme', was: '—', pct: share, isNew: true }]);
  }

  function drawNew() {
    const rows = allocation();
    const total = round1(rows.reduce((t, r) => t + pct(r.pct), 0));
    const balanced = Math.abs(total - 100) <= 0.05;
    const mode = data.form.modes.find((m) => m.key === form.mode);
    const error = validate(total);

    modal('pg-new-modal', 'New programme', 'Open a programme and re-base shared costs', `
      <div class="pg-form-pair">
        <label class="as-field"><span>Programme name</span>
          <input data-f="name" value="${esc(form.name)}" placeholder="e.g. Media Monitoring"></label>
        <label class="as-field"><span>Code</span>
          <input data-f="code" value="${esc(form.code)}" placeholder="auto" style="font-family:'IBM Plex Mono',monospace;"></label>
      </div>

      <div class="pg-form-pair even">
        <label class="as-field"><span>Programme manager</span>
          <select data-f="manager">${data.form.managers.map((m) => `<option ${m === form.manager ? 'selected' : ''}>${esc(m)}</option>`).join('')}</select></label>
        <label class="as-field"><span>Active from</span>
          <input data-f="since" type="date" value="${esc(form.since)}"></label>
      </div>

      <label class="as-field"><span>What the programme covers</span>
        <textarea data-f="purpose" rows="2" placeholder="The boundary that makes coding decisions consistent between people">${esc(form.purpose)}</textarea></label>

      <div class="pg-rule"></div>

      <div class="pg-form-share">
        <label class="as-field"><span>Share of shared costs %</span>
          <input data-f="share" value="${esc(form.share)}" inputmode="numeric" placeholder="0" style="font-family:'IBM Plex Mono',monospace;"></label>
        <div>
          <div class="pg-section-label" style="margin-bottom:8px;">How the other programmes absorb it</div>
          <div class="pg-modes">
            ${data.form.modes.map((m) => `<button type="button" data-mode="${esc(m.key)}" class="${m.key === form.mode ? 'on' : ''}">${esc(m.label)}</button>`).join('')}
          </div>
          <div class="pg-hint" style="margin-top:8px;">${esc(mode.note)}</div>
        </div>
      </div>

      <div class="pg-alloc">
        <div class="pg-alloc-row coa-head">
          <div style="padding:8px 11px;">Programme</div>
          <div style="padding:8px 11px;text-align:end;">Was</div>
          <div style="padding:8px 11px;text-align:end;">New share</div>
        </div>
        ${rows.map((r) => `
          <div class="pg-alloc-row">
            <div class="pg-alloc-name">
              <span>${esc(r.name)}</span>
              ${r.isNew ? '<em>New</em>' : ''}
            </div>
            <div class="pg-alloc-was">${esc(r.was)}</div>
            <div class="pg-alloc-new">${r.editable
              ? `<input data-share="${r.at}" value="${esc(r.pct)}" inputmode="numeric">`
              : `<span>${esc(pct(r.pct))}%</span>`}</div>
          </div>`).join('')}
        <div class="pg-alloc-row total">
          <div class="pg-alloc-name"><span>Total</span></div>
          <div></div>
          <div class="pg-alloc-new"><span class="${balanced ? 'ok' : 'off'}">${round1(total)}%</span></div>
        </div>
      </div>

      <div class="pg-note">The programme opens as <strong>Pipeline</strong> with no budget. Nothing can be spent against it
        until an award is recorded or a budget revision adds lines — which is the control that stops a programme existing
        on paper and quietly absorbing shared costs.</div>`,
      'Open programme',
      error || 'Shared-cost allocation balances. Nothing is written until you open the programme.',
      !!error);

    const el = document.getElementById('pg-new-modal');
    el.querySelectorAll('[data-f]').forEach((input) => input.addEventListener('change', () => {
      form[input.dataset.f] = input.value;
      drawNew();
    }));
    el.querySelectorAll('[data-mode]').forEach((b) => b.addEventListener('click', () => {
      form.mode = b.dataset.mode;
      // Hand-set shares start from where the allocation stands today.
      if (form.mode === 'manual') {
        form.shares = data.form.allocable.map((a) => ({ code: a.code, name: a.name, was: a.was, pct: String(a.share) }));
      }
      drawNew();
    }));
    el.querySelectorAll('[data-share]').forEach((input) => input.addEventListener('change', () => {
      form.shares[+input.dataset.share].pct = input.value;
      drawNew();
    }));
    el.querySelector('[data-submit]').addEventListener('click', (e) => submitNew(e.target, error));
  }

  /** The same checks the API applies, said before the request rather than after it. */
  function validate(total) {
    const name = form.name.trim();
    const share = pct(form.share);

    if (!name) return 'Give the programme a name.';
    if (data.form.names.some((n) => n.toLowerCase() === name.toLowerCase())) return name + ' already exists.';
    if (!form.purpose.trim()) return 'State what the programme covers — it is what makes coding decisions consistent between people.';
    if (share < 0 || share >= 100) return 'The share of shared costs must be between 0 and 99%.';
    if (Math.abs(total - 100) > 0.05) {
      return 'The shared-cost allocation totals ' + round1(total) + '%. It has to be exactly 100% or support costs are under- or over-recovered.';
    }
    return '';
  }

  async function submitNew(button, error) {
    if (error) { UI.toast(error); return; }

    button.disabled = true;
    try {
      const result = await UI.postJSON('/api/programmes', {
        name: form.name.trim(), code: form.code.trim(), manager: form.manager, since: form.since,
        purpose: form.purpose.trim(), share: pct(form.share), mode: form.mode,
        shares: form.shares.map((s) => ({ code: s.code, pct: pct(s.pct) })),
      });
      closeModal();
      UI.toast(result.message);
      state.filter = 'All';
      state.q = '';
      await refresh();
      await openDetail(result.code);
    } catch (err) {
      UI.toast(err.message);
      button.disabled = false;
    }
  }

  // ---- The modal shell ----

  let modalEl = null;

  function modal(id, kicker, title, body, submitLabel, note, muted) {
    closeModal();
    modalEl = document.createElement('div');
    modalEl.className = 'ap-modal';
    modalEl.id = id;
    modalEl.innerHTML = `
      <div class="ap-modal-scrim" data-close></div>
      <div class="ap-modal-box" role="dialog" aria-modal="true" aria-label="${esc(title)}" style="width:820px;">
        <div class="jd-head" style="padding:16px 20px;border-bottom-color:#E4E2DB;">
          <div style="display:flex;flex-direction:column;gap:3px;">
            <div class="jd-caps" style="letter-spacing:.1em;">${esc(kicker)}</div>
            <div style="font-size:16px;font-weight:600;letter-spacing:-.01em;">${esc(title)}</div>
          </div>
          <button type="button" class="rt-close" data-close aria-label="Close">×</button>
        </div>
        <div class="ap-modal-body">${body}</div>
        <div class="ap-modal-foot">
          <div class="pg-modal-note ${muted ? 'bad' : ''}">${esc(note || '')}</div>
          <button type="button" class="btn" data-close style="height:34px;padding:0 14px;">Cancel</button>
          <button type="button" class="btn btn-primary ${muted ? 'pg-blocked' : ''}" data-submit style="height:34px;padding:0 18px;">${esc(submitLabel)}</button>
        </div>
      </div>`;
    document.body.appendChild(modalEl);
    modalEl.addEventListener('click', (e) => { if (e.target.closest('[data-close]')) closeModal(); });
  }

  function closeModal() {
    if (modalEl) modalEl.remove();
    modalEl = null;
  }

  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

  await refresh();
  if (params.get('programme')) await openDetail(params.get('programme'));
})();
