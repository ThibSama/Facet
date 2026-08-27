import '../css/app.css';

/**
 * Progressive-enhancement entrypoint for the server-rendered markup.
 * No SPA, no frontend framework — this only decorates HTML PHP already sent.
 */
function markHydrated(root: Document = document): void {
  root.documentElement.dataset.facet = 'ready';
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => markHydrated());
} else {
  markHydrated();
}

export { markHydrated };
