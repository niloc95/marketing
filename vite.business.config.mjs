/**
 * Vite build for the signed in app shell's scripts: /bookings (online
 * bookings, docs/bookings/) and the /admin shell. Public pages keep
 * public/assets/directory.js and the Tailwind CLI; nothing here touches them.
 *
 * Inputs:  resources/business/main.js  → public/assets/business/business.js
 *          resources/shell/admin.js    → public/assets/business/shell.js
 *          (npm run biz:build)
 *
 * Fixed file names, no hashes: the layouts bust caches with ?v=<filemtime>,
 * like directory.css. The two entries share resources/shell/, which Rollup
 * puts in a shared chunk. ES modules loaded with type="module" from files, so
 * the strict CSP needs nothing new.
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
      input: {
        business: 'resources/business/main.js',
        shell: 'resources/shell/admin.js',
      },
      output: {
        entryFileNames: '[name].js',
        // Hashed: the entries import it without a ?v= stamp.
        chunkFileNames: '[name]-[hash].js',
        assetFileNames: '[name][extname]',
      },
    },
  },
});
