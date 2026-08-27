/**
 * Collapsible primary navigation for narrow viewports.
 *
 * The server sends a plain, complete, always-visible navigation list and a
 * toggle button marked `hidden`. Nothing here is required to use the site:
 * this module reveals the button and takes over the list's visibility only
 * once it is running, which is why the no-JavaScript document is not a
 * degraded version of anything — it is the base case.
 *
 * The collapse exists only below the breakpoint. Above it the list is always
 * shown and the button is removed by the stylesheet, so the state it reports
 * is kept truthful rather than left describing a control nobody can reach.
 * This is a disclosure, not a dialog: focus is never trapped, and Escape
 * closes it and hands focus back to the button that opened it.
 */
const WIDE_QUERY = '(min-width: 48em)';

function enhanceNavigation(root: Document = document): void {
  for (const header of Array.from(root.querySelectorAll<HTMLElement>('[data-facet-header]'))) {
    enhanceHeader(header);
  }
}

function enhanceHeader(header: HTMLElement): void {
  const toggle = header.querySelector<HTMLButtonElement>('[data-facet-nav-toggle]');
  const navigation = header.querySelector<HTMLElement>('[data-facet-nav]');

  if (toggle === null || navigation === null) {
    return;
  }

  // The button says which element it controls; honour that rather than
  // guessing, so a mismatched shell fails visibly instead of silently.
  if (toggle.getAttribute('aria-controls') !== navigation.id) {
    return;
  }

  const wide =
    typeof window.matchMedia === 'function'
      ? window.matchMedia(WIDE_QUERY)
      : null;

  const isWide = (): boolean => wide !== null && wide.matches;

  let open = false;

  const setOpen = (next: boolean): void => {
    open = next;
    header.dataset.facetNavOpen = next ? 'true' : 'false';
    // Above the breakpoint the list is visible whatever the button thinks.
    toggle.setAttribute('aria-expanded', isWide() ? 'true' : String(next));
  };

  header.dataset.facetNavEnhanced = '';
  toggle.hidden = false;
  setOpen(false);

  toggle.addEventListener('click', () => setOpen(!open));

  header.addEventListener('keydown', (event: KeyboardEvent) => {
    if (event.key !== 'Escape' || !open || isWide()) {
      return;
    }

    setOpen(false);
    toggle.focus();
  });

  wide?.addEventListener('change', () => setOpen(false));
}

export { WIDE_QUERY, enhanceNavigation };
