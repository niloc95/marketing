/**
 * Light or dark, the same 'xs-theme' localStorage key the public pages and
 * the marketing site use, so a choice made anywhere carries over. The first
 * paint is handled by the blocking script in layouts/business.php.
 */
const KEY = 'xs-theme';

function apply(theme) {
  document.documentElement.classList.toggle('dark', theme === 'dark');
  document.documentElement.style.colorScheme = theme;
  document.querySelectorAll('[data-theme-toggle]').forEach((b) => b.setAttribute('aria-pressed', String(theme === 'dark')));
}

/** The shell's top bar button (layouts/_shell.php); delegated, so it outlives page swaps. */
export function initTheme() {
  const dark = document.documentElement.classList.contains('dark');
  document.querySelectorAll('[data-theme-toggle]').forEach((b) => b.setAttribute('aria-pressed', String(dark)));
  document.addEventListener('click', (e) => {
    if (!e.target.closest('[data-theme-toggle]')) return;
    const next = document.documentElement.classList.contains('dark') ? 'light' : 'dark';
    apply(next);
    try {
      localStorage.setItem(KEY, next);
    } catch (err) {
      // Storage blocked: the choice lasts for this page only.
    }
  });
}
