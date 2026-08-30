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
    files: ['tools/*.mjs', 'scripts/*.mjs'],
    languageOptions: {
      globals: {
        console: 'readonly',
        process: 'readonly',
        fetch: 'readonly',
        AbortSignal: 'readonly',
        setTimeout: 'readonly',
        clearTimeout: 'readonly',
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
