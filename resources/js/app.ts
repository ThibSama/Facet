import '../css/app.css';
import { enhanceNavigation } from './nav';
import { enhanceThemeControls } from './theme';

/**
 * Progressive-enhancement entrypoint for the server-rendered markup.
 * No SPA, no frontend framework — this only decorates HTML PHP already sent.
 *
 * The shell behaviours live in the shared layer rather than in a skin: they
 * are addressed through `data-facet-*` hooks, so any skin that renders the
 * shell contract gets a working navigation collapse and theme control without
 * shipping its own copy of either.
 */
function markHydrated(root: Document = document): void {
  root.documentElement.dataset.facet = 'ready';
}

function enhance(root: Document = document): void {
  enhanceNavigation(root);
  enhanceThemeControls(root);
  markHydrated(root);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => enhance());
} else {
  enhance();
}

export { enhance, markHydrated };
