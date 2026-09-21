/**
 * Staff and observer advances (v5).
 *
 * Money handed to a person before it becomes expenditure. An advance is raised,
 * approved, issued — which is what puts it on 1220 as a receivable — chased when
 * it goes quiet, and finally cleared by receipts or taken off the holder's pay.
 * The ageing, not the bank balance, is what this screen is really reporting, so
 * the buckets and the control tie sit above the register rather than under it.
 */
(async function () {
  const app = document.getElementById('app');
  const params = new URLSearchParams(location.search);
  const state = { filter: 'Outstanding', age: 'All', q: '', page: 1 };
  let data = null;
  let searchTimer;

  async function load() {
    const p = new URLSearchParams({ filter: state.filter, age: state.age, q: state.q, page: state.page });
    return UI.fetchJSON('/api/advances?' + p.toString());
  }

  async function refresh() {
    data = await load();
    state.page = data.page;
    render();
  }

  // ---- The page ----

  function render() {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Accounting',
      title: 'Staff and observer advances',
      blurb: 'Money handed to a person before it becomes expenditure. Every issued advance is a receivable on 1220 '
        + 'until receipts are coded or the balance is recovered from pay — which is why the ageing here, and not the '
        + 'bank balance, is the real measure of field discipline.',
      actions: '<button type="button" class="btn btn-primary" id="adv-new">+ Request advance</button>',
    });
    document.getElementById('adv-new').addEventListener('click', openRequest);

    app.appendChild(UI.statGrid(data.stats));
    app.appendChild(buckets());
    app.appendChild(tie());
    app.appendChild(register());
  }

  function buckets() {
    const div = document.createElement('div');
    div.className = 'adv-buckets';
    div.innerHTML = data.aging.map((b) => `
      <div class="adv-bucket">
        <span>${UI.esc(b.label)}</span>
        <div><b class="${b.heavy ? 'heavy' : ''}">${UI.esc(b.value)}</b><small>${UI.esc(b.note)}</small></div>
      </div>`).join('');
    return div;
  }

  // The register has to agree to the control account. A difference means
  // expenditure was coded straight out of 1220 without a surrender.
  function tie() {
    const ok = data.control.reconciled;
    const div = document.createElement('div');
    div.className = 'card';
    div.style.cssText = ok ? 'background:var(--calm-wash);border-color:var(--calm-line);' : 'background:#FDF7EE;border-color:#F0DFC8;';
    div.innerHTML = `<div style="padding:12px 16px;font-size:12px;line-height:1.55;color:${ok ? 'var(--calm-ink)' : '#8A5B2E'};">
      <strong>${ok ? 'Register agrees to 1220' : 'Register leads the ledger'}</strong> —
      register ${UI.esc(data.control.register)} against ${UI.esc(data.control.balance)} posted.
      <div style="margin-top:5px;">${UI.esc(data.control.note)}</div></div>`;
    return div;
  }

  function register() {
    const card = document.createElement('div');
    card.className = 'card';

    const toolbar = document.createElement('div');
    toolbar.className = 'toolbar';
    toolbar.style.flexWrap = 'wrap';

    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Holder, reference or purpose…';
    search.value = state.q;
    search.addEventListener('input', (e) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => { state.q = e.target.value; state.page = 1; refresh(); }, 200);
    });

    const ages = document.createElement('div');
    ages.className = 'adv-ages';
    ages.innerHTML = ['All'].concat(data.buckets)
      .map((b) => `<button type="button" class="adv-age ${b === state.age ? 'on' : ''}" data-age="${UI.esc(b)}">${UI.esc(b)}</button>`).join('');
    ages.querySelectorAll('[data-age]').forEach((b) => b.addEventListener('click', () => {
      state.age = b.dataset.age;
      state.page = 1;
      refresh();
    }));

    toolbar.append(search, ages);
    card.appendChild(toolbar);
    card.appendChild(UI.tabs(data.tabs, state.filter, (label) => {
      state.filter = label;
      state.page = 1;
      refresh();
    }));

    const head = document.createElement('div');
    head.className = 'adv-grid coa-head';
    head.innerHTML = [
      ['Advance', ''], ['Holder', ''], ['Programme', ''], ['Grant / award', ''],
      ['Advanced', 'end'], ['Accounted', 'end'], ['Outstanding', 'end'], ['Due', ''], ['Ageing', ''], ['Status', ''],
    ].map(([label, align]) => `<div style="padding:9px 12px;${align ? 'text-align:end;' : ''}">${UI.esc(label)}</div>`).join('');
    card.appendChild(head);

    if (data.rows.length) {
      data.rows.forEach((r) => card.appendChild(row(r)));
      if (data.pages > 1) card.appendChild(pager());
    } else {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = 'No advances match this view.';
      card.appendChild(empty);
    }

    const footer = document.createElement('div');
    footer.className = 'card-footer';
    footer.textContent = data.footer;
    card.appendChild(footer);
    return card;
  }

  const AGE_CLASS = { 'Not due': 'd0', '1–30 days': 'd30', '31–60 days': 'd60', '60+ days': 'd90' };

  function row(r) {
    const div = document.createElement('div');
    div.className = 'adv-grid adv-row';
    div.innerHTML = `
      <div class="adv-who">
        <span>${UI.esc(r.holder)}</span>
        <span><span class="adv-ref">${UI.esc(r.ref)}</span> · ${UI.esc(r.purpose)}</span>
      </div>
      <div>${r.isObserver ? '<span class="adv-kind">Observer</span>' : '<span class="adv-plain">Staff</span>'}</div>
      <div class="adv-plain">${UI.esc(r.program)}</div>
      <div class="adv-plain">${UI.esc(r.grant)}</div>
      <div class="adv-mono lead">${UI.esc(r.amount)}</div>
      <div class="adv-mono">${UI.esc(r.accounted)}</div>
      <div class="adv-mono ${r.open ? (r.overdue ? 'late' : 'open') : 'nil'}">${UI.esc(r.outstanding)}</div>
      <div class="adv-plain">${UI.esc(r.dueDate)}</div>
      <div class="adv-age-cell ${r.open ? AGE_CLASS[r.bucket] || 'd0' : 'nil'}">${UI.esc(r.age)}</div>
      <div><span class="adv-pill ${r.status.toLowerCase()}">${UI.esc(r.status)}</span></div>`;
    div.addEventListener('click', () => openDetail(r.ref));
    return div;
  }

  function pager() {
    const div = document.createElement('div');
    div.className = 'coa-pager';
    const from = (data.page - 1) * data.pageSize + 1;
    const buttons = [];
    for (let k = 1; k <= data.pages; k++) {
      buttons.push(`<button type="button" class="coa-page ${k === data.page ? 'on' : ''}" data-page="${k}">${k}</button>`);
    }
    div.innerHTML = `
      <span>Showing ${from}–${Math.min(data.filtered, data.page * data.pageSize)} of ${data.filtered} advances</span>
      <div style="margin-inline-start:auto;display:flex;align-items:center;gap:4px;">
        <button type="button" class="coa-page" data-page="${data.page - 1}" ${data.page <= 1 ? 'disabled' : ''}>‹</button>
        ${buttons.join('')}
        <button type="button" class="coa-page" data-page="${data.page + 1}" ${data.page >= data.pages ? 'disabled' : ''}>›</button>
      </div>`;
    div.querySelectorAll('[data-page]').forEach((b) => b.addEventListener('click', () => {
      state.page = +b.dataset.page;
      refresh();
    }));
    return div;
  }

  // ---- One advance ----

  let detail = null;

  async function openDetail(ref) {
    try {
      detail = await UI.fetchJSON('/api/advances/' + encodeURIComponent(ref));
    } catch (err) {
      UI.toast(err.message);
      return;
    }
    drawDetail();
  }

  function drawDetail() {
    const d = detail;
    const flags = [
      d.overdueNote ? `<div class="adv-flag late">${UI.esc(d.overdueNote)}</div>` : '',
      d.leaverNote ? `<div class="adv-flag warn">${UI.esc(d.leaverNote)}</div>` : '',
      d.limitNote ? `<div class="adv-flag warn">${UI.esc(d.limitNote)}</div>` : '',
      d.rejectedNote ? `<div class="adv-flag late">${UI.esc(d.rejectedNote)}</div>` : '',
    ].join('');

    const receipts = d.receipts.length ? `
      <div>
        <div class="adv-section-label">Receipts coded on surrender</div>
        <div class="adv-receipts">
          ${d.receipts.map((r) => `
            <div class="adv-receipt">
              <div>
                <span class="adv-receipt-desc">${UI.esc(r.desc)}</span>
                <span class="adv-receipt-code">${UI.esc(r.code)} · ${UI.esc(r.account)}</span>
              </div>
              <span>${UI.fmtMoney(r.amount)}</span>
            </div>`).join('')}
          <div class="adv-receipt total">
            <div><span class="adv-receipt-desc">Coded to programme lines</span></div>
            <span>${UI.esc(d.receiptTotal)}</span>
          </div>
        </div>
      </div>` : '';

    const reminders = d.reminders.length ? `
      <div>
        <div class="adv-section-label">Reminders sent</div>
        ${d.reminders.map((r) => `<div class="adv-trail"><span>${UI.esc(r.on)}</span><span>Reminder ${r.level} — ${UI.esc(r.to)}</span></div>`).join('')}
      </div>` : '';

    // Issuing is the posting that makes an advance a receivable, so the method
    // is chosen here rather than assumed.
    const issue = d.canIssue ? `
      <div>
        <div class="adv-section-label">Pay out by</div>
        <select id="adv-method" style="height:34px;border:1px solid #DDDAD2;border-radius:7px;background:#FFF;font-size:12.5px;padding:0 8px;width:200px;">
          ${data.form.methods.map((m) => `<option>${UI.esc(m)}</option>`).join('')}
        </select>
        <div class="muted" style="font-size:11.5px;line-height:1.55;margin-top:6px;">Issuing debits 1220 and credits the paying account. No expenditure is recognised yet.</div>
      </div>` : '';

    UI.drawer(`${d.ref} · ${d.holder}`, `
      <div class="adv-head">
        <div class="adv-head-top">
          <span>${UI.esc(d.ref)}</span>
          <span class="adv-pill ${d.status.toLowerCase()}">${UI.esc(d.status)}</span>
        </div>
        <b>${UI.esc(d.holder)}</b>
        <small>${UI.esc(d.amountText)} advanced · ${UI.esc(d.outText)}</small>
      </div>
      <div class="adv-body">
        <div class="adv-purpose">${UI.esc(d.purpose)}</div>
        ${flags}
        <div class="adv-facts">
          ${d.facts.map((f) => `<div class="adv-fact"><span>${UI.esc(f.label)}</span><span>${UI.esc(f.value)}</span></div>`).join('')}
        </div>
        ${receipts}
        <div>
          <div class="adv-section-label">Receipt documents</div>
          <div id="adv-docs"></div>
        </div>
        ${reminders}
        <div>
          <div class="adv-section-label">Audit trail</div>
          ${d.trail.map((t) => `<div class="adv-trail"><span>${UI.esc(t.when)}</span><span>${UI.esc(t.what)}</span></div>`).join('')}
        </div>
        ${issue}
      </div>
      <div class="adv-foot">
        ${d.canRemind ? '<button type="button" class="btn" data-do="remind">Send reminder</button>' : ''}
        ${d.canRecover ? '<button type="button" class="btn adv-recover" data-do="recover">Recover from payroll</button>' : ''}
        ${d.canReject ? '<button type="button" class="btn adv-danger" data-do="reject">Reject</button>' : ''}
        <button type="button" class="btn adv-close" data-do="close">Close</button>
        ${d.canApprove ? '<button type="button" class="btn btn-primary" data-do="approve">Approve</button>' : ''}
        ${d.canIssue ? '<button type="button" class="btn btn-primary" data-do="issue">Issue funds</button>' : ''}
        ${d.canSurrender ? '<button type="button" class="btn btn-primary" data-do="surrender">Surrender with receipts</button>' : ''}
      </div>`, { wide: true });

    UI.docPanel(document.getElementById('adv-docs'), {
      kind: 'advance', ref: d.ref, docs: d.documents, canAdd: d.canAttach && d.status !== 'Requested',
      empty: d.receipts.length ? 'No receipts on file. This advance was surrendered before receipts were required — attach them if they are to hand.' : 'Receipts are attached when the advance is surrendered.',
      label: 'Attach a receipt',
      onAdded: (documents) => { d.documents = documents; },
    });
    document.querySelectorAll('[data-do]').forEach((b) => b.addEventListener('click', () => act(b, b.dataset.do)));
  }

  async function act(button, what) {
    if (what === 'close') return UI.closeDrawer();
    if (what === 'surrender') return openSurrender();
    if (what === 'reject') {
      const reason = window.prompt('Why is ' + detail.ref + ' refused? The requester has to know what to do instead.');
      if (reason === null) return;
      return post(button, '/api/advances/' + encodeURIComponent(detail.ref) + '/reject', { reason });
    }

    const body = what === 'issue' ? { method: document.getElementById('adv-method').value } : {};
    return post(button, '/api/advances/' + encodeURIComponent(detail.ref) + '/' + what, body);
  }

  async function post(button, url, body) {
    button.disabled = true;
    try {
      const result = await UI.postJSON(url, body);
      UI.toast(result.message);
      await refresh();
      await openDetail(detail.ref);
    } catch (err) {
      UI.toast(err.message);
      button.disabled = false;
    }
  }

  // ---- Surrendering against receipts ----

  let surrender = null;

  function openSurrender() {
    surrender = { lines: [{ code: data.form.codes[0].code, desc: '', amount: '' }], mode: 'refund', files: [] };
    drawSurrender();
  }

  const amountOf = (v) => parseFloat(String(v || '').replace(/[^0-9.]/g, '')) || 0;

  function drawSurrender() {
    const target = detail.surrenderTarget;
    const total = surrender.lines.reduce((t, l) => t + amountOf(l.amount), 0);
    const diff = Math.round((target - total) * 100) / 100;

    modal('adv-surrender', `Surrender · ${UI.esc(detail.ref)}`,
      `Code the receipts brought back by ${UI.esc(detail.holder)}`, `
      <div class="adv-modal-lines">
        <div class="adv-modal-line coa-head">
          <div style="padding:8px 11px;">Expenditure line</div>
          <div style="padding:8px 11px;">What was bought</div>
          <div style="padding:8px 11px;text-align:end;">Amount</div>
          <div></div>
        </div>
        ${surrender.lines.map((l, i) => `
          <div class="adv-modal-line">
            <div><select data-l="${i}" data-k="code">${data.form.codes.map((c) => `<option value="${UI.esc(c.code)}" ${c.code === l.code ? 'selected' : ''}>${UI.esc(c.label)}</option>`).join('')}</select></div>
            <div><input data-l="${i}" data-k="desc" value="${UI.esc(l.desc)}" placeholder="e.g. Fuel, Nakuru to Naivasha, three vehicles"></div>
            <div><input data-l="${i}" data-k="amount" class="num" value="${UI.esc(l.amount)}" inputmode="numeric" placeholder="0"></div>
            <div>${surrender.lines.length > 1 ? `<button type="button" data-remove="${i}">×</button>` : ''}</div>
          </div>`).join('')}
        <div class="adv-modal-foot">
          <button type="button" class="btn" data-add style="padding:5px 11px;font-size:11.5px;">+ Add receipt line</button>
          <div class="adv-modal-sums">
            <span>Advance to account for <b>${UI.esc(detail.surrenderText)}</b></span>
            <span>Receipts <b>${UI.fmtMoney(total)}</b></span>
            <span class="adv-diff ${diff === 0 ? 'ok' : diff > 0 ? 'under' : 'over'}">${
              diff === 0 ? 'Exactly accounted' : diff > 0 ? UI.fmtMoney(diff) + ' unspent' : UI.fmtMoney(-diff) + ' overspent'
            }</span>
          </div>
        </div>
      </div>

      ${diff > 0 ? `
        <div>
          <div class="adv-section-label">What happens to the unspent balance</div>
          <div class="adv-choice">
            <button type="button" data-mode="refund" class="${surrender.mode === 'refund' ? 'on' : ''}">Cash returned and banked</button>
            <button type="button" data-mode="outstanding" class="${surrender.mode === 'outstanding' ? 'on' : ''}">Balance stays outstanding</button>
          </div>
        </div>` : ''}

      <div id="adv-receipt-docs"></div>

      <div class="adv-note">${
        diff > 0 && surrender.mode === 'outstanding'
          ? 'The receipted amount comes off 1220 and the rest stays owed by the holder, still ageing.'
          : diff > 0
            ? 'The receipted amount goes to the programme lines and the unspent cash is banked, clearing the advance.'
            : diff < 0
              ? 'The holder spent more than they held. The overspend is owed back to them and joins the payables.'
              : 'The receipts account for the advance exactly, and it clears.'
      }</div>`,
      'Post surrender',
      'Posting moves the receipted amount off 1220 and onto the programme expenditure lines. Nothing is written until you post.');

    const el = document.getElementById('adv-surrender');
    surrender.docs = UI.docPicker(el.querySelector('#adv-receipt-docs'), {
      label: 'Receipts', required: data.form.requireReceipts !== false, files: surrender.files,
      hint: 'A scan or photo of each receipt. One file can hold several.',
    });
    el.querySelectorAll('[data-l]').forEach((input) => input.addEventListener('change', () => {
      surrender.lines[+input.dataset.l][input.dataset.k] = input.value;
      if (input.dataset.k === 'amount') drawSurrender();
    }));
    el.querySelector('[data-add]').addEventListener('click', () => {
      surrender.lines.push({ code: data.form.codes[0].code, desc: '', amount: '' });
      drawSurrender();
    });
    el.querySelectorAll('[data-remove]').forEach((b) => b.addEventListener('click', () => {
      surrender.lines.splice(+b.dataset.remove, 1);
      drawSurrender();
    }));
    el.querySelectorAll('[data-mode]').forEach((b) => b.addEventListener('click', () => {
      surrender.mode = b.dataset.mode;
      drawSurrender();
    }));
    el.querySelector('[data-submit]').addEventListener('click', (e) => submitSurrender(e.target));
  }

  async function submitSurrender(button) {
    if (surrender.docs.busy()) return UI.toast('Wait for the receipts to finish uploading.');
    button.disabled = true;
    try {
      const result = await UI.postJSON('/api/advances/' + encodeURIComponent(detail.ref) + '/surrender', {
        mode: surrender.mode,
        receipts: surrender.lines.map((l) => ({ code: l.code, desc: l.desc, amount: amountOf(l.amount) })),
        documents: surrender.docs.ids(),
      });
      closeModal();
      UI.closeDrawer();
      UI.toast(result.message);
      await refresh();
    } catch (err) {
      UI.toast(err.message);
      button.disabled = false;
    }
  }

  // ---- Raising a request ----

  let form = null;

  function openRequest() {
    const award = data.form.awards[0];
    form = {
      holder: '', kind: 'Staff', role: '', purpose: '',
      grant: award.ref, program: award.programmes[0] || '',
      amount: '', dueDate: defaultDue(),
    };
    drawRequest();
  }

  /** Policy allows 14 days from the end of the activity; three weeks is the usual ask. */
  function defaultDue() {
    const d = new Date();
    d.setDate(d.getDate() + 21);
    return d.toISOString().slice(0, 10);
  }

  function drawRequest() {
    const award = data.form.awards.find((a) => a.ref === form.grant) || data.form.awards[0];
    const programmes = award.programmes.length ? award.programmes : data.form.programmes;

    modal('adv-request', 'New advance', 'Request an advance against a programme', `
      <div class="adv-form-grid">
        <label class="as-field"><span>Who holds the money</span>
          <input data-f="holder" value="${UI.esc(form.holder)}" placeholder="Name, or a batch label such as STO batch — Coast"></label>
        <div class="as-field"><span>Holder type</span>
          <div class="adv-choice">
            <button type="button" data-kind="Staff" class="${form.kind === 'Staff' ? 'on' : ''}" style="flex:1;">Staff</button>
            <button type="button" data-kind="Observer" class="${form.kind === 'Observer' ? 'on' : ''}" style="flex:1;">Observer</button>
          </div>
        </div>
      </div>

      <label class="as-field"><span>Role or batch size</span>
        <input data-f="role" value="${UI.esc(form.role)}" placeholder="e.g. Long-term observer, Kilifi — or 24 short-term observers"></label>

      <label class="as-field"><span>What the advance is for</span>
        <textarea data-f="purpose" rows="2" placeholder="The activity the receipts will be checked against">${UI.esc(form.purpose)}</textarea></label>

      <div class="adv-form-pair">
        <label class="as-field"><span>Grant / award</span>
          <select data-f="grant">${data.form.awards.map((a) => `<option value="${UI.esc(a.ref)}" ${a.ref === form.grant ? 'selected' : ''}>${UI.esc(a.label)}</option>`).join('')}</select>
          <span class="muted" style="font-size:11px;">Charged to ${UI.esc(award.fund)} · ${UI.esc(award.funder)}</span></label>
        <label class="as-field"><span>Programme</span>
          <select data-f="program">${programmes.map((p) => `<option ${p === form.program ? 'selected' : ''}>${UI.esc(p)}</option>`).join('')}</select>
          <span class="muted" style="font-size:11px;">${award.restricted ? 'The award fixes which programmes may be charged.' : 'Unrestricted money may go to any programme.'}</span></label>
      </div>

      <div class="adv-form-amount">
        <label class="as-field"><span>Amount requested</span>
          <input data-f="amount" value="${UI.esc(form.amount)}" inputmode="numeric" placeholder="0" style="font-family:'IBM Plex Mono',monospace;"></label>
        <label class="as-field"><span>Surrender due by</span>
          <input data-f="dueDate" type="date" value="${UI.esc(form.dueDate)}"></label>
        <div class="muted" style="font-size:11.5px;line-height:1.55;">Policy allows 14 days from the end of the activity. Anything longer needs the programme director to say why.</div>
      </div>

      <div class="adv-note">The request is a claim on the programme budget, not a posting. Approval records who authorised it, and only
        issuing the funds puts the amount on <strong style="color:#16211E;">1220 staff and observer advances</strong> as a receivable from the holder.</div>`,
      'Raise request');

    const el = document.getElementById('adv-request');
    el.querySelectorAll('[data-f]').forEach((input) => input.addEventListener('change', () => {
      form[input.dataset.f] = input.value;
      // The award fixes which programmes may be charged, so the form is redrawn.
      if (input.dataset.f === 'grant') {
        const next = data.form.awards.find((a) => a.ref === form.grant);
        const list = next.programmes.length ? next.programmes : data.form.programmes;
        if (!list.includes(form.program)) form.program = list[0] || '';
        drawRequest();
      }
    }));
    el.querySelectorAll('[data-kind]').forEach((b) => b.addEventListener('click', () => {
      form.kind = b.dataset.kind;
      drawRequest();
    }));
    el.querySelector('[data-submit]').addEventListener('click', (e) => submitRequest(e.target));
  }

  async function submitRequest(button) {
    button.disabled = true;
    try {
      const result = await UI.postJSON('/api/advances', Object.assign({}, form, { amount: amountOf(form.amount) }));
      closeModal();
      UI.toast(result.message);
      state.filter = 'Awaiting approval';
      state.age = 'All';
      state.q = '';
      state.page = 1;
      await refresh();
      await openDetail(result.ref);
    } catch (err) {
      UI.toast(err.message);
      button.disabled = false;
    }
  }

  // ---- A modal, shared by the surrender and the request ----

  let modalEl = null;

  function modal(id, kicker, title, body, submitLabel, note) {
    closeModal();
    modalEl = document.createElement('div');
    modalEl.className = 'ap-modal';
    modalEl.id = id;
    modalEl.innerHTML = `
      <div class="ap-modal-scrim" data-close></div>
      <div class="ap-modal-box" role="dialog" aria-modal="true" aria-label="${UI.esc(title)}" style="width:780px;">
        <div class="jd-head" style="padding:16px 20px;border-bottom-color:#E4E2DB;">
          <div style="display:flex;flex-direction:column;gap:3px;">
            <div class="jd-caps" style="letter-spacing:.1em;">${kicker}</div>
            <div style="font-size:16px;font-weight:600;letter-spacing:-.01em;">${title}</div>
          </div>
          <button type="button" class="rt-close" data-close aria-label="Close">×</button>
        </div>
        <div class="ap-modal-body">${body}</div>
        <div class="ap-modal-foot">
          <div style="min-width:0;flex:1;font-size:12px;line-height:1.5;color:#8B948F;">${note ? UI.esc(note) : ''}</div>
          <button type="button" class="btn" data-close style="height:34px;padding:0 14px;">Cancel</button>
          <button type="button" class="btn btn-primary" data-submit style="height:34px;padding:0 18px;">${UI.esc(submitLabel)}</button>
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
  if (params.get('advance')) await openDetail(params.get('advance'));
})();
