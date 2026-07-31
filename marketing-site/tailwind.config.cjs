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
  content: ['./marketing-site/**/*.html'],
  theme: {
    extend: tokens,
  },
  plugins: [require('@tailwindcss/forms')],
};
