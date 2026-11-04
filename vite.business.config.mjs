/**
 * Vite build for the /business dashboard script only (online bookings,
 * docs/bookings/). Public pages keep public/assets/directory.js and the
 * Tailwind CLI; nothing here touches them.
 *
 * Input:  resources/business/main.js
 * Output: public/assets/business/business.js   (npm run biz:build)
 *
 * Fixed file names, no hashes: layouts/business.php busts caches with
 * ?v=<filemtime>, like directory.css. One ES module, loaded with
 * type="module" from a file, so the strict CSP needs nothing new.
 */
import { defineConfig } from 'vite';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('.', import.meta.url));

export default defineConfig({
  root,
  publicDir: false,
  logLevel: 'warn',
  build: {
    outDir: process.env.BIZ_OUT_DIR || 'public/assets/business',
    emptyOutDir: true,
    target: 'es2020',
    sourcemap: false,
    rollupOptions: {
      input: 'resources/business/main.js',
      output: {
        entryFileNames: 'business.js',
        chunkFileNames: '[name].js',
        assetFileNames: '[name][extname]',
      },
    },
  },
});
