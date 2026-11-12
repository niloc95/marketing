/**
 * Docking under the top bar. The top bar is the page's header and can wrap
 * onto a second line on a phone (title, then the page's buttons), so its
 * height is published as --shell-top for what docks beneath it: the tab
 * strips (.page-tabs) and the profile editor's side column.
 *
 * Tab strips scroll sideways on a phone, so the active tab is brought into
 * view when a page arrives; otherwise "Branches" can be active and off screen.
 */
import { registerPage } from './lifecycle.js';

function publishTop() {
  const bar = document.querySelector('.shell-topbar');
  if (!bar) return;
  const set = () => document.documentElement.style.setProperty('--shell-top', bar.offsetHeight + 'px');
  set();
  if ('ResizeObserver' in window) new ResizeObserver(set).observe(bar);
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', publishTop);
else publishTop();

registerPage('.page-tabs', (strip) => {
  const active = strip.querySelector('.is-active, [aria-current="page"]');
  if (!active || strip.scrollWidth <= strip.clientWidth) return;
  strip.scrollLeft = active.offsetLeft - (strip.clientWidth - active.offsetWidth) / 2;
});
