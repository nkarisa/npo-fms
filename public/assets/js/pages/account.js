/**
 * My account: who I am, the roles I hold and where, my password, my second
 * sign-in step and recent sign-in activity. Changes to the second step and new
 * recovery codes ask for the password again (the API checks it).
 */
(async function () {
  const app = document.getElementById('app');
  const esc = UI.esc;
  let data = null;

  async function load() {
    try {
      data = await UI.fetchJSON('/api/account');
    } catch (err) {
      app.innerHTML = `<div class="coa-empty">${esc(err.message)}</div>`;
      return;
    }
    render();
  }

  const methodLabel = (key) => (data.mfa.methods.find((m) => m.key === key) || { label: key }).label;

  function render() {
    const me = data.me;
    const mfa = data.mfa;
    app.innerHTML = `
      <div class="page-head">
        <div>
          <div class="page-kicker">Account</div>
          <h1 class="page-title">My account</h1>
          <p class="page-blurb">How you sign in, and what your roles let you do. Roles are assigned in Settings → Users by whoever manages users.</p>
        </div>
      </div>
      <div class="acct">
        <div>
          <div class="card">
            <div class="card-head"><span class="card-title">You</span></div>
            <div class="card-body">
              <div class="acct-id"><span class="acct-avatar">${esc(me.initials)}</span><span class="st-stack"><strong>${esc(me.name)}</strong><span class="st-sub">${esc(me.email)}</span></span></div>
              <div>
                <div class="acct-row"><span>Last signed in</span><span>${esc(me.lastSignIn)}</span></div>
                <div class="acct-row"><span>What you can do</span><span>${esc(me.rights)}</span></div>
                <div class="acct-row"><span>Approval limit</span><span>${esc(me.limit)}</span></div>
              </div>
            </div>
          </div>
          <div class="card">
            <div class="card-head"><span class="card-title">Roles</span><span class="card-hint">${data.access.length === 1 ? '1 role' : data.access.length + ' roles'}</span></div>
            <div class="card-body">
              ${data.access.map((a) => `<div class="acct-row"><span>${esc(a.role)}</span><span>${esc(a.entities)}</span></div>`).join('')}
              <div class="st-kicker">Permissions these give you</div>
              <div class="acct-perms">${data.permissions.map((p) => `<span class="acct-perm">${esc(p)}</span>`).join('') || '<span class="st-muted">None</span>'}</div>
            </div>
          </div>
        </div>
        <div>
          <div class="card">
            <div class="card-head"><span class="card-title">Second sign-in step</span></div>
            <div class="card-body" id="mfa-body">
              ${mfa.method
                ? `<span class="acct-state on">● On — ${esc(methodLabel(mfa.method))}</span>
                   <p class="auth-note">${mfa.recoveryLeft} of your recovery codes ${mfa.recoveryLeft === 1 ? 'is' : 'are'} unused.${mfa.recoveryLeft <= 3 ? ' Issue new ones before you run out.' : ''}</p>`
                : `<span class="acct-state off">○ Off</span>
                   <p class="auth-note">${mfa.required ? 'Your roles need one; you will be asked to set it up at your next sign-in.' : 'Anyone with your password can sign in as you. Add a second step to stop that.'}</p>`}
              <div class="acct-actions">
                <button type="button" class="btn ${mfa.method ? '' : 'btn-primary'}" data-act="setup">${mfa.method ? 'Switch method' : 'Set up'}</button>
                ${mfa.method ? '<button type="button" class="btn" data-act="codes">New recovery codes</button>' : ''}
                ${mfa.method && !mfa.required ? '<button type="button" class="btn" data-act="remove">Turn off</button>' : ''}
              </div>
            </div>
          </div>
          <div class="card">
            <div class="card-head"><span class="card-title">Password</span></div>
            <div class="card-body">
              <form class="bu" id="pw-form" style="padding:0;" novalidate>
                <input type="email" autocomplete="username" value="${esc(me.email)}" hidden readonly>
                <label class="bu-field"><span>Current password</span><input name="current" type="password" autocomplete="current-password"></label>
                <label class="bu-field"><span>New password</span><input name="password" type="password" autocomplete="new-password"></label>
                ${UI.passwordRules(data.passwordRules)}
                <label class="bu-field"><span>Type it again</span><input name="again" type="password" autocomplete="new-password"></label>
                ${data.passwordExpires ? `<p class="auth-note">Your password expires on ${esc(data.passwordExpires.date)} — ${data.passwordExpires.days === 0 ? 'today' : data.passwordExpires.days === 1 ? 'tomorrow' : 'in ' + data.passwordExpires.days + ' days'}. After that you choose a new one when you sign in.</p>` : ''}
                <div class="bu-actions"><button type="submit" class="btn btn-primary">Change password</button></div>
              </form>
            </div>
          </div>
          <div class="card">
            <div class="card-head"><span class="card-title">Recent sign-in activity</span></div>
            <div class="card-body">
              ${data.activity.map((e) => `<div class="acct-row"><span>${esc(e.when)}</span><span>${esc(e.what)}${e.from ? ` <span class="st-sub">· ${esc(e.from)}</span>` : ''}</span></div>`).join('') || '<span class="st-muted">Nothing yet.</span>'}
              <p class="st-note">Something you don't recognise? Change your password and tell whoever manages users.</p>
            </div>
          </div>
        </div>
      </div>`;

    const pwForm = app.querySelector('#pw-form');
    pwForm.addEventListener('submit', changePassword);
    pwForm.password.addEventListener('input', () => UI.tickPasswordRules(pwForm.querySelector('.pw-rules'), data.passwordRules, pwForm.password.value, me));
    app.querySelectorAll('[data-act]').forEach((b) => b.addEventListener('click', () => ({ setup, codes: newCodes, remove })[b.dataset.act]()));
  }

  async function changePassword(e) {
    e.preventDefault();
    const f = e.target;
    if (f.password.value !== f.again.value) { UI.toast('The two new passwords are not the same.'); return; }
    try {
      const chosen = f.password.value;
      const res = await UI.postJSON('/api/account/password', { current: f.current.value, password: chosen });
      data = res;
      render();
      UI.toast(res.message);
      // So the browser fills the new password at the next sign-in, not the one it replaced.
      await UI.rememberPassword(data.me.email, chosen, data.me.name);
    } catch (err) {
      UI.toast(err.message);
    }
  }

  /** Asks for the password in the drawer, then runs `then(password)`. */
  function withPassword(title, intro, action, then) {
    UI.drawer(title, `
      <form class="bu" id="pw-check" novalidate>
        <p class="bu-intro">${esc(intro)}</p>
        <label class="bu-field"><span>Your password</span><input name="password" type="password" autocomplete="current-password"></label>
        <div class="bu-actions"><button type="button" class="btn" data-close>Cancel</button><button type="submit" class="btn btn-primary">${esc(action)}</button></div>
      </form>`);
    const form = document.getElementById('pw-check');
    form.password.focus();
    form.querySelector('[data-close]').addEventListener('click', UI.closeDrawer);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      form.querySelectorAll('button').forEach((b) => { b.disabled = true; });
      try { await then(form.password.value); } catch (err) { UI.toast(err.message); }
      form.querySelectorAll('button').forEach((b) => { b.disabled = false; });
    });
  }

  function setup() {
    UI.drawer('Second sign-in step', `
      <div class="bu" id="mfa-pick">
        <p class="bu-intro">Choose how you will prove it is you after your password. ${data.mfa.method ? `The new method replaces ${esc(methodLabel(data.mfa.method))} once it is confirmed.` : ''}</p>
        <div class="mfa-choices">${data.mfa.methods.map((m) => `
          <button type="button" class="mfa-choice" data-method="${esc(m.key)}" ${m.available ? '' : 'disabled'}>
            <span class="mfa-choice-title">${esc(m.label)}</span>
            <span class="mfa-choice-sub">${esc(m.key === 'totp' ? (m.available ? 'Google Authenticator, Microsoft Authenticator, Okta Verify or similar.' : 'Needs an application encryption key first.') : 'A code sent to ' + data.me.email + ' each time.')}</span>
          </button>`).join('')}</div>
        <label class="bu-field"><span>Your password</span><input id="mfa-pw" type="password" autocomplete="current-password"></label>
      </div>`);
    const box = document.getElementById('mfa-pick');
    box.querySelectorAll('.mfa-choice').forEach((b) => b.addEventListener('click', async () => {
      try {
        const res = await UI.postJSON('/api/account/mfa/start', { method: b.dataset.method, password: box.querySelector('#mfa-pw').value });
        confirmStep(b.dataset.method, res);
      } catch (err) {
        UI.toast(err.message);
      }
    }));
  }

  function confirmStep(method, res) {
    UI.drawer(method === 'totp' ? 'Set up your authenticator app' : 'Confirm your email', `
      <form class="bu" id="mfa-confirm" novalidate>
        ${method === 'totp' ? MFA.totpPanel(res.totp) : `<p class="bu-intro">${esc(res.message)} Enter it to confirm.</p>`}
        <label class="bu-field"><span>Code</span><input name="code" class="auth-code" inputmode="numeric" autocomplete="one-time-code" maxlength="7"></label>
        <div class="bu-actions"><button type="button" class="btn" data-close>Cancel</button><button type="submit" class="btn btn-primary">Confirm</button></div>
      </form>`);
    const form = document.getElementById('mfa-confirm');
    form.code.focus();
    form.querySelector('[data-close]').addEventListener('click', UI.closeDrawer);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        const out = await UI.postJSON('/api/account/mfa/confirm', { code: form.code.value });
        data = out;
        render();
        showCodes(out.recoveryCodes, out.message);
      } catch (err) {
        UI.toast(err.message);
      }
    });
  }

  function showCodes(codes, message) {
    UI.drawer('Your recovery codes', `<div class="bu" id="mfa-codes"><p class="bu-intro">${esc(message)}</p>${MFA.recoveryPanel(codes)}<div class="bu-actions"><button type="button" class="btn btn-primary" id="codes-done" disabled>Done</button></div></div>`);
    const box = document.getElementById('mfa-codes');
    MFA.wireRecovery(box, codes, (saved) => { box.querySelector('#codes-done').disabled = !saved; });
    box.querySelector('#codes-done').addEventListener('click', UI.closeDrawer);
  }

  function newCodes() {
    withPassword('New recovery codes', 'The codes you have now stop working as soon as the new ones are issued.', 'Issue new codes', async (password) => {
      const res = await UI.postJSON('/api/account/recovery-codes', { password });
      data = res;
      render();
      showCodes(res.recoveryCodes, res.message);
    });
  }

  function remove() {
    withPassword('Turn off the second step', 'Anyone with your password will then be able to sign in as you.', 'Turn it off', async (password) => {
      const res = await UI.postJSON('/api/account/mfa/remove', { password });
      data = res;
      UI.closeDrawer();
      render();
      UI.toast(res.message);
    });
  }

  await load();
})();
