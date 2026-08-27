import './skin.css';

/**
 * evolving-interface — skin runtime.
 *
 * Scaffolding only: it marks the document with the skin that rendered it so
 * the isolation contract is observable in the DOM. Like the shared runtime it
 * decorates server-rendered markup and never produces it.
 */
const SKIN_ID = 'evolving-interface';

function markSkin(root: Document = document): void {
  root.documentElement.dataset.facetSkin = SKIN_ID;
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => markSkin());
} else {
  markSkin();
}

export { SKIN_ID, markSkin };
