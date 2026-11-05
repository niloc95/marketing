/**
 * Entry for the /business dashboard (layouts/business.php). Each module
 * looks for its own data attributes and does nothing when they are absent.
 */
import { initTheme } from './theme.js';
import { initInactivityMonitor } from './inactivity.js';
import { initCalendar } from './calendar/scheduler.js';
import { initProviderForms } from './providers.js';
import { initDashboard } from './dashboard.js';

initTheme();
initInactivityMonitor();
initCalendar();
initProviderForms();
initDashboard();
