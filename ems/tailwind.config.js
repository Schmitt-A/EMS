/** @type {import('tailwindcss').Config} */
module.exports = {
  darkMode: 'class',
  content: ['./app/**/*.php', './app/**/*.js'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
      borderRadius: {
        xl: '0.75rem',
        lg: '0.5rem',
      },
      boxShadow: {
        card: '0 1px 2px rgb(0 0 0 / 0.05)',
      },
      colors: {
        border: 'hsl(var(--border) / <alpha-value>)',
        background: 'hsl(var(--background) / <alpha-value>)',
        foreground: 'hsl(var(--foreground) / <alpha-value>)',
        primary: {
          DEFAULT: 'hsl(var(--primary) / <alpha-value>)',
          foreground: 'hsl(var(--primary-foreground) / <alpha-value>)',
        },
        muted: {
          DEFAULT: 'hsl(var(--muted) / <alpha-value>)',
          foreground: 'hsl(var(--muted-foreground) / <alpha-value>)',
        },
        card: {
          DEFAULT: 'hsl(var(--card) / <alpha-value>)',
          foreground: 'hsl(var(--card-foreground) / <alpha-value>)',
        },
        pv: 'hsl(var(--pv) / <alpha-value>)',
        battery: 'hsl(var(--battery) / <alpha-value>)',
        house: 'hsl(var(--house) / <alpha-value>)',
        import: 'hsl(var(--import) / <alpha-value>)',
        export: 'hsl(var(--export) / <alpha-value>)',
        wallbox: 'hsl(var(--wallbox) / <alpha-value>)',
      },
    },
  },
  plugins: [],
};
