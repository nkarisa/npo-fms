/**
 * The sign-in screens. The server says where the sign-in is (`stage` on every
 * /api/auth response) and this draws that step:
 *
 *   signedOut → email and password (or: forgot password)
 *   mfa       → the code from the authenticator app or email, or a recovery code
 *   enrol     → choose and set up a second step, then keep the recovery codes
 *   done      → on to the page that was asked for
 *
 * An invitation or reset link (/accept-invite, /reset-password) starts by choosing
 * a password, then carries on from whatever stage that leads to.
 */
(function () {
  const root = document.getElementById('auth');
  const esc = UI.esc;
  const mode = root.dataset.mode;
  const next = root.dataset.next || '/';
  const token = new URLSearchParams(location.search).get('token') || '';
  let state = null;

  async function api(method, url, body) {
    const res = await fetch(url, {
      method,
      headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
      body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({}));
    if (data.stage) state = data;
    if (!res.ok) throw Object.assign(new Error(data.error || `Something went wrong (${res.status}).`), { data });
    return data;
  }

  function paint(html) {
    root.innerHTML = html;
    const first = root.querySelector('input:not([type=checkbox]):not([readonly])');
    if (first) first.focus();
  }

  const note = (text, tone) => (text ? `<p class="auth-msg ${tone || ''}" role="${tone === 'error' ? 'alert' : 'status'}">${esc(text)}</p>` : '');

  function showError(err) {
    const el = root.querySelector('.auth-msg-slot');
    if (el) el.innerHTML = note(err.message, 'error');
  }

  /** Disables a form's buttons while a request is out. */
  async function busy(form, run) {
    const before = state?.stage;
    const buttons = form.querySelectorAll('button');
    buttons.forEach((b) => { b.disabled = true; });
    try {
      await run();
    } catch (err) {
      // A refusal can also move the sign-in (too many wrong codes starts it again):
      // draw the step it is now at, with the reason.
      if (state?.stage !== before) route(err.message); else showError(err);
    } finally {
      buttons.forEach((b) => { b.disabled = false; });
    }
  }

  function route(message) {
    const stage = state ? state.stage : 'signedOut';
    if (stage === 'done') { location.replace(next); return; }
    if (stage === 'mfa') return challenge(message);
    if (stage === 'enrol') return chooseMethod(message);
    if (stage === 'renew') return renewForm(message);
    return signInForm(message);
  }

  // ---- Password ----

  function signInForm(message) {
    paint(`
      <h1 class="auth-title">Sign in</h1>
      <form class="auth-form" id="f-login" novalidate>
        <label class="auth-field"><span>Email</span><input name="email" type="email" autocomplete="username" required maxlength="190"></label>
        <label class="auth-field"><span>Password</span><input name="password" type="password" autocomplete="current-password" required></label>
        <div class="auth-msg-slot">${note(message)}</div>
        <button type="submit" class="btn btn-primary auth-submit">Sign in</button>
      </form>
      <button type="button" class="auth-link" id="to-forgot">Forgot your password?</button>`);
    const form = root.querySelector('#f-login');
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      busy(form, async () => {
        const res = await api('POST', '/api/auth/login', { email: form.email.value, password: form.password.value });
        route(res.message);
      });
    });
    root.querySelector('#to-forgot').addEventListener('click', () => forgotForm(form.email.value));
  }

  function forgotForm(email) {
    paint(`
      <h1 class="auth-title">Reset your password</h1>
      <p class="auth-note">We will email a link to choose a new password. Your second sign-in step stays as it is.</p>
      <form class="auth-form" id="f-forgot" novalidate>
        <label class="auth-field"><span>Email</span><input name="email" type="email" autocomplete="username" required value="${esc(email)}"></label>
        <div class="auth-msg-slot"></div>
        <button type="submit" class="btn btn-primary auth-submit">Email me a link</button>
      </form>
      <button type="button" class="auth-link" id="to-login">Back to sign in</button>`);
    const form = root.querySelector('#f-forgot');
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      busy(form, async () => {
        const res = await api('POST', '/api/auth/forgot', { email: form.email.value });
        root.querySelector('.auth-msg-slot').innerHTML = note(res.message, 'ok');
      });
    });
    root.querySelector('#to-login').addEventListener('click', () => signInForm());
  }

  // ---- Second step at sign-in ----

  function challenge(message) {
    const u = state.user;
    const byEmail = u.method === 'email';
    paint(`
      <h1 class="auth-title">Second step</h1>
      <p class="auth-note">${byEmail
        ? `Enter the six-digit code we emailed to <strong>${esc(u.email)}</strong>.`
        : 'Enter the six-digit code your authenticator app shows for this account.'}</p>
      <form class="auth-form" id="f-code" novalidate>
        <label class="auth-field"><span>Code</span><input name="code" class="auth-code" inputmode="numeric" autocomplete="one-time-code" maxlength="11" required placeholder="123 456"></label>
        <div class="auth-msg-slot">${note(message)}</div>
        <button type="submit" class="btn btn-primary auth-submit">Continue</button>
      </form>
      <div class="auth-links">
        ${byEmail ? '<button type="button" class="auth-link" id="resend">Send a new code</button>' : ''}
        <button type="button" class="auth-link" id="use-recovery">Lost your ${byEmail ? 'email access' : 'phone'}? Use a recovery code</button>
        <button type="button" class="auth-link" id="restart">Sign in as someone else</button>
      </div>`);
    const form = root.querySelector('#f-code');
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      busy(form, async () => { await api('POST', '/api/auth/verify', { code: form.code.value }); route(); });
    });
    root.querySelector('#use-recovery').addEventListener('click', () => {
      const input = form.code;
      input.removeAttribute('inputmode');
      input.maxLength = 11;
      input.placeholder = 'abcde-fghjk';
      input.value = '';
      root.querySelector('.auth-field > span').textContent = 'Recovery code';
      root.querySelector('.auth-note').textContent = `Enter one of the recovery codes you saved when you set up your second step. ${u.recoveryLeft} left; each works once.`;
      input.focus();
    });
    root.querySelector('#resend')?.addEventListener('click', () => busy(form, async () => {
      const res = await api('POST', '/api/auth/code');
      root.querySelector('.auth-msg-slot').innerHTML = note(res.message, 'ok');
    }));
    root.querySelector('#restart').addEventListener('click', restart);
  }

  async function restart() {
    try { await api('POST', '/api/auth/logout'); } catch (e) { state = { stage: 'signedOut' }; }
    signInForm();
  }

  // ---- Setting up a second step ----

  function chooseMethod(message) {
    const u = state.user;
    paint(`
      <h1 class="auth-title">Protect your account</h1>
      <p class="auth-note">${u.required ? 'Your account needs a second sign-in step' : 'Add a second sign-in step'} — something you have as well as your password — so a stolen password is not enough to get in. Choose one:</p>
      <div class="mfa-choices">
        ${u.methods.map((m) => `
          <button type="button" class="mfa-choice" data-method="${esc(m.key)}" ${m.available ? '' : 'disabled'}>
            <span class="mfa-choice-title">${esc(m.label)}${m.key === 'totp' ? ' <em>recommended</em>' : ''}</span>
            <span class="mfa-choice-sub">${esc(m.key === 'totp'
              ? (m.available ? 'Google Authenticator, Microsoft Authenticator, Okta Verify or any app that shows six-digit codes. Works without signal.' : 'Not available until the application has an encryption key.')
              : `A code sent to ${u.email} each time you sign in.`)}</span>
          </button>`).join('')}
      </div>
      <div class="auth-msg-slot">${note(message)}</div>
      <button type="button" class="auth-link" id="restart">Sign in as someone else</button>`);
    root.querySelectorAll('.mfa-choice').forEach((b) => b.addEventListener('click', async () => {
      root.querySelectorAll('.mfa-choice').forEach((x) => { x.disabled = true; });
      try {
        const res = await api('POST', '/api/auth/enrol/start', { method: b.dataset.method });
        setUp(b.dataset.method, res);
      } catch (err) {
        showError(err);
        root.querySelectorAll('.mfa-choice').forEach((x) => { x.disabled = false; });
      }
    }));
    root.querySelector('#restart').addEventListener('click', restart);
  }

  function setUp(method, res) {
    paint(`
      <h1 class="auth-title">${method === 'totp' ? 'Set up your authenticator app' : 'Confirm your email'}</h1>
      ${method === 'totp' ? MFA.totpPanel(res.totp) : `<p class="auth-note">${esc(res.message)} Enter it below to confirm this is where your codes should go.</p>`}
      <form class="auth-form" id="f-enrol" novalidate>
        <label class="auth-field"><span>Code</span><input name="code" class="auth-code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required placeholder="123 456"></label>
        <div class="auth-msg-slot"></div>
        <button type="submit" class="btn btn-primary auth-submit">Confirm</button>
      </form>
      <div class="auth-links">
        ${method === 'email' ? '<button type="button" class="auth-link" id="resend">Send a new code</button>' : ''}
        <button type="button" class="auth-link" id="back">Choose a different way</button>
      </div>`);
    const form = root.querySelector('#f-enrol');
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      busy(form, async () => {
        const done = await api('POST', '/api/auth/enrol/confirm', { code: form.code.value });
        keepCodes(done.recoveryCodes);
      });
    });
    root.querySelector('#resend')?.addEventListener('click', () => busy(form, async () => {
      const r = await api('POST', '/api/auth/code');
      root.querySelector('.auth-msg-slot').innerHTML = note(r.message, 'ok');
    }));
    root.querySelector('#back').addEventListener('click', () => chooseMethod());
  }

  function keepCodes(codes) {
    paint(`
      <h1 class="auth-title">Save your recovery codes</h1>
      ${MFA.recoveryPanel(codes)}
      <button type="button" class="btn btn-primary auth-submit" id="continue" disabled>Continue</button>`);
    MFA.wireRecovery(root, codes, (saved) => { root.querySelector('#continue').disabled = !saved; });
    // An expired password is replaced next; otherwise, into the application.
    root.querySelector('#continue').addEventListener('click', () => route());
  }

  // ---- An expired password ----

  function renewForm(message) {
    const u = state.user || {};
    const email = u.account || '';
    paint(`
      <h1 class="auth-title">Choose a new password</h1>
      <p class="auth-note">${esc(message || 'Your password has expired. Choose a new one to carry on.')} Passwords here last ${esc(state.passwordPolicy?.maxAgeDays || '')} days.</p>
      <form class="auth-form" id="f-renew" novalidate>
        <input type="email" autocomplete="username" value="${esc(email)}" hidden readonly>
        <label class="auth-field"><span>New password</span><input name="password" type="password" autocomplete="new-password" required></label>
        ${UI.passwordRules(state.passwordRules)}
        <label class="auth-field"><span>Type it again</span><input name="again" type="password" autocomplete="new-password" required></label>
        <div class="auth-msg-slot"></div>
        <button type="submit" class="btn btn-primary auth-submit">Save and carry on</button>
      </form>
      <div class="auth-links"><button type="button" class="auth-link" id="out">Sign out</button></div>`);
    const form = root.querySelector('#f-renew');
    form.password.addEventListener('input', () => UI.tickPasswordRules(form.querySelector('.pw-rules'), state.passwordRules, form.password.value, { email, name: u.name }));
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      if (form.password.value !== form.again.value) { showError(new Error('The two passwords are not the same.')); return; }
      busy(form, async () => {
        const chosen = form.password.value;
        await api('POST', '/api/auth/renew', { password: chosen });
        // So the browser fills the new password at the next sign-in, not the one it replaced.
        await UI.rememberPassword(email, chosen, u.name);
        route();
      });
    });
    root.querySelector('#out').addEventListener('click', async () => {
      try { await api('POST', '/api/auth/logout'); } catch (err) { state = { stage: 'signedOut' }; }
      route();
    });
  }

  // ---- Invitation and reset links ----

  async function linkForm() {
    const purpose = mode === 'invite' ? 'invite' : 'reset';
    let res;
    try {
      res = await api('GET', `/api/auth/${purpose}?token=${encodeURIComponent(token)}`);
    } catch (err) {
      paint(`<h1 class="auth-title">${purpose === 'invite' ? 'Invitation' : 'Reset link'}</h1>${note(err.message, 'error')}<a class="auth-link" href="/login">Go to sign in</a>`);
      return;
    }
    // No sign-in has started yet, so the policy comes with the link rather than in `state`.
    const { link, passwordRules: rules } = res;
    const min = res.minLength || 12;
    paint(`
      <h1 class="auth-title">${purpose === 'invite' ? `Welcome, ${esc(link.name.split(' ')[0])}` : 'Choose a new password'}</h1>
      <p class="auth-note">${purpose === 'invite' ? 'Choose a password for ' : 'For '}<strong>${esc(link.email)}</strong>. A few unrelated words make a password that is long and easy to remember.</p>
      <form class="auth-form" id="f-link" novalidate>
        <input type="email" autocomplete="username" value="${esc(link.email)}" hidden readonly>
        <label class="auth-field"><span>New password</span><input name="password" type="password" autocomplete="new-password" required minlength="${min}"></label>
        ${UI.passwordRules(rules)}
        <label class="auth-field"><span>Type it again</span><input name="again" type="password" autocomplete="new-password" required></label>
        <div class="auth-msg-slot"></div>
        <button type="submit" class="btn btn-primary auth-submit">${purpose === 'invite' ? 'Set up my account' : 'Save the new password'}</button>
      </form>`);
    const form = root.querySelector('#f-link');
    form.password.addEventListener('input', () => UI.tickPasswordRules(form.querySelector('.pw-rules'), rules, form.password.value, link));
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      if (form.password.value !== form.again.value) { showError(new Error('The two passwords are not the same.')); return; }
      busy(form, async () => {
        const res = await api('POST', `/api/auth/${purpose}`, { token, password: form.password.value });
        // So the browser fills the new password at the next sign-in, not the one it replaced.
        await UI.rememberPassword(link.email, form.password.value, link.name);
        // The link is used up; keep it out of the address bar and history.
        history.replaceState(null, '', '/login');
        route(res.message);
      });
    });
  }

  // ---- Start ----

  (async () => {
    if (mode === 'invite' || mode === 'reset') { linkForm(); return; }
    try { await api('GET', '/api/auth'); } catch (e) { state = { stage: 'signedOut' }; }
    route();
  })();
})();
