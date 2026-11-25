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
import { initBookingForm } from './customer-picker.js';
import { initAvailability } from './availability.js';
import { initFind } from './find.js';
import { initAppointmentHost } from './appointment-host.js';

// Once per load: they live outside the swapped content.
initTheme();
initInactivityMonitor();

registerPage('[data-calendar]', initCalendar);
registerPage('[data-provider-form]', initProviderForm);
registerPage('form[data-customer-url]', initBookingForm);
registerPage('[data-availability]', initAvailability);
registerPage('[data-appt-host]', initAppointmentHost);
// The top bar's Find: the head is swapped with each page, so it registers like one.
registerPage('[data-shell-find]', initFind);
dashboardPages.forEach(([selector, init]) => registerPage(selector, init));

initShell();
