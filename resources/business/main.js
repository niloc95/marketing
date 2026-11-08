/**
 * Entry for the /business dashboard (layouts/business.php). Page scripts
 * register with the shell (resources/shell/), which runs them for every page
 * that arrives, by a load or a swap, and stops them when it leaves. Each
 * looks for its own data attributes and does nothing when they are absent.
 */
import { initShell, registerPage } from '../shell/index.js';
import { initTheme } from './theme.js';
import { initInactivityMonitor } from './inactivity.js';
import { initCalendar } from './calendar/scheduler.js';
import { initProviderForm } from './providers.js';
import { dashboardPages } from './dashboard.js';

// Once per load: they live outside the swapped content.
initTheme();
initInactivityMonitor();

registerPage('[data-calendar]', initCalendar);
registerPage('[data-provider-form]', initProviderForm);
dashboardPages.forEach(([selector, init]) => registerPage(selector, init));

initShell();
