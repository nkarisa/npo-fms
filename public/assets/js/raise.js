/**
 * Raise a label, from wherever it is read.
 *
 * Cmd-click (or Alt-click) any translatable label in the shell and the wording
 * goes to the reviewer of record for that language. The v5 prototype put this on
 * the sidebar; anything carrying data-i18n is offered it here, so a label does
 * not have to be hunted down in Settings by somebody who has just read it wrong.
 *
 * Nothing on screen moves. A raise is a request — the label a reader sees is
 * unchanged until the reviewer approves it, which is what makes this safe to
 * offer to everyone rather than only to translators.
 */
(function () {
  // Cmd on macOS, Alt everywhere. Not Ctrl: on a Mac that is the right-click.
  const held = (e) => e.metaKey || e.altKey;

  let pop = null;
  let state = null;

  // ---- Opening ----

  document.addEventListener('click', (e) => {
    const mark = e.target.closest('.i18n-mark');
    const host = e.target.closest('[data-i18n]');
    if (!host) return;
    // The EN badge is itself the invitation, so it needs no modifier.
    if (!mark && !held(e)) return;
    e.preventDefault();
    e.stopPropagation();
    open(host.dataset.i18n, e.clientX, e.clientY);
  }, true);

  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });

  async function open(str, x, y) {
    close();
    build(x, y);
    // The one this call is drawing into. A reader who presses Escape, or clicks
    // another label, while the lookup is still in flight closes it, and this
    // answer must then be dropped rather than painted into whatever replaced it.
    const mine = pop;
    const live = () => pop === mine;

    pop.innerHTML = '<div class="rz-load">Loading…</div>';
    try {
      const data = await UI.fetchJSON(`/api/i18n/string?str=${encodeURIComponent(str)}`);
      if (!live()) return;
      if (!data.locales.length) {
        pop.innerHTML = '<div class="rz-load">No language is being translated yet, so there is no reviewer to raise this with.</div>';
        return;
      }
      // Raise against the language being read; from English, against the first
      // translated language, which the reader can change.
      const reading = data.locales.find((l) => l.code === data.current);
      state = {
        ...data,
        target: (reading || data.locales[0]).code,
        mode: data.locked ? 'unlock' : 'suggest',
        // data.reasons is the list a reader may pick from; picked is their choice.
        picked: [],
      };
      render();
    } catch (err) {
      if (live()) pop.innerHTML = `<div class="rz-load">${UI.esc(err.message)}</div>`;
    }
  }

  function build(x, y) {
    const shade = document.createElement('div');
    shade.className = 'rz-shade';
    shade.addEventListener('click', close);
    document.body.appendChild(shade);

    pop = document.createElement('div');
    pop.className = 'rz-pop';
    // Anchored to the click. How tall it ends up depends on what the string turns
    // out to be — a locked term and a language picker both add to it — so the
    // vertical fit is done by measuring, in fit(), once there is something to
    // measure. Getting that wrong puts the buttons off the bottom of the screen.
    const w = 348;
    const left = Math.max(12, Math.min(x + 12, window.innerWidth - w - 12));
    pop.style.cssText = `left:${left}px;top:${y + 10}px;width:${w}px;`;
    // The popover is chrome about the wording, never the wording itself, so it
    // stays left-to-right even while the page it sits on runs right-to-left.
    pop.dir = 'ltr';
    document.body.appendChild(pop);
  }

  function close() {
    document.querySelectorAll('.rz-shade, .rz-pop').forEach((el) => el.remove());
    pop = null;
    state = null;
  }

  // ---- Drawing ----

  function render() {
    const s = state;
    const esc = UI.esc;
    const target = s.locales.find((l) => l.code === s.target);
    const fromSource = s.current === s.source;

    pop.innerHTML = `
      <div class="rz-head">
        <span class="rz-title">Raise this label</span>
        <button type="button" class="rz-x" title="Close">&times;</button>
      </div>
      <div class="rz-body">
        <div class="rz-pair"><span class="rz-key">Source</span><span class="rz-src">${esc(s.str)}</span></div>
        <div class="rz-pair">
          <span class="rz-key">In ${esc(target.native)}</span>
          <span class="rz-now">${esc(target.text)}${target.translated ? '' : '<span class="i18n-mark rz-en">EN</span>'}</span>
        </div>

        ${s.locked ? `
          <div class="rz-locked">
            <div class="rz-locked-head">⌷ Locked term — wording cannot be suggested</div>
            <div>${esc(s.locked.note)}</div>
            <div>Unlock authority · <strong>${esc(s.locked.unlock)}</strong></div>
          </div>` : ''}

        ${fromSource && s.locales.length > 1 ? `
          <div class="rz-field">
            <span class="rz-label">Language to raise it in</span>
            <div class="rz-chips">${s.locales.map((l) => `
              <button type="button" class="rz-chip ${l.code === s.target ? 'on' : ''}" data-lang="${esc(l.code)}">${esc(l.native)}</button>`).join('')}</div>
          </div>` : ''}

        ${s.locked ? '' : `
          <div class="rz-seg">
            <button type="button" class="rz-seg-btn ${s.mode === 'suggest' ? 'on' : ''}" data-mode="suggest">Suggest wording</button>
            <button type="button" class="rz-seg-btn ${s.mode === 'report' ? 'on' : ''}" data-mode="report">Report a problem</button>
          </div>`}

        ${s.mode === 'report' ? `
          <div class="rz-field">
            <span class="rz-label">What is wrong with it</span>
            <div class="rz-chips">${reasonChips()}</div>
          </div>` : ''}

        <label class="rz-field">
          <span class="rz-label">${s.locked ? 'Why it should change' : s.mode === 'suggest' ? `The wording your team uses in ${esc(target.native)}` : 'Anything else the reviewer should know (optional)'}</span>
          <textarea class="rz-text" rows="2" placeholder="${s.mode === 'suggest' && !s.locked ? esc(target.text) : ''}"></textarea>
        </label>

        <label class="rz-field">
          <span class="rz-label">Raised on behalf of <em>optional</em></span>
          <input class="rz-who" maxlength="120">
        </label>

        <div class="rz-note">${esc(noteFor(target))}</div>
      </div>
      <div class="rz-foot">
        <button type="button" class="btn rz-cancel">Cancel</button>
        <button type="button" class="btn btn-primary rz-send">${s.locked ? 'Send request' : 'Raise with reviewer'}</button>
      </div>`;

    wire();
    fit();
  }

  /** Lift the popover back inside the viewport when the render has made it tall. */
  function fit() {
    const box = pop.getBoundingClientRect();
    const spill = box.bottom - (window.innerHeight - 12);
    if (spill > 0) {
      pop.style.top = `${Math.max(12, box.top - spill)}px`;
    }
  }

  /** The reason chips, rebuilt each render so the selected ones stay lit. */
  function reasonChips() {
    return state.reasons.map((r) => `
      <button type="button" class="rz-chip ${state.picked.includes(r) ? 'on' : ''}" data-reason="${UI.esc(r)}">${UI.esc(r)}</button>`).join('');
  }

  function noteFor(target) {
    if (state.locked) {
      return `The approved wording stays in place. The unlock is a named request to ${state.locked.unlock}, and any change is versioned with the financial statements.`;
    }

    return `Goes to ${target.reviewer || 'the reviewer'}, reviewer of record for ${target.native}. The label is unchanged until they approve.`;
  }

  function wire() {
    const q = (sel) => pop.querySelector(sel);

    q('.rz-x').addEventListener('click', close);
    q('.rz-cancel').addEventListener('click', close);

    pop.querySelectorAll('[data-lang]').forEach((b) => b.addEventListener('click', () => {
      state.target = b.dataset.lang;
      keepTyping(render);
    }));
    pop.querySelectorAll('[data-mode]').forEach((b) => b.addEventListener('click', () => {
      state.mode = b.dataset.mode;
      state.picked = [];
      render();
    }));
    pop.querySelectorAll('[data-reason]').forEach((b) => b.addEventListener('click', () => {
      const r = b.dataset.reason;
      state.picked = state.picked.includes(r) ? state.picked.filter((x) => x !== r) : state.picked.concat([r]);
      keepTyping(render);
    }));

    q('.rz-send').addEventListener('click', send);
    q('.rz-text').focus();
  }

  /** Re-render without losing what the reader has already typed. */
  function keepTyping(draw) {
    const text = pop.querySelector('.rz-text').value;
    const who = pop.querySelector('.rz-who').value;
    draw();
    pop.querySelector('.rz-text').value = text;
    pop.querySelector('.rz-who').value = who;
  }

  async function send() {
    const btn = pop.querySelector('.rz-send');
    const body = {
      str: state.str,
      locale: state.target,
      mode: state.locked ? 'unlock' : state.mode,
      text: pop.querySelector('.rz-text').value,
      reasons: state.picked,
      who: pop.querySelector('.rz-who').value,
    };

    btn.disabled = true;
    try {
      const res = await UI.postJSON('/api/i18n/requests', body);
      close();
      UI.toast(res.note);
    } catch (err) {
      btn.disabled = false;
      UI.toast(err.message);
    }
  }
})();
