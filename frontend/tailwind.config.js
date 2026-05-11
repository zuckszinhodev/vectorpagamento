/** @type {import('tailwindcss').Config} */

export default {
  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        // ── Surfaces ──────────────────────────────────────
        surface: {
          page:     'var(--surface-page)',
          card:     'var(--surface-card)',
          elevated: 'var(--surface-elevated)',
          hover:    'var(--surface-hover)',
          nav:      'var(--surface-nav)',
          input:    'var(--surface-input)',
          code:     'var(--surface-code)',
        },
        // ── Text ──────────────────────────────────────────
        tx: {
          primary:   'var(--text-primary)',
          secondary: 'var(--text-secondary)',
          muted:     'var(--text-muted)',
          inverted:  'var(--text-inverted)',
        },
        // ── Accent (single brand color) ──────────────────
        accent: {
          DEFAULT:      '#16A34A',
          primary:      '#16A34A',
          hover:        '#15803D',
          active:       '#166534',
          subtle:       'var(--accent-subtle)',
          'subtle-hover': 'var(--accent-subtle-hover)',
        },
        // ── Status (functional only) ─────────────────────
        status: {
          success:        '#22C55E',
          'success-subtle': 'var(--status-success-subtle)',
          error:          '#EF4444',
          'error-subtle':   'var(--status-error-subtle)',
          warning:        '#F59E0B',
          'warning-subtle': 'var(--status-warning-subtle)',
        },
        // ── Borders ──────────────────────────────────────
        border: {
          DEFAULT:  'var(--border-default)',
          subtle:   'var(--border-subtle)',
          focus:    '#16A34A',
        },
        // ── Legacy compatibility (keep primary/dark for existing code) ──
        dark: {
          DEFAULT: 'var(--surface-page)',
          50:  'var(--surface-hover)',
          100: 'var(--surface-hover)',
          200: 'var(--surface-card)',
          300: 'var(--surface-card)',
          400: 'var(--surface-page)',
          500: 'var(--surface-page)',
          600: 'var(--surface-page)',
        },
        primary: {
          50:  '#F0FDF4',
          100: '#DCFCE7',
          200: '#BBF7D0',
          300: '#86EFAC',
          400: '#4ADE80',
          500: '#22C55E',
          600: '#16A34A',
          700: '#15803D',
          800: '#166534',
          900: '#14532D',
          950: '#052E16',
        },
        success: { 50: '#F0FDF4', 500: '#22C55E', 600: '#16A34A', 700: '#15803D' },
        warning: { 50: '#FFFBEB', 500: '#F59E0B', 600: '#D97706', 700: '#B45309' },
        danger:  { 50: '#FEF2F2', 500: '#EF4444', 600: '#DC2626', 700: '#B91C1C' },
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
        mono: ['JetBrains Mono', 'monospace'],
      },
      borderRadius: {
        card:    '10px',
        element: '6px',
        pill:    '9999px',
      },
      boxShadow: {
        float:   'var(--shadow-float)',
        tooltip: 'var(--shadow-tooltip)',
        none:    'none',
      },
      spacing: {
        'section': '32px',
        'card-inner': '20px',
        'card-gap': '16px',
      },
      transitionDuration: {
        fast:   '120ms',
        normal: '200ms',
        slow:   '350ms',
      },
      animation: {
        'fade-in':    'fadeIn 0.2s ease-out',
        'slide-up':   'slideUp 0.3s ease-out',
        'slide-down': 'slideDown 0.3s ease-out',
        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
        'node-pulse': 'nodePulse 2.5s ease-in-out infinite',
      },
      keyframes: {
        fadeIn: {
          '0%':   { opacity: '0' },
          '100%': { opacity: '1' },
        },
        slideUp: {
          '0%':   { transform: 'translateY(8px)', opacity: '0' },
          '100%': { transform: 'translateY(0)', opacity: '1' },
        },
        slideDown: {
          '0%':   { transform: 'translateY(-8px)', opacity: '0' },
          '100%': { transform: 'translateY(0)', opacity: '1' },
        },
        nodePulse: {
          '0%, 100%': { opacity: '0.8', transform: 'scale(1)' },
          '50%':      { opacity: '1', transform: 'scale(1.15)' },
        },
      },
    },
  },
  plugins: [],
};
