/**
 * The shell's sidebar (layouts/_sidebar.php), ported from Bookings'
 * unified-sidebar.js. Phone: a drawer. Tablet: a rail of icons that opens to
 * full width. Desktop: always open, so nothing here runs there. Closes on
 * the backdrop, the close button, Escape, after a page change, and when the
 * window grows to desktop width.
 */
const DESKTOP = '(min-width: 1024px)';

export function initSidebar() {
  const sidebar = document.querySelector('[data-shell-sidebar]');
  const toggle = document.querySelector('[data-shell-menu]');
  const overlay = document.querySelector('[data-shell-overlay]');
  if (!sidebar || !toggle) return;

  const setOpen = (open) => {
    sidebar.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    if (overlay) overlay.hidden = !open;
    document.documentElement.classList.toggle('shell-locked', open);
  };
  const close = () => {
    if (sidebar.classList.contains('is-open')) setOpen(false);
  };

  toggle.addEventListener('click', () => {
    const open = !sidebar.classList.contains('is-open');
    setOpen(open);
    if (open) sidebar.querySelector('.shell-link')?.focus();
  });
  overlay?.addEventListener('click', close);
  sidebar.querySelector('[data-shell-close]')?.addEventListener('click', () => {
    close();
    toggle.focus();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && sidebar.classList.contains('is-open')) {
      close();
      toggle.focus();
    }
  });
  document.addEventListener('spa:navigated', close);
  window.matchMedia(DESKTOP).addEventListener('change', (e) => {
    if (e.matches) close();
  });
}
