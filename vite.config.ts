import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

/**
 * Facet uses Vite purely as an asset pipeline for a server-rendered PHP app.
 * There is no SPA entrypoint and no frontend framework: the manifest lets PHP
 * resolve hashed asset URLs at render time.
 *
 * Entrypoints are split along the skin boundary. `app` is the shared layer
 * every request loads; each skin gets its own isolated entrypoint so a
 * document can reference the shared assets plus exactly one skin's assets.
 * PHP decides which pair to emit by reading the manifest — see
 * src/Asset/AssetResolver.php.
 */
const SHARED_ENTRYPOINTS = {
  app: 'resources/js/app.ts',
};

/**
 * Skin entrypoints, keyed by skin id. `evolving-interface` is the one real
 * MVP skin; `fixture-unselected` is a test fixture that is built on purpose
 * and must never be referenced by a rendered document — that is what proves
 * an unselected entrypoint is not injected.
 */
const SKIN_ENTRYPOINTS = {
  'skin-evolving-interface': 'resources/skins/evolving-interface/skin.ts',
  'skin-fixture-unselected': 'resources/skins/fixture-unselected/skin.ts',
};

export default defineConfig({
  plugins: [tailwindcss()],
  base: '/build/',
  // public/ is the PHP document root, not a Vite static dir.
  publicDir: false,
  build: {
    manifest: 'manifest.json',
    outDir: 'public/build',
    emptyOutDir: true,
    rollupOptions: {
      input: {
        ...SHARED_ENTRYPOINTS,
        ...SKIN_ENTRYPOINTS,
      },
    },
  },
});
