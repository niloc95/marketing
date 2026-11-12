/**
 * The app shell (layouts/_shell.php): sidebar and page swaps. Imported by
 * the /business entry (resources/business/main.js) and built on its own as
 * assets/business/shell.js for /admin, whose page behaviour stays in
 * directory.js.
 */
import { initSidebar } from './sidebar.js';
import { initSpa } from './spa.js';
import { startPages } from './lifecycle.js';
import './tabs.js';

export { registerPage } from './lifecycle.js';

/** Call once, after the page scripts have registered. */
export function initShell() {
  initSidebar();
  initSpa();
  startPages(document);
}
