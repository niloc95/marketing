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
  content: ['./app/Views/**/*.php'],
  theme: {
    extend: tokens,
  },
  plugins: [require('@tailwindcss/forms')],
};
