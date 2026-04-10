// Ref: https://tailwindcss.com/docs/configuration
/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './index.html',
    './src/**/*.{vue,js,ts,jsx,tsx}',
  ],
  theme: {
    extend: {
      colors: {
        // SaintAugustin brand palette (adjust to taste)
        primary: {
          50:  '#f0f5ff',
          100: '#e0ebff',
          200: '#b8d4fe',
          300: '#7ab4fc',
          400: '#3b8ff8',
          500: '#1a6fe8',
          600: '#0d54c6',
          700: '#0e42a1',
          800: '#123985',
          900: '#14326e',
          950: '#0e2049',
        },
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [],
}
