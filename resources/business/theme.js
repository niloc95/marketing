/**
 * Light or dark, the same 'xs-theme' localStorage key the public pages and
 * the marketing site use, so a choice made anywhere carries over. The first
 * paint is handled by the blocking script in layouts/business.php.
 */
const KEY = 'xs-theme';

function apply(theme) {
  document.documentElement.classList.toggle('dark', theme === 'dark');
  document.documentElement.style.colorScheme = theme;
}

export function initTheme() {
  document.querySelectorAll('[data-biz-theme-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const next = document.documentElement.classList.contains('dark') ? 'light' : 'dark';
      apply(next);
      try {
        localStorage.setItem(KEY, next);
      } catch (e) {
        // Storage blocked: the choice lasts for this page only.
      }
    });
  });
}
