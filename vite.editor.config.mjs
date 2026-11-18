/**
 * Vite build for the profile forms' rich text editor (Tiptap).
 *
 * Input:  resources/editor/rich-editor.js
 * Output: public/assets/editor/rich-editor.js   (npm run editor:build)
 *
 * One classic script (IIFE) that sets window.RichEditor, loaded with `defer`
 * before public/assets/directory.js by directory/_editor_assets.php. Fixed
 * name, no hash: the view busts caches with ?v=<filemtime>. Self hosted and
 * loaded from a file, so the strict CSP needs nothing new. The built file is
 * committed, like public/assets/business/.
 */
import { defineConfig } from 'vite';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('.', import.meta.url));

export default defineConfig({
  root,
  publicDir: false,
  logLevel: 'warn',
  build: {
    outDir: 'public/assets/editor',
    emptyOutDir: true,
    target: 'es2020',
    sourcemap: false,
    minify: 'esbuild',
    lib: {
      entry: 'resources/editor/rich-editor.js',
      name: 'RichEditorBundle',
      formats: ['iife'],
      fileName: () => 'rich-editor.js',
    },
  },
});
