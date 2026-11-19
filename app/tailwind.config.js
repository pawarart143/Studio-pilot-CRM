/** @type {import('tailwindcss').Config} */
const v = (n) => `rgb(var(--${n}) / <alpha-value>)`;
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  darkMode: 'class',
  theme: {
    extend: {
      fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
      colors: {
        app: v('app'),
        surface: v('surface'),
        subtle: v('subtle'),
        line: v('line'),
        ink: v('ink'),
        muted: v('muted'),
        faint: v('faint'),
        accent: { DEFAULT: v('accent'), soft: v('accent-soft'), ink: v('accent-ink') },
      },
      boxShadow: {
        card: '0 1px 2px rgb(15 23 42 / 0.04), 0 1px 3px rgb(15 23 42 / 0.04)',
        pop: '0 10px 30px -10px rgb(15 23 42 / 0.25), 0 4px 10px -4px rgb(15 23 42 / 0.1)',
      },
      borderRadius: { xl: '0.85rem' },
      fontSize: { '2xs': ['0.6875rem', '1rem'] },
    },
  },
  plugins: [],
};
