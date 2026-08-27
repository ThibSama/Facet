/**
 * Light/dark control for the shared shell.
 *
 * The stylesheet already themes the document on its own: `prefers-color-scheme`
 * decides when nothing is stored, and a `data-theme` attribute overrides it.
 * This module only adds the part CSS cannot do — letting a visitor choose, and
 * remembering the choice.
 *
 * What it stores is a single browser-local preference: one key in
 * localStorage, holding the word "light" or "dark". No cookie, so it is never
 * sent to the server; nothing about the visitor beyond which of two
 * stylesheets they prefer. Every read and write is guarded, because storage
 * throws outright in some privacy modes and a theme switch is not worth
 * breaking a page over.
 */
const STORAGE_KEY = 'facet.theme';

const DARK_QUERY = '(prefers-color-scheme: dark)';

type Theme = 'light' | 'dark';

function isTheme(value: unknown): value is Theme {
  return value === 'light' || value === 'dark';
}

/** The stored choice, or null when there is none — or none can be read. */
function storedTheme(): Theme | null {
  try {
    const stored = window.localStorage.getItem(STORAGE_KEY);

    return isTheme(stored) ? stored : null;
  } catch {
    return null;
  }
}

function storeTheme(theme: Theme): void {
  try {
    window.localStorage.setItem(STORAGE_KEY, theme);
  } catch {
    // Unwritable storage costs the visitor persistence, not the page.
  }
}

function systemTheme(): Theme {
  return typeof window.matchMedia === 'function' && window.matchMedia(DARK_QUERY).matches
    ? 'dark'
    : 'light';
}

/**
 * What the document is actually showing: the explicit choice the pre-paint
 * bootstrap stamped on <html>, and otherwise whatever the system asked for.
 */
function activeTheme(root: Document = document): Theme {
  const declared = root.documentElement.getAttribute('data-theme');

  return isTheme(declared) ? declared : systemTheme();
}

function applyTheme(theme: Theme, root: Document = document): void {
  root.documentElement.setAttribute('data-theme', theme);
}

/**
 * Reveals and wires the theme control the shell rendered hidden.
 *
 * It is a toggle button, so its accessible state is `aria-pressed`: pressed
 * means the dark theme is the one currently in effect. The label stays fixed —
 * a button whose name changes as you press it is far harder to follow.
 */
function enhanceThemeControls(root: Document = document): void {
  const controls = Array.from(
    root.querySelectorAll<HTMLButtonElement>('[data-facet-theme-toggle]')
  );

  if (controls.length === 0) {
    return;
  }

  const sync = (): void => {
    const pressed = activeTheme(root) === 'dark' ? 'true' : 'false';

    for (const control of controls) {
      control.setAttribute('aria-pressed', pressed);
    }
  };

  for (const control of controls) {
    control.hidden = false;
    control.addEventListener('click', () => {
      const next: Theme = activeTheme(root) === 'dark' ? 'light' : 'dark';

      applyTheme(next, root);
      storeTheme(next);
      sync();
    });
  }

  sync();

  if (typeof window.matchMedia === 'function') {
    // With no stored choice the CSS follows the system by itself; only the
    // button's reported state has to be brought back in line.
    window.matchMedia(DARK_QUERY).addEventListener('change', () => {
      if (storedTheme() === null) {
        sync();
      }
    });
  }
}

export { STORAGE_KEY, activeTheme, applyTheme, enhanceThemeControls, storedTheme };
export type { Theme };
