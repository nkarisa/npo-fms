/** Small render helpers shared by every page script. No framework — just fetch + DOM. */
const UI = (() => {
  const fmtMoney = (n) => {
    if (n === null || n === undefined) return '—';
    if (typeof n === 'string') return n;
    if (n === 0) return '—';
    const s = Math.abs(Math.round(n)).toLocaleString('en-US');
    return n < 0 ? `(${s})` : s;
  };

  async function fetchJSON(url) {
    const res = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error(`Request failed: ${res.status}`);
    return res.json();
  }

  function toast(msg) {
    const el = document.getElementById('toast');
    if (!el) return;
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => el.classList.remove('show'), 2400);
  }

  function statGrid(stats) {
    const div = document.createElement('div');
    div.className = 'stat-grid';
    div.innerHTML = stats.map(s => `
      <div class="stat">
        <div class="stat-label">${esc(s.label)}</div>
        <div class="stat-value">${esc(s.value)}</div>
        <div class="stat-note">${esc(s.note || '')}</div>
      </div>`).join('');
    return div;
  }

  function tabs(items, activeLabel, onClick) {
    const div = document.createElement('div');
    div.className = 'tabs';
    div.innerHTML = items.map(t => {
      const label = typeof t === 'string' ? t : t.label;
      const count = typeof t === 'object' && t.count !== undefined ? ` (${t.count})` : '';
      const active = label === activeLabel ? 'active' : '';
      return `<button type="button" class="tab ${active}" data-tab="${esc(label)}">${esc(label)}${count}</button>`;
    }).join('');
    div.querySelectorAll('.tab').forEach(btn => btn.addEventListener('click', () => onClick(btn.dataset.tab)));
    return div;
  }

  function table(columns, rows, onRowClick) {
    const wrap = document.createElement('table');
    wrap.className = 'data';
    const thead = `<thead><tr>${columns.map(c => `<th class="${c.num ? 'num' : ''}">${esc(c.label)}</th>`).join('')}</tr></thead>`;
    const tbody = rows.length
      ? `<tbody>${rows.map((r, i) => `<tr data-i="${i}">${columns.map(c => `<td class="${c.num ? 'num' : ''}">${c.render ? c.render(r) : esc(r[c.key] ?? '')}</td>`).join('')}</tr>`).join('')}</tbody>`
      : '';
    wrap.innerHTML = thead + tbody;
    if (onRowClick) {
      wrap.querySelectorAll('tbody tr').forEach(tr => tr.addEventListener('click', () => onRowClick(rows[+tr.dataset.i])));
    }
    return wrap;
  }

  function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  }

  function badge(text, tone) {
    return `<span class="badge ${tone || 'plain'}">${esc(text)}</span>`;
  }

  function pageHead(container, { kicker, title, blurb, actions }) {
    const div = document.createElement('div');
    div.className = 'page-head';
    div.innerHTML = `
      <div>
        <div class="page-kicker">${esc(kicker || '')}</div>
        <h1 class="page-title">${esc(title)}</h1>
        <p class="page-blurb">${esc(blurb || '')}</p>
      </div>
      <div class="page-actions">${actions || ''}</div>`;
    container.appendChild(div);
  }

  // ---- New journal drawer, shared by the Journals and General ledger pages ----
  const J_TYPE_OPTIONS = ['Standard', 'Accrual', 'Reversing', 'Recurring', 'Adjustment', 'Allocation'];
  const J_PERIOD_OPTIONS = ['Jun 2026', 'Jul 2026', 'Aug 2026', 'Sep 2026'];
  const J_PREPARER_OPTIONS = ['J. Achieng', 'M. Otieno', 'S. Njeri', 'P. Mwangi', 'W. Kamau'];
  const J_PROGRAM_OPTIONS = ['Shared services', 'Election Observation', 'Civic Education', 'Governance Advocacy', 'Youth and Gender Inclusion'];

  let journalDrawer;
  let accountOptions = null;

  async function loadAccountOptions() {
    if (accountOptions) return accountOptions;
    const data = await fetchJSON('/api/coa');
    accountOptions = data.accounts
      .filter(a => a.level === 2 && a.status !== 'Archived')
      .map(a => ({ code: a.code, label: `${a.code} · ${a.name}` }));
    return accountOptions;
  }

  function lineRow(line) {
    const row = document.createElement('div');
    row.className = 'jd-line';
    row.style.cssText = 'display:grid;grid-template-columns:170px 1fr 130px 80px 80px 20px;gap:6px;align-items:center;margin-bottom:6px;';
    const cellStyle = 'width:100%;min-width:0;box-sizing:border-box;';
    const codeOptions = (accountOptions || []).map(o => `<option value="${o.code}" ${o.code === line?.code ? 'selected' : ''}>${esc(o.label)}</option>`).join('');
    row.innerHTML = `
      <select class="jd-code" style="${cellStyle}border:1px solid #DDDAD2;border-radius:6px;padding:6px 7px;font-size:12px;">
        <option value="">Select account…</option>
        ${codeOptions}
      </select>
      <input class="jd-desc" placeholder="Line description" value="${esc(line?.desc || '')}" style="${cellStyle}border:1px solid #DDDAD2;border-radius:6px;padding:6px 7px;font-size:12px;">
      <select class="jd-program" style="${cellStyle}border:1px solid #DDDAD2;border-radius:6px;padding:6px 4px;font-size:11.5px;">${J_PROGRAM_OPTIONS.map(o => `<option ${o === line?.program ? 'selected' : ''}>${o}</option>`).join('')}</select>
      <input class="jd-dr" type="number" min="0" step="1" placeholder="Debit" value="${line?.dr || ''}" style="${cellStyle}border:1px solid #DDDAD2;border-radius:6px;padding:6px 7px;font-size:12px;text-align:right;">
      <input class="jd-cr" type="number" min="0" step="1" placeholder="Credit" value="${line?.cr || ''}" style="${cellStyle}border:1px solid #DDDAD2;border-radius:6px;padding:6px 7px;font-size:12px;text-align:right;">
      <button type="button" class="jd-remove" style="width:100%;border:none;background:transparent;color:#8B948F;cursor:pointer;font-size:13px;">✕</button>`;
    return row;
  }

  function buildJournalDrawer() {
    journalDrawer = document.createElement('div');
    journalDrawer.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(13,27,24,.28);z-index:1000;align-items:flex-start;justify-content:flex-end;';
    journalDrawer.innerHTML = `
      <div style="background:#fff;width:760px;max-width:100%;height:100%;overflow-y:auto;box-shadow:-16px 0 40px rgba(13,27,24,.12);">
        <div style="padding:18px 22px 14px;border-bottom:1px solid #EEEDE8;display:flex;align-items:flex-start;gap:12px;">
          <div>
            <div style="font-size:10px;letter-spacing:0.09em;text-transform:uppercase;color:#8B948F;">New journal</div>
            <div style="font-size:16px;font-weight:600;letter-spacing:-0.015em;">Create a journal entry</div>
          </div>
          <button type="button" id="jd-close" style="margin-left:auto;border:none;background:transparent;color:#8B948F;font-size:16px;cursor:pointer;">✕</button>
        </div>
        <form id="jd-form" style="padding:18px 22px 24px;display:flex;flex-direction:column;gap:14px;">
          <div id="jd-error" style="color:#A5442F;font-size:12px;display:none;"></div>

          <label style="display:flex;flex-direction:column;gap:5px;font-size:11px;color:#6E7873;">Narration
            <input name="narration" required placeholder="What is this entry for?" style="border:1px solid #DDDAD2;border-radius:6px;padding:7px 9px;font-size:12.5px;margin-top:4px;">
          </label>

          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
            <label style="display:flex;flex-direction:column;gap:5px;font-size:11px;color:#6E7873;">Date
              <input name="date" type="date" value="${new Date().toISOString().slice(0, 10)}" style="border:1px solid #DDDAD2;border-radius:6px;padding:7px 9px;font-size:12.5px;margin-top:4px;">
            </label>
            <label style="display:flex;flex-direction:column;gap:5px;font-size:11px;color:#6E7873;">Type
              <select name="type" style="border:1px solid #DDDAD2;border-radius:6px;padding:7px 9px;font-size:12.5px;margin-top:4px;">${J_TYPE_OPTIONS.map(t => `<option>${t}</option>`).join('')}</select>
            </label>
            <label style="display:flex;flex-direction:column;gap:5px;font-size:11px;color:#6E7873;">Period
              <select name="period" style="border:1px solid #DDDAD2;border-radius:6px;padding:7px 9px;font-size:12.5px;margin-top:4px;">${J_PERIOD_OPTIONS.map(p => `<option ${p === 'Aug 2026' ? 'selected' : ''}>${p}</option>`).join('')}</select>
            </label>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <label style="display:flex;flex-direction:column;gap:5px;font-size:11px;color:#6E7873;">Preparer
              <select name="preparer" style="border:1px solid #DDDAD2;border-radius:6px;padding:7px 9px;font-size:12.5px;margin-top:4px;">${J_PREPARER_OPTIONS.map(p => `<option>${p}</option>`).join('')}</select>
            </label>
            <label style="display:flex;flex-direction:column;gap:5px;font-size:11px;color:#6E7873;">Supporting document
              <input name="doc" placeholder="e.g. ELOG/JV/0312" style="border:1px solid #DDDAD2;border-radius:6px;padding:7px 9px;font-size:12.5px;margin-top:4px;">
            </label>
          </div>
          <label style="display:flex;flex-direction:column;gap:5px;font-size:11px;color:#6E7873;">Memo
            <textarea name="memo" rows="2" style="border:1px solid #DDDAD2;border-radius:6px;padding:8px 9px;font-size:12.5px;resize:vertical;margin-top:4px;"></textarea>
          </label>

          <div style="height:1px;background:#EEEDE8;"></div>
          <div style="display:flex;align-items:center;gap:8px;">
            <div style="font-size:10px;letter-spacing:0.09em;text-transform:uppercase;color:#8B948F;">Lines</div>
            <button type="button" id="jd-add-line" class="btn" style="margin-left:auto;padding:4px 10px;">Add line</button>
          </div>
          <div style="display:grid;grid-template-columns:170px 1fr 130px 80px 80px 20px;gap:6px;font-size:10px;letter-spacing:0.05em;text-transform:uppercase;color:#A3ABA7;">
            <span>Account</span><span>Description</span><span>Programme</span><span>Debit</span><span>Credit</span><span></span>
          </div>
          <div id="jd-lines"></div>
          <div id="jd-balance" style="font-size:12px;font-weight:600;text-align:right;"></div>

          <div style="display:flex;gap:8px;margin-top:8px;">
            <button type="button" id="jd-save-draft" class="btn">Save draft</button>
            <button type="button" id="jd-submit" class="btn btn-primary">Submit for approval</button>
            <button type="button" id="jd-cancel" class="btn" style="margin-left:auto;">Cancel</button>
          </div>
        </form>
      </div>`;
    document.body.appendChild(journalDrawer);

    const linesBox = journalDrawer.querySelector('#jd-lines');
    linesBox.addEventListener('input', updateBalance);
    linesBox.addEventListener('click', (e) => {
      if (e.target.classList.contains('jd-remove') && linesBox.children.length > 2) {
        e.target.closest('.jd-line').remove();
        updateBalance();
      }
    });
    journalDrawer.querySelector('#jd-add-line').addEventListener('click', () => {
      linesBox.appendChild(lineRow());
      updateBalance();
    });
    journalDrawer.querySelector('#jd-close').addEventListener('click', closeJournalDrawer);
    journalDrawer.querySelector('#jd-cancel').addEventListener('click', closeJournalDrawer);
    journalDrawer.addEventListener('click', (e) => { if (e.target === journalDrawer) closeJournalDrawer(); });

    function updateBalance() {
      const dr = [...linesBox.querySelectorAll('.jd-dr')].reduce((a, el) => a + (parseFloat(el.value) || 0), 0);
      const cr = [...linesBox.querySelectorAll('.jd-cr')].reduce((a, el) => a + (parseFloat(el.value) || 0), 0);
      const bal = journalDrawer.querySelector('#jd-balance');
      if (dr === cr && dr > 0) {
        bal.style.color = '#2C6B58';
        bal.textContent = `Balanced · ${fmtMoney(dr)} each side`;
      } else {
        bal.style.color = '#A5442F';
        bal.textContent = `Out of balance by ${fmtMoney(Math.abs(dr - cr))} · debits ${fmtMoney(dr)}, credits ${fmtMoney(cr)}`;
      }
    }
    journalDrawer._updateBalance = updateBalance;
  }

  function closeJournalDrawer() {
    journalDrawer.style.display = 'none';
  }

  function collectLines() {
    return [...journalDrawer.querySelectorAll('.jd-line')].map(row => ({
      code: row.querySelector('.jd-code').value.trim(),
      desc: row.querySelector('.jd-desc').value.trim(),
      program: row.querySelector('.jd-program').value,
      dr: parseFloat(row.querySelector('.jd-dr').value) || 0,
      cr: parseFloat(row.querySelector('.jd-cr').value) || 0,
    }));
  }

  async function openNewJournalDrawer(opts) {
    opts = opts || {};
    await loadAccountOptions();
    if (!journalDrawer) buildJournalDrawer();

    const form = journalDrawer.querySelector('#jd-form');
    form.reset();
    form.querySelector('[name=date]').value = new Date().toISOString().slice(0, 10);
    journalDrawer.querySelector('#jd-error').style.display = 'none';

    const linesBox = journalDrawer.querySelector('#jd-lines');
    linesBox.innerHTML = '';
    const first = opts.defaultLine || {};
    linesBox.appendChild(lineRow({ code: first.code, fund: first.fund, program: first.program }));
    linesBox.appendChild(lineRow());
    journalDrawer._updateBalance();

    const errorBox = journalDrawer.querySelector('#jd-error');

    async function submit(status) {
      errorBox.style.display = 'none';
      const fd = new FormData(form);
      const dateVal = fd.get('date');
      const formatted = dateVal ? new Date(dateVal + 'T00:00:00').toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }).replace(/ /g, ' ') : '';
      const payload = {
        narration: fd.get('narration'),
        date: formatted,
        type: fd.get('type'),
        period: fd.get('period'),
        preparer: fd.get('preparer'),
        doc: fd.get('doc'),
        memo: fd.get('memo'),
        status,
        lines: collectLines(),
      };
      try {
        const res = await fetch('/api/journals', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify(payload),
        });
        const body = await res.json();
        if (!res.ok) {
          errorBox.textContent = body.error || 'Could not save the journal.';
          errorBox.style.display = 'block';
          return;
        }
        closeJournalDrawer();
        toast(`${body.journal.ref} · ${status === 'Draft' ? 'draft saved' : 'submitted for approval'}.`);
        if (opts.onSaved) opts.onSaved(body.journal);
      } catch (err) {
        errorBox.textContent = 'Could not reach the server.';
        errorBox.style.display = 'block';
      }
    }

    journalDrawer.querySelector('#jd-save-draft').onclick = () => submit('Draft');
    journalDrawer.querySelector('#jd-submit').onclick = () => submit('Pending approval');

    journalDrawer.style.display = 'flex';
  }

  return { fmtMoney, fetchJSON, toast, statGrid, tabs, table, esc, badge, pageHead, openNewJournalDrawer };
})();
