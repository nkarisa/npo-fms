/**
 * User manual: the documentation rendered from docs/user-manual.md, with a
 * contents sidebar that tracks the section you are reading. The HTML and the
 * table of contents come from /api/manual; images are served from /api/manual/image.
 */
(async function () {
  const app = document.getElementById('app');

  let data;
  try {
    data = await UI.fetchJSON('/api/manual');
  } catch (err) {
    app.innerHTML = `<div class="coa-empty">${UI.esc(err.message)}</div>`;
    return;
  }

  UI.pageHead(app, {
    kicker: 'Insight',
    title: 'User manual',
    blurb: 'How to use the application, page by page. Last updated ' + UI.esc(data.updated) + '.',
  });

  const layout = document.createElement('div');
  layout.className = 'doc-layout';
  layout.innerHTML = `
    <nav class="doc-toc" aria-label="Contents">
      <div class="doc-toc-title">On this page</div>
      ${data.toc.map((t) => `<a class="doc-toc-link doc-toc-l${t.level}" href="#${UI.esc(t.id)}" data-id="${UI.esc(t.id)}">${UI.esc(t.text)}</a>`).join('')}
    </nav>
    <article class="doc-body">${data.html}</article>`;
  app.appendChild(layout);

  // In-page links (the manual's own table of contents and the sidebar) scroll
  // to the heading rather than jumping, and never leave the app shell.
  layout.addEventListener('click', (e) => {
    const link = e.target.closest('a[href^="#"]');
    if (!link) return;
    const id = decodeURIComponent(link.getAttribute('href').slice(1));
    const target = document.getElementById(id);
    if (target) {
      e.preventDefault();
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      history.replaceState(null, '', '#' + id);
    }
  });

  // Highlight the contents entry for the section currently in view.
  const links = new Map([...layout.querySelectorAll('.doc-toc-link')].map((a) => [a.dataset.id, a]));
  const headings = [...layout.querySelectorAll('.doc-body [id]')].filter((h) => links.has(h.id));
  if ('IntersectionObserver' in window && headings.length) {
    const seen = new Set();
    const spy = new IntersectionObserver((entries) => {
      entries.forEach((entry) => entry.isIntersecting ? seen.add(entry.target.id) : seen.delete(entry.target.id));
      const active = headings.find((h) => seen.has(h.id));
      links.forEach((a) => a.classList.remove('is-active'));
      if (active) links.get(active.id).classList.add('is-active');
    }, { rootMargin: '-80px 0px -70% 0px' });
    headings.forEach((h) => spy.observe(h));
  }

  // If the page was opened with a hash, jump to it once rendered.
  if (location.hash) {
    const target = document.getElementById(decodeURIComponent(location.hash.slice(1)));
    if (target) target.scrollIntoView();
  }
})();
