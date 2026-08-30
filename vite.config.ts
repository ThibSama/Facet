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

/**
 * Project-local files that PHP must address directly belong here once their
 * provenance and final metadata are known. Declaring them as inputs gives the
 * Vite manifest a stable source key and a fingerprinted production file.
 * Fonts referenced from resources/fonts/fonts.css do not need a second entry.
 */
const LOCAL_ASSET_ENTRYPOINTS = {};

export default defineConfig(({ command }) => ({
  plugins: [tailwindcss()],
  /**
   * `/build/` is where the production output lands under the PHP document
   * root, so built asset URLs must carry it. The dev server has no such
   * directory: it serves the project's *sources*, and PHP addresses them by
   * their repository-relative path (`/resources/js/app.ts`). Applying the build
   * base there too would move every dev URL under `/build/` while PHP kept
   * emitting the unprefixed one, and every module request — the HMR client
   * included — would 404 against a server that was demonstrably listening.
   *
   * Build output is unaffected: only `serve` uses the root base.
   */
  base: command === 'build' ? '/build/' : '/',
  /**
   * Backend integration: the document is served by PHP on :8000 while the
   * modules and stylesheets come from Vite on :5173. Without an explicit
   * origin, Vite rewrites the `url()` in `resources/fonts/fonts.css` to a
   * root-relative path, which the browser then resolves against the *document*
   * — asking PHP for a font that only exists behind Vite. Declaring the origin
   * makes every generated asset URL absolute, so the two servers stop
   * disagreeing about who owns `/resources/`.
   *
   * `strictPort` is the same promise the supervisor makes: the development
   * ports are fixed, and a busy one is a failure rather than a quiet move.
   */
  server: {
    host: '127.0.0.1',
    port: 5173,
    strictPort: true,
    origin: 'http://127.0.0.1:5173',
  },
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
        ...LOCAL_ASSET_ENTRYPOINTS,
      },
    },
  },
}));
