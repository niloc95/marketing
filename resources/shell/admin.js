/**
 * Entry for /admin (layouts/admin.php) → assets/business/shell.js. Admin's
 * page behaviour lives in directory.js, delegated on document, so after a
 * page swap there is nothing to start again here.
 */
import { initShell } from './index.js';

initShell();
