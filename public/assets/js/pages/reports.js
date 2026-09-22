/**
 * Reports (v5): the statement of financial position, of activities, of cash flows
 * and the trial balance, for a period against a comparative. Figures, notes and
 * the checks each statement passes come from /api/reports; a line opens its
 * postings in the general ledger for the same months. The choice is kept in the
 * address, so a statement can be linked to.
 */
(async function () {
  const app = document.getElementById('app');
  const esc = UI.esc;
  const params = new URLSearchParams(location.search);
  const state = {
    report: params.get('report') || '',
    period: params.get('period') || '',
    compare: params.get('compare') || 'Prior year',
    split: params.get('split') !== '0',
  };
  let data = null;

  const query = () => new URLSearchParams({ report: state.report, period: state.period, compare: state.compare, split: state.split ? '1' : '0' });

  async function refresh() {
    try {
      data = await UI.fetchJSON('/api/reports?' + query().toString());
    } catch (err) {
      app.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`;
      return;
    }
    Object.assign(state, { report: data.report, period: data.period || '', compare: data.compare || state.compare, split: data.split ?? state.split });
    history.replaceState(null, '', '/reports?' + query().toString());
    render();
  }

  function render() {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Insight',
      title: data.title,
      blurb: data.blurb,
      actions: data.empty ? '' : '<button type="button" class="btn" data-act="print">Print</button><button type="button" class="btn btn-primary" data-act="export">Export</button>',
    });
    app.querySelectorAll('[data-act]').forEach((b) => b.addEventListener('click', () => ({ print, export: exportReport })[b.dataset.act]()));

    app.appendChild(filters());
    if (data.empty) {
      const empty = document.createElement('div');
      empty.className = 'card empty-state';
      empty.textContent = data.empty;
      app.appendChild(empty);
      return;
    }
    app.appendChild(statement());
  }

  // ---- Choosing the statement ----

  function filters() {
    const div = document.createElement('div');
    div.className = 'rp-filters';
    const select = (key, options, current) => `<select data-f="${key}">${options.map((o) => `<option value="${esc(o)}" ${o === current ? 'selected' : ''}>${esc(o)}</option>`).join('')}</select>`;
    div.innerHTML = `
      <div class="rp-seg" role="tablist">
        ${data.tabs.map((t) => `<button type="button" role="tab" class="rp-seg-btn ${t.key === data.report ? 'on' : ''}" aria-selected="${t.key === data.report}" data-tab="${esc(t.key)}">${esc(t.label)}</button>`).join('')}
      </div>
      ${data.empty ? '' : `
        <label class="rp-filter">Period ${select('period', data.periods, data.period)}</label>
        <label class="rp-filter">Comparative ${select('compare', data.compares, data.compare)}</label>
        ${data.showSplit ? `<label class="rp-check"><input type="checkbox" data-f="split" ${data.split ? 'checked' : ''}>Split by restriction class</label>` : ''}
        <div class="rp-hint ${data.balanced ? '' : 'bad'}">${esc(data.hint)}</div>`}`;

    div.querySelectorAll('[data-tab]').forEach((b) => b.addEventListener('click', () => { state.report = b.dataset.tab; refresh(); }));
    div.querySelectorAll('select[data-f]').forEach((s) => s.addEventListener('change', () => { state[s.dataset.f] = s.value; refresh(); }));
    const split = div.querySelector('input[data-f="split"]');
    if (split) split.addEventListener('change', () => { state.split = split.checked; refresh(); });
    return div;
  }

  // ---- The statement ----

  const cellText = (c) => (c.v === null || c.v === undefined ? '' : UI.fmtMoney(c.v));
  const cellClass = (c) => ['rp-num', c.bold ? 'bold' : '', c.semi ? 'semi' : '', c.accent ? 'accent' : '', c.muted ? 'muted' : ''].join(' ').trim();

  function statement() {
    const cols = data.columns.length;
    // Account column and one per figure, as the prototype sets them: wider for a statement, narrower for the trial balance.
    const tb = data.report === 'Trial balance';
    const wide = data.split && data.report === 'Statement of activities';
    const track = `minmax(${tb ? 320 : wide ? 300 : 340}px, 1fr) repeat(${cols}, ${tb || wide ? 150 : 170}px)`;
    const minWidth = wide ? 1080 : tb ? 900 : 860;

    const card = document.createElement('div');
    card.className = 'rp-card';
    card.innerHTML = `
      <div class="rp-scroll">
        <div class="rp-sheet" style="min-width:${minWidth}px; --rp-track:${track};">
          <div class="rp-title">
            <div class="rp-name">${esc(data.heading)}</div>
            <div class="rp-subtitle">${esc(data.sub)}</div>
          </div>
          <div class="rp-row rp-headrow">
            <div class="rp-label">${esc(data.labelColumn)}</div>
            ${data.columns.map((c) => `<div class="rp-num">${esc(c)}</div>`).join('')}
          </div>
          ${data.sections.map(section).join('')}
          <div class="rp-notes">
            <div class="rp-notes-head">Notes</div>
            ${data.notes.map((n) => `<div class="rp-note"><span>·</span>${esc(n)}</div>`).join('')}
          </div>
        </div>
      </div>
      <div class="rp-foot">
        <span>${esc(data.footer)}</span>
        <span class="rp-foot-basis">${esc(data.basis)}</span>
      </div>`;

    card.querySelectorAll('[data-drill]').forEach((r) => r.addEventListener('click', () => {
      const p = new URLSearchParams({ account: r.dataset.drill });
      if (data.drillPeriod) p.set('period', data.drillPeriod);
      location.href = '/gl?' + p.toString();
    }));
    return card;
  }

  function section(s) {
    const head = s.heading === null ? '' : `
      <div class="rp-row rp-section"><div class="rp-label">${esc(s.heading)}</div>${data.columns.map(() => '<div></div>').join('')}</div>`;

    return head + s.rows.map((r) => {
      // A line opens its postings only where the ledger holds them.
      const drill = r.drill && data.source === 'ledger' ? r.drill : '';
      return `
        <div class="rp-row rp-${r.kind} ${drill ? 'rp-drill' : ''}" ${drill ? `data-drill="${esc(drill)}" title="Open the postings on ${esc(drill)}"` : ''}>
          <div class="rp-label ${r.kind === 'line' && s.heading !== null ? 'indent' : ''}">
            ${r.code ? `<span class="rp-code">${esc(r.code)}</span>` : ''}<span class="rp-text">${esc(r.label)}</span>
          </div>
          ${r.cells.map((c) => `<div class="${cellClass(c)}">${esc(cellText(c))}</div>`).join('')}
        </div>`;
    }).join('');
  }

  // ---- Print and export ----

  function print() {
    let sheet = document.getElementById('rp-print');
    if (!sheet) {
      sheet = document.createElement('div');
      sheet.id = 'rp-print';
      document.body.appendChild(sheet);
    }
    const row = (r) => `
      <tr class="${r.kind}">
        <td>${r.code ? `<span class="pp-code">${esc(r.code)}</span>` : ''}${esc(r.label)}</td>
        ${r.cells.map((c) => `<td class="num">${esc(cellText(c))}</td>`).join('')}
      </tr>`;
    sheet.innerHTML = `
      <div class="pp-head">
        <div class="pp-title">${esc(data.heading)}</div>
        <div class="pp-sub">${esc(data.sub)}</div>
      </div>
      <table>
        <thead><tr><th>${esc(data.labelColumn)}</th>${data.columns.map((c) => `<th class="num">${esc(c)}</th>`).join('')}</tr></thead>
        <tbody>
          ${data.sections.map((s) => (s.heading === null ? '' : `<tr class="heading"><td colspan="${data.columns.length + 1}">${esc(s.heading)}</td></tr>`) + s.rows.map(row).join('')).join('')}
        </tbody>
      </table>
      <div class="pp-caps">Notes</div>
      ${data.notes.map((n) => `<p class="pp-small">${esc(n)}</p>`).join('')}
      <p class="pp-small">${esc(data.basis)}</p>`;

    const root = document.documentElement;
    const title = document.title;
    root.classList.add('rp-printing');
    document.title = `${UI.brand()} ${data.report.toLowerCase()} — ${data.period}`;
    const restore = () => { root.classList.remove('rp-printing'); document.title = title; window.removeEventListener('afterprint', restore); };
    window.addEventListener('afterprint', restore);
    setTimeout(() => window.print(), 30);
  }

  async function exportReport() {
    try {
      const res = await fetch('/api/reports/export?' + query().toString());
      if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'The statement could not be exported.');
      UI.download(await res.blob(), `${UI.brand()} ${data.report.toLowerCase()} ${data.period}.csv`, res.headers.get('Content-Disposition'));
      UI.toast(`${data.report} for ${data.period} exported with ${data.compare === 'None' ? 'the current period only' : `the ${data.compare.toLowerCase()} comparative`}.`);
    } catch (err) {
      UI.toast(err.message);
    }
  }

  refresh();
})();
