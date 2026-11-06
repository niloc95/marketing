/**
 * Entry for the /bookings dashboard (layouts/business.php). Each module
 * looks for its own data attributes and does nothing when they are absent.
 */
import { initTheme } from './theme.js';
import { initInactivityMonitor } from './inactivity.js';
import { initCalendar } from './calendar/scheduler.js';
import { initProviderForms } from './providers.js';
import { initDashboard } from './dashboard.js';
import { initBookingPage } from './customer-picker.js';

initTheme();
initInactivityMonitor();
initCalendar();
initProviderForms();
initDashboard();
initBookingPage();
