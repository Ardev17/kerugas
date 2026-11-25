import type { Config } from 'tailwindcss'

/**
 * Design tokens do KERUGAS (Master Build Prompt, secção 9).
 * Alteráveis aqui sem tocar nos componentes.
 */
export default {
  darkMode: 'class',
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        primary: '#FC7201',
        secondary: '#FFB020',
        success: '#16A34A',
        warning: '#F59E0B',
        danger: '#DC2626',
        dark: '#0F172A',
        gray: '#64748B',
        light: '#F8FAFC',
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
      },
      screens: {
        xs: '360px',
      },
    },
  },
  plugins: [],
} satisfies Config
