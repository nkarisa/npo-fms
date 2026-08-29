(async function () {
  const app = document.getElementById('app');
  let state = { section: 'Organisation' };

  async function load() {
    return UI.fetchJSON('/api/settings');
  }

  function render(data) {
    app.innerHTML = '';
    UI.pageHead(app, {
      kicker: 'Insight',
      title: 'Settings',
      blurb: 'Organisation, ledger periods, donor segments, approvals, users and the audit log.',
    });

    const card = document.createElement('div');
    card.className = 'card';
    card.appendChild(UI.tabs(data.sections, state.section, (label) => { state.section = label; renderSection(); }));
    const body = document.createElement('div');
    body.className = 'card-body';
    card.appendChild(body);
    app.appendChild(card);

    function renderSection() {
      Array.from(card.querySelectorAll('.tab')).forEach(t => t.classList.toggle('active', t.dataset.tab === state.section));
      body.innerHTML = '';
      if (state.section === 'Organisation') {
        body.appendChild(UI.table([{ label: 'Entity', key: 'name' }, { label: 'Type', key: 'type' }, { label: 'Currency', key: 'currency' }, { label: 'Status', key: 'status' }], data.entities));
      } else if (state.section === 'Segments') {
        body.appendChild(UI.table([{ label: 'Segment', key: 'name' }, { label: 'Example', key: 'example' }, { label: 'Accounts', num: true, key: 'count' }, { label: 'Required', render: (r) => r.required ? UI.badge('Mandatory', 'calm') : UI.badge('Optional', 'warn') }], data.segments));
      } else if (state.section === 'Approvals') {
        body.appendChild(UI.table([{ label: 'Rule', key: 'label' }, { label: 'Threshold', key: 'threshold', num: true, render: (r) => typeof r.threshold === 'number' ? r.threshold.toLocaleString() : r.threshold }, { label: 'Approver', key: 'approver' }, { label: 'Escalation', key: 'escalation' }], data.approvals));
      } else if (state.section === 'Users') {
        body.appendChild(UI.table([{ label: 'Name', key: 'name' }, { label: 'Email', key: 'email' }, { label: 'Role', key: 'role' }, { label: 'Status', key: 'status' }, { label: 'Last active', key: 'lastActive' }], data.users));
      } else if (state.section === 'Audit log') {
        body.appendChild(UI.table([{ label: 'When', key: 'when' }, { label: 'Who', key: 'who' }, { label: 'What', key: 'what' }, { label: 'Area', key: 'area' }], data.audit));
      } else {
        body.innerHTML = data.toggles.map(t => `
          <div class="detail-row"><span class="k">${UI.esc(t.label)} <span class="muted">— ${UI.esc(t.note)}</span></span><span class="v">${t.on ? 'On' : 'Off'}</span></div>`).join('');
      }
    }

    renderSection();
  }

  render(await load());
})();
