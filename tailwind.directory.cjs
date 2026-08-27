/**
 * Tailwind config for the directory app (CodeIgniter views).
 * Shares its palette and type stack with the marketing site via
 * ./tailwind.tokens.cjs.
 *
 * Input:  resources/directory.css
 * Output: public/assets/directory.css   (npm run list:css)
 */
const tokens = require('./tailwind.tokens.cjs');

/** @type {import('tailwindcss').Config} */
module.exports = {
  // Class strategy, not media: the theme is a user choice persisted in
  // localStorage['xs-theme'] and shared with the marketing site, so a visitor
  // who picks dark there arrives here already dark.
  darkMode: 'class',
  // directory.js is scanned too, not just the views: the map modules build
  // markup at runtime (popup contents, the fullscreen control, cluster badges),
  // and a class that only ever appears in JS is otherwise purged as unused —
  // which shows up as an unstyled control rather than as a build error.
  //
  // app/Helpers likewise: category_group_style() in directory_ui_helper.php is
  // where the fifteen .cat-tint-* class names are written down, and nowhere
  // else. Without this glob every category tile builds green and renders navy.
  content: ['./app/Views/**/*.php', './app/Helpers/**/*.php', './public/assets/directory.js'],
  theme: {
    extend: tokens,
  },
  plugins: [require('@tailwindcss/forms')],
};
