/**
 * Every drop-down in the application opens as a searchable list: the user types
 * part of what they are after — an account code, a word of a grant's name — and
 * picks from what is left, rather than scrolling a chart of accounts.
 *
 * The native <select> stays exactly where it is and stays the source of truth.
 * Only its drop-down is replaced: the select keeps its own styling in every screen
 * that sizes it, its value is still read and written by the page scripts, and a
 * pick is announced with the same `input` and `change` events a native pick
 * fires, so the delegated listeners the pages already have go on working. That is
 * also why nothing has to be set up per select — a select a page renders later
 * is searchable the moment it is on screen.
 *
 * A select marked data-native keeps the browser's own list. Touch-only devices
 * keep it too: their own pickers are built for a thumb, and this list is not.
 */
(() => {
  if (window.matchMedia && matchMedia('(hover: none) and (pointer: coarse)').matches) return;

  const OPEN_KEYS = ['Enter', ' ', 'ArrowDown', 'ArrowUp', 'F4'];

  const pop = document.createElement('div');
  pop.className = 'ss-pop';
  pop.hidden = true;
  pop.innerHTML = `
    <input class="ss-search" type="text" autocomplete="off" spellcheck="false" placeholder="Type to search…"
      role="combobox" aria-autocomplete="list" aria-expanded="true" aria-controls="ss-list">
    <ul class="ss-list" id="ss-list" role="listbox"></ul>
    <div class="ss-empty" hidden></div>`;
  const input = pop.querySelector('.ss-search');
  const list = pop.querySelector('.ss-list');
  const empty = pop.querySelector('.ss-empty');

  let current = null; // the select whose list is open
  let items = [];     // the options on show, as { index, el }
  let active = -1;    // position in items of the highlighted one

  const eligible = (el) => el instanceof HTMLSelectElement && !el.multiple && el.size <= 1 && !el.disabled && !('native' in el.dataset);

  // Case and accents are ignored, so "cote" finds "Côte d'Ivoire".
  const fold = (s) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const esc = (s) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  /** The label with each searched word marked, where folding has left its length alone. */
  function mark(text, terms) {
    const folded = fold(text);
    if (!terms.length || folded.length !== text.length) return esc(text);
    const hit = new Array(text.length).fill(false);
    terms.forEach((t) => { const at = folded.indexOf(t); if (at >= 0) hit.fill(true, at, at + t.length); });
    let html = '';
    for (let i = 0; i < text.length;) {
      let j = i;
      while (j < text.length && hit[j] === hit[i]) j++;
      html += hit[i] ? `<mark>${esc(text.slice(i, j))}</mark>` : esc(text.slice(i, j));
      i = j;
    }
    return html;
  }

  function render() {
    const terms = fold(input.value).split(/\s+/).filter(Boolean);
    const options = Array.from(current.options);
    let html = '';
    let group = null;
    items = [];
    options.forEach((o, index) => {
      if (o.hidden) return;
      const text = o.textContent.trim();
      // Every word has to appear, in any order, in the label or the value — so
      // "salaries 41" finds "4100 · Salaries" as readily as "4100" does.
      const haystack = fold(text + ' ' + o.value);
      if (!terms.every((t) => haystack.includes(t))) return;
      const g = o.parentElement.tagName === 'OPTGROUP' ? o.parentElement : null;
      if (g && g !== group) html += `<li class="ss-group" role="presentation">${esc(g.label)}</li>`;
      group = g;
      const disabled = o.disabled || (g && g.disabled);
      html += `<li class="ss-opt${o.selected ? ' is-selected' : ''}" role="option" id="ss-opt-${index}" data-index="${index}"`
        + ` aria-selected="${o.selected}"${disabled ? ' aria-disabled="true"' : ''}>${text ? mark(text, terms) : '&nbsp;'}</li>`;
      if (!disabled) items.push({ index });
    });
    list.innerHTML = html;
    items.forEach((it) => { it.el = list.querySelector(`[data-index="${it.index}"]`); });
    empty.hidden = items.length > 0 || list.children.length > 0;
    empty.textContent = options.length ? 'Nothing matches' : 'No options';

    // Open on the current choice; once the user is typing, on the best match.
    const at = terms.length ? 0 : items.findIndex((it) => it.index === current.selectedIndex);
    highlight(at >= 0 ? at : 0);
  }

  function highlight(i) {
    if (active >= 0 && items[active]) items[active].el.classList.remove('is-active');
    active = items.length ? Math.max(0, Math.min(items.length - 1, i)) : -1;
    if (active < 0) { input.removeAttribute('aria-activedescendant'); return; }
    const el = items[active].el;
    el.classList.add('is-active');
    input.setAttribute('aria-activedescendant', el.id);
    // The list scrolls itself; scrollIntoView would move the page under it too.
    if (el.offsetTop < list.scrollTop) list.scrollTop = el.offsetTop;
    else if (el.offsetTop + el.offsetHeight > list.scrollTop + list.clientHeight) list.scrollTop = el.offsetTop + el.offsetHeight - list.clientHeight;
  }

  /** Below the select when there is room, above it when there is more there; never off-screen. */
  function place() {
    const r = current.getBoundingClientRect();
    const vw = document.documentElement.clientWidth;
    const vh = window.innerHeight;
    const width = Math.min(Math.max(r.width, 240), 480, vw - 16);
    const rtl = getComputedStyle(current).direction === 'rtl';
    const left = Math.max(8, Math.min(rtl ? r.right - width : r.left, vw - width - 8));
    const below = vh - r.bottom - 12;
    const above = r.top - 12;
    const down = below >= 220 || below >= above;
    Object.assign(pop.style, {
      left: left + 'px',
      width: width + 'px',
      top: down ? r.bottom + 4 + 'px' : '',
      bottom: down ? '' : vh - r.top + 4 + 'px',
    });
    list.style.maxHeight = Math.max(120, Math.min(320, (down ? below : above) - 48)) + 'px';
  }

  function open(select, query) {
    if (current) close(false);
    current = select;
    if (!pop.isConnected) document.body.appendChild(pop);
    input.value = query || '';
    pop.hidden = false;
    select.classList.add('ss-open');
    select.setAttribute('aria-expanded', 'true');
    render();
    place();
    input.focus({ preventScroll: true });
    highlight(active);
  }

  function close(refocus) {
    if (!current) return;
    const select = current;
    current = null;
    pop.hidden = true;
    list.innerHTML = '';
    items = [];
    active = -1;
    select.classList.remove('ss-open');
    select.setAttribute('aria-expanded', 'false');
    if (refocus && select.isConnected) select.focus();
  }

  function pick(i) {
    const it = items[i];
    if (!it) return;
    const select = current;
    close(true);
    if (select.selectedIndex === it.index) return;
    select.selectedIndex = it.index;
    select.dispatchEvent(new Event('input', { bubbles: true }));
    select.dispatchEvent(new Event('change', { bubbles: true }));
  }

  // A press on a select opens its list in place of the browser's; a press on the
  // one already open closes it; a press anywhere else puts the list away.
  document.addEventListener('mousedown', (e) => {
    if (pop.contains(e.target)) return;
    const select = e.target.closest ? e.target.closest('select') : null;
    if (e.button === 0 && select && eligible(select)) {
      e.preventDefault();
      if (current === select) { close(true); return; }
      select.focus();
      open(select);
      return;
    }
    close(false);
  }, true);

  // From the keyboard the list opens the way a native one does, and typing on a
  // focused select opens it already searching for what was typed.
  document.addEventListener('keydown', (e) => {
    const select = e.target;
    if (current || !eligible(select) || e.ctrlKey || e.metaKey) return;
    const printable = e.key.length === 1 && e.key !== ' ' && !e.altKey;
    if (!printable && !OPEN_KEYS.includes(e.key)) return;
    e.preventDefault();
    open(select, printable ? e.key : '');
  }, true);

  input.addEventListener('input', render);

  input.addEventListener('keydown', (e) => {
    const page = Math.max(1, Math.floor(list.clientHeight / 30));
    switch (e.key) {
      case 'ArrowDown': highlight(active + 1); break;
      case 'ArrowUp': highlight(active - 1); break;
      case 'PageDown': highlight(active + page); break;
      case 'PageUp': highlight(active - page); break;
      case 'Enter': pick(active); break;
      // Escape closes only the list, not the drawer or dialog the select sits in.
      case 'Escape': close(true); break;
      // Focus goes back to the select before the browser moves it on, so Tab
      // carries on from the select rather than from the end of the page.
      case 'Tab': close(true); return;
      default: return;
    }
    e.preventDefault();
    e.stopPropagation();
  });

  // Clicks inside the list must not take focus from the search box.
  pop.addEventListener('mousedown', (e) => e.preventDefault());
  list.addEventListener('click', (e) => {
    const li = e.target.closest('.ss-opt');
    if (!li || li.getAttribute('aria-disabled')) return;
    pick(items.findIndex((it) => it.el === li));
  });
  list.addEventListener('mousemove', (e) => {
    const li = e.target.closest('.ss-opt');
    const i = li ? items.findIndex((it) => it.el === li) : -1;
    if (i >= 0 && i !== active) highlight(i);
  });

  pop.addEventListener('focusout', (e) => { if (current && !pop.contains(e.relatedTarget)) close(false); });
  // The list is pinned to the viewport, so once the page under it moves it is put away
  // rather than left floating beside the wrong field.
  window.addEventListener('scroll', (e) => { if (current && !pop.contains(e.target)) close(false); }, true);
  window.addEventListener('resize', () => close(false));
})();
