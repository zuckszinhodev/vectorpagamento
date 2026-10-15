// Theme definitions for the platform
// Two themes: Classic and Modern
// Color values use space-separated RGB format ("R G B") for Tailwind opacity modifier support.

export type ThemeVariant = 'classic' | 'modern';

export interface ThemeColors {
  /** Space-separated RGB values, e.g. "250 245 255" */
  primary: Record<number, string>;
  accent: Record<number, string>;
  dark: {
    DEFAULT: string;
    50: string;
    100: string;
    200: string;
    300: string;
    400: string;
    500: string;
  };
}

export interface ThemeDefinition {
  id: ThemeVariant;
  name: string;
  description: string;
  /** Hex colors for preview cards in the admin UI */
  preview: {
    primary: string;
    accent: string;
    bg: string;
  };
  colors: ThemeColors;
  cssVars: {
    light: Record<string, string>;
    dark: Record<string, string>;
  };
}

export const themes: Record<ThemeVariant, ThemeDefinition> = {
  classic: {
    id: 'classic',
    name: 'Clássica',
    description: 'Tema principal da VectorPagamentos com foco em tons de verde. Interface consolidada e familiar.',
    preview: { primary: '#16A34A', accent: '#10B981', bg: '#ECFDF5' },
    colors: {
      primary: {
        50: '240 253 244', 100: '220 252 231', 200: '187 247 208',
        300: '134 239 172', 400: '74 222 128', 500: '34 197 94',
        600: '22 163 74',   700: '21 128 61',   800: '22 101 52',
        900: '20 83 45',    950: '5 46 22',
      },
      accent: {
        50: '236 253 245', 100: '209 250 229', 200: '167 243 208',
        300: '110 231 183', 400: '52 211 153',  500: '16 185 129',
        600: '5 150 105',   700: '4 120 87',    800: '6 95 70',
        900: '6 78 59',     950: '2 44 34',
      },
      dark: {
        DEFAULT: '10 10 10', 50: '38 38 38', 100: '32 32 32',
        200: '24 24 24', 300: '20 20 20', 400: '14 14 14', 500: '10 10 10',
      },
    },
    cssVars: {
      light: {
        '--theme-primary': '16, 185, 129',
        '--theme-primary-hover': '5, 150, 105',
        '--theme-accent': '52, 211, 153',
        '--theme-card-accent': '16, 185, 129',
        '--theme-sidebar-active': '16, 185, 129',
        '--theme-gradient-from': '16, 185, 129',
        '--theme-gradient-to': '52, 211, 153',
      },
      dark: {
        '--theme-primary': '16, 185, 129',
        '--theme-primary-hover': '5, 150, 105',
        '--theme-accent': '52, 211, 153',
        '--theme-card-accent': '16, 185, 129',
        '--theme-sidebar-active': '52, 211, 153',
        '--theme-gradient-from': '16, 185, 129',
        '--theme-gradient-to': '52, 211, 153',
      },
    },
  },

  modern: {
    id: 'modern',
    name: 'Moderna',
    description: 'Interface dark premium com destaque em vermelho. Visual moderno estilo dashboard financeiro.',
    preview: { primary: '#ef4444', accent: '#dc2626', bg: '#0a0a0a' },
    colors: {
      primary: {
        50: '254 242 242', 100: '254 226 226', 200: '254 202 202',
        300: '252 165 165', 400: '248 113 113', 500: '239 68 68',
        600: '220 38 38',   700: '185 28 28',   800: '153 27 27',
        900: '127 29 29',   950: '69 10 10',
      },
      accent: {
        50: '255 241 242', 100: '255 228 230', 200: '254 205 211',
        300: '253 164 175', 400: '251 113 133', 500: '244 63 94',
        600: '225 29 72',   700: '190 18 60',   800: '159 18 57',
        900: '136 19 55',   950: '76 5 25',
      },
      dark: {
        DEFAULT: '10 10 12', 50: '30 30 34', 100: '20 20 23',
        200: '16 16 19', 300: '14 14 16', 400: '12 12 14', 500: '10 10 12',
      },
    },
    cssVars: {
      light: {
        '--theme-primary': '239, 68, 68',
        '--theme-primary-hover': '220, 38, 38',
        '--theme-accent': '244, 63, 94',
        '--theme-card-accent': '239, 68, 68',
        '--theme-sidebar-active': '239, 68, 68',
        '--theme-gradient-from': '239, 68, 68',
        '--theme-gradient-to': '220, 38, 38',
      },
      dark: {
        '--theme-primary': '239, 68, 68',
        '--theme-primary-hover': '220, 38, 38',
        '--theme-accent': '244, 63, 94',
        '--theme-card-accent': '239, 68, 68',
        '--theme-sidebar-active': '239, 68, 68',
        '--theme-gradient-from': '239, 68, 68',
        '--theme-gradient-to': '220, 38, 38',
      },
    },
  },
};

export const themeList = Object.values(themes);

// Helper to apply theme CSS variables to document
export function applyThemeCssVars(themeVariant: ThemeVariant, mode: 'light' | 'dark') {
  const theme = themes[themeVariant];
  if (!theme) return;

  const vars = mode === 'dark' ? theme.cssVars.dark : theme.cssVars.light;
  const root = document.documentElement;

  // Apply CSS variables
  Object.entries(vars).forEach(([key, value]) => {
    root.style.setProperty(key, value);
  });

  // Also set the primary color CSS var used by existing components
  root.style.setProperty('--primary', vars['--theme-primary']);
}

// Helper to generate Tailwind-compatible color classes
export function getThemeColorClasses(themeVariant: ThemeVariant) {
  const theme = themes[themeVariant];
  return {
    primary: theme.colors.primary,
    accent: theme.colors.accent,
  };
}

// Convert space-separated RGB to hex for display purposes
export function rgbToHex(rgb: string): string {
  const parts = rgb.split(' ').map(Number);
  if (parts.length !== 3) return '#000000';
  return '#' + parts.map(n => n.toString(16).padStart(2, '0')).join('');
}
