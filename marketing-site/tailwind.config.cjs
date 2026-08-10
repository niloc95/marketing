/**
 * Tailwind config for the standalone marketing site.
 * Brand palette + font stack live in ../tailwind.tokens.cjs, shared with the
 * directory app (tailwind.directory.cjs) so both read as one visual system:
 *   ocean  #003049 (primary)   orange #F77F00 (accent/CTA)
 *   golden #FCBF49 (highlight)  crimson #D62828 (danger)  cream #EAE2B7 (warm bg)
 */
const tokens = require('../tailwind.tokens.cjs');

/** @type {import('tailwindcss').Config} */
module.exports = {
  darkMode: 'class',
  // .php as well as .html: contact.php server-renders the no-JS confirm step
  // and the result pages, and its classes have to survive the purge. Scripts
  // are deliberately NOT scanned — assets/contact.js reads its class names off
  // data attributes in contact.html rather than inventing any.
  content: ['./marketing-site/**/*.{html,php}'],
  theme: {
    extend: tokens,
  },
  plugins: [require('@tailwindcss/forms')],
};
