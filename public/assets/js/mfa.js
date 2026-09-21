/**
 * The pieces of setting up a second sign-in step that the sign-in screen and My
 * account share: the authenticator QR code with its typed-in key, and the
 * recovery codes shown once afterwards. Needs vendor/qrcode.js.
 */
const MFA = (() => {
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /** The QR code for an otpauth:// address, as inline SVG. */
  function qr(uri) {
    const code = qrcode(0, 'M');
    code.addData(uri);
    code.make();
    return code.createSvgTag({ cellSize: 4, margin: 2, scalable: true, alt: 'QR code for your authenticator app' });
  }

  /** Scan-or-type instructions for an authenticator app. `totp` is what the API returned. */
  function totpPanel(totp) {
    return `
      <ol class="mfa-steps">
        <li>Open your authenticator app — Google Authenticator, Microsoft Authenticator, Okta Verify, 1Password or similar — and add an account.</li>
        <li>Scan this code with it.
          <div class="mfa-qr">${qr(totp.uri)}</div>
          <details class="mfa-manual"><summary>Can't scan? Type the key instead</summary>
            <div class="mfa-key-row"><span>Account</span><code>${esc(totp.account)}</code></div>
            <div class="mfa-key-row"><span>Key</span><code class="mfa-key">${esc(totp.grouped)}</code></div>
            <div class="mfa-key-row"><span>Type</span><code>Time-based, 6 digits, every 30 seconds</code></div>
          </details>
        </li>
        <li>Type the six-digit code the app now shows for ${esc(totp.issuer)}.</li>
      </ol>`;
  }

  /** The recovery codes, with ways to keep them. Shown once. */
  function recoveryPanel(codes) {
    return `
      <div class="mfa-recovery">
        <p class="auth-note">Keep these somewhere safe, away from your phone — a password manager or a printed copy. Each one gets you in once if you lose your phone or cannot reach your email. <strong>They will not be shown again.</strong></p>
        <ol class="mfa-codes">${codes.map((c) => `<li><code>${esc(c)}</code></li>`).join('')}</ol>
        <div class="mfa-code-actions">
          <button type="button" class="btn" data-mfa="copy">Copy</button>
          <button type="button" class="btn" data-mfa="download">Download as text</button>
          <button type="button" class="btn" data-mfa="print">Print</button>
        </div>
        <label class="mfa-saved"><input type="checkbox" data-mfa="saved"> I have saved these codes</label>
      </div>`;
  }

  /** Wires the copy, download and print buttons; `onSaved(checked)` follows the checkbox. */
  function wireRecovery(root, codes, onSaved) {
    const text = `Recovery codes — ${document.documentElement.dataset.brand || 'Finance'}\nEach code works once.\n\n${codes.join('\n')}\n`;
    root.querySelector('[data-mfa="copy"]').addEventListener('click', async (e) => {
      try { await navigator.clipboard.writeText(text); e.target.textContent = 'Copied'; } catch (err) { e.target.textContent = 'Copy failed — select them instead'; }
    });
    root.querySelector('[data-mfa="download"]').addEventListener('click', () => {
      const url = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
      const a = Object.assign(document.createElement('a'), { href: url, download: 'recovery-codes.txt' });
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
    root.querySelector('[data-mfa="print"]').addEventListener('click', () => {
      const w = window.open('', '_blank', 'width=420,height=560');
      if (!w) return;
      w.document.write(`<pre style="font:14px/1.7 monospace;padding:24px;">${esc(text)}</pre>`);
      w.document.close();
      w.print();
    });
    root.querySelector('[data-mfa="saved"]').addEventListener('change', (e) => onSaved(e.target.checked));
  }

  return { totpPanel, recoveryPanel, wireRecovery };
})();
