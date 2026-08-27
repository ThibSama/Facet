import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

/**
 * Facet uses Vite purely as an asset pipeline for a server-rendered PHP app.
 * There is no SPA entrypoint and no frontend framework: the manifest lets PHP
 * resolve hashed asset URLs at render time.
 */
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
        app: 'resources/js/app.ts',
      },
    },
  },
});
