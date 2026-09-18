(async function () {
  const app = document.getElementById('app');
  let state = { location: 'All locations', filter: 'All', q: '' };

  async function load() {
    const p = new URLSearchParams(state);
    return UI.fetchJSON('/api/asset-verification?' + p.toString());
  }

  function render(data) {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Accounting',
      title: 'Asset verification',
      blurb: 'Physical count against the register. Exceptions carry a book value that must be resolved before the period closes.',
    });
    app.appendChild(UI.statGrid(data.stats));

    if (data.warning) {
      const warn = document.createElement('div');
      warn.className = 'card';
      warn.style.cssText = 'background:#FBF1E1;border-color:#EEE2CB;';
      warn.innerHTML = `<div style="padding:12px 16px;color:#8A5B2E;font-size:12px;line-height:1.55;">${UI.esc(data.warning)}</div>`;
      app.appendChild(warn);
    }

    const card = document.createElement('div');
    card.className = 'card';
    card.innerHTML = `<div class="card-head"><span class="card-title">Round ${UI.esc(data.round)}</span>`
      + `<span class="muted" style="font-size:11px;">${data.progress.checked} of ${data.progress.total} counted</span></div>`;

    const progress = document.createElement('div');
    progress.style.cssText = 'padding:0 16px 12px;';
    progress.innerHTML = UI.bar(data.progress.pct, 'calm');
    card.appendChild(progress);

    const toolbar = document.createElement('div');
    toolbar.className = 'toolbar';
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Search tag, description or custodian…';
    search.value = state.q;
    search.addEventListener('input', (e) => { state.q = e.target.value; refresh(); });
    const loc = document.createElement('select');
    loc.innerHTML = data.locationOptions.map(l => `<option ${l === state.location ? 'selected' : ''}>${UI.esc(l)}</option>`).join('');
    loc.addEventListener('change', (e) => { state.location = e.target.value; refresh(); });
    toolbar.append(search, loc);
    card.appendChild(toolbar);

    card.appendChild(UI.tabs(data.tabs, state.filter, (label) => { state.filter = label; refresh(); }));

    if (data.rows.length) {
      card.appendChild(UI.table([
        { label: 'Tag', key: 'tag' },
        { label: 'Asset', key: 'desc' },
        { label: 'Class', key: 'cls' },
        { label: 'Location', key: 'location' },
        { label: 'Custodian', key: 'custodian' },
        {
          label: 'Result',
          render: (r) => UI.badge(r.result, r.result === 'Sighted' ? 'calm'
            : r.result === 'Not found' ? 'urgent'
              : r.result === 'Condition issue' ? 'warn' : 'plain')
            + (r.note ? `<div class="muted" style="font-size:11px;">${UI.esc(r.note)}</div>` : ''),
        },
        { label: 'NBV', num: true, key: 'nbv' },
      ], data.rows, (r) => openCount(r, data.resultOptions)));
    } else {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = 'No assets match your filters.';
      card.appendChild(empty);
    }

    const footer = document.createElement('div');
    footer.className = 'card-footer';
    footer.textContent = data.hint;
    card.appendChild(footer);
    app.appendChild(card);

    renderExceptions();
  }

  async function renderExceptions() {
    const data = await UI.fetchJSON('/api/asset-verification/exceptions');
    const card = document.createElement('div');
    card.className = 'card';
    card.innerHTML = `<div class="card-head"><span class="card-title">Exceptions</span>`
      + `<span class="muted" style="font-size:11px;">${data.total} carrying ${UI.esc(data.value)}</span></div>`;

    if (data.rows.length) {
      card.appendChild(UI.table([
        { label: 'Tag', key: 'tag' },
        { label: 'Asset', key: 'desc' },
        { label: 'Location', key: 'location' },
        { label: 'Custodian', key: 'custodian' },
        { label: 'Finding', render: (r) => UI.badge(r.result, r.result === 'Not found' ? 'urgent' : 'warn') },
        { label: 'Accounting action', render: (r) => `<span class="muted" style="font-size:11.5px;">${UI.esc(r.action)}</span>` },
        { label: 'NBV', num: true, key: 'nbv' },
      ], data.rows));
    } else {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = data.hint;
      card.appendChild(empty);
    }

    const footer = document.createElement('div');
    footer.className = 'card-footer';
    footer.textContent = data.hint;
    card.appendChild(footer);
    app.appendChild(card);
  }

  function openCount(asset, results) {
    const facts = [
      ['Class', asset.cls], ['Location', asset.location],
      ['Custodian', asset.custodian], ['Net book value', asset.nbv],
      ['Current result', asset.result],
    ];

    UI.drawer(`${asset.tag} · ${asset.desc}`, `
      <div style="padding:14px 18px;">
        ${facts.map(([k, v]) => `<div class="detail-row"><span class="k">${UI.esc(k)}</span><span class="v">${UI.esc(v)}</span></div>`).join('')}
        ${asset.note ? `<div class="muted" style="font-size:11.5px;margin-top:10px;line-height:1.5;">${UI.esc(asset.note)}</div>` : ''}
      </div>
      <div style="border-top:1px solid #E4E2DB;padding:14px 18px;background:#FBFAF7;">
        <div style="font-weight:600;font-size:12.5px;margin-bottom:9px;">Record the count</div>
        <select id="av-result" style="width:100%;box-sizing:border-box;border:1px solid #DDDAD2;border-radius:6px;padding:7px 8px;font-size:12px;">
          ${results.map(r => `<option ${r === asset.result ? 'selected' : ''}>${UI.esc(r)}</option>`).join('')}
        </select>
        <input id="av-note" placeholder="What was found" value="${UI.esc(asset.note || '')}"
               style="width:100%;box-sizing:border-box;margin-top:8px;border:1px solid #DDDAD2;border-radius:6px;padding:7px 8px;font-size:12px;">
        <div class="muted" style="font-size:11px;margin-top:7px;line-height:1.5;">Anything other than "Sighted" needs a note — a count without a reason is not evidence.</div>
        <button id="av-save" style="margin-top:10px;border:1px solid var(--accent);background:var(--accent);color:#fff;border-radius:6px;padding:7px 14px;font-size:12px;font-weight:600;cursor:pointer;">Save result</button>
      </div>`);

    document.getElementById('av-save').addEventListener('click', async (e) => {
      e.target.disabled = true;
      try {
        // The tag carries slashes; the API expects them as path segments.
        await UI.postJSON('/api/asset-verification/' + asset.tag, {
          result: document.getElementById('av-result').value,
          note: document.getElementById('av-note').value,
        });
        UI.closeDrawer();
        UI.toast('Count recorded');
        refresh();
      } catch (err) {
        UI.toast(err.message);
        e.target.disabled = false;
      }
    });
  }

  async function refresh() {
    render(await load());
  }

  refresh();
})();
