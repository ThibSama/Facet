import js from '@eslint/js';
import tseslint from 'typescript-eslint';

export default tseslint.config(
  {
    ignores: ['node_modules/**', 'vendor/**', 'public/build/**'],
  },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    // Node scripts: build tooling and the development supervisor. They run on
    // Node directly, never in a browser, so the Node globals they use are
    // declared here rather than pulled in as another dependency.
    //
    // The browser globals are here for the same reason and not by accident. A
    // script that drives a page carries functions written to run *inside* that
    // page — everything handed to `page.evaluate` and `page.addInitScript` —
    // and those are browser code living in a Node file. They are legitimate
    // there, so they are declared here rather than silenced at each use.
    files: ['tools/*.mjs', 'scripts/*.mjs'],
    languageOptions: {
      globals: {
        console: 'readonly',
        process: 'readonly',
        fetch: 'readonly',
        AbortSignal: 'readonly',
        setTimeout: 'readonly',
        clearTimeout: 'readonly',
        window: 'readonly',
        document: 'readonly',
        Event: 'readonly',
        requestAnimationFrame: 'readonly',
        performance: 'readonly',
        getComputedStyle: 'readonly',
      },
    },
  },
  {
    files: ['**/*.{ts,js}'],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'module',
    },
    rules: {
      'no-console': ['error', { allow: ['warn', 'error'] }],
      eqeqeq: ['error', 'always'],
    },
  },
);
