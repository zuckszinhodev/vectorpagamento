// Theme definitions for the platform
// Two themes: Classic (current emerald) and Modern (new design to be built)
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
    description: 'O tema original Vector Pagamentos com tons de verde elegante. Interface consolidada e familiar.',
    preview: { primary: '#16a34a', accent: '#10b981', bg: '#f0fdf4' },
    colors: {
      primary: {
        50: '240 253 244', 100: '220 252 231', 200: '187 247 208',
        300: '134 239 172', 400: '74 222 128', 500: '34 197 94',
        600: '22 163 74',  700: '21 128 61',  800: '22 101 52',
        900: '20 83 45',   950: '5 46 22',
      },
      accent: {
        50: '236 253 245', 100: '209 250 229', 200: '167 243 208',
        300: '110 231 183', 400: '52 211 153', 500: '16 185 129',
        600: '5 150 105',   700: '4 120 87',   800: '6 95 70',
        900: '6 78 59',    950: '2 44 34',
      },
      dark: {
        DEFAULT: '10 10 10', 50: '38 38 38', 100: '32 32 32',
        200: '24 24 24', 300: '20 20 20', 400: '14 14 14', 500: '10 10 10',
      },
    },
    cssVars: {
      light: {
        '--theme-primary': '34, 197, 94',
        '--theme-primary-hover': '22, 163, 74',
        '--theme-accent': '16, 185, 129',
        '--theme-card-accent': '34, 197, 94',
        '--theme-sidebar-active': '34, 197, 94',
        '--theme-gradient-from': '34, 197, 94',
        '--theme-gradient-to': '16, 185, 129',
      },
      dark: {
        '--theme-primary': '34, 197, 94',
        '--theme-primary-hover': '22, 163, 74',
        '--theme-accent': '16, 185, 129',
        '--theme-card-accent': '34, 197, 94',
        '--theme-sidebar-active': '74, 222, 128',
        '--theme-gradient-from': '34, 197, 94',
        '--theme-gradient-to': '16, 185, 129',
      },
    },
  },

  modern: {
    id: 'modern',
    name: 'Moderna',
    description: 'Nova interface moderna e minimalista. Design clean com visual atualizado.',
    preview: { primary: '#22c55e', accent: '#06b6d4', bg: '#f8fafc' },
    colors: {
      primary: {
        50: '240 253 244', 100: '220 252 231', 200: '187 247 208',
        300: '134 239 172', 400: '74 222 128', 500: '34 197 94',
        600: '22 163 74',   700: '21 128 61',   800: '22 101 52',
        900: '20 83 45',    950: '5 46 22',
      },
      accent: {
        50: '236 254 255', 100: '207 250 254', 200: '165 243 252',
        300: '103 232 249', 400: '34 211 238',  500: '6 182 212',
        600: '8 145 178',   700: '14 116 144',  800: '21 94 117',
        900: '22 78 99',    950: '8 51 68',
      },
      dark: {
        DEFAULT: '15 23 42', 50: '51 65 85', 100: '30 41 59',
        200: '24 33 50', 300: '20 28 44', 400: '17 24 39', 500: '15 23 42',
      },
    },
    cssVars: {
      light: {
        '--theme-primary': '34, 197, 94',
        '--theme-primary-hover': '22, 163, 74',
        '--theme-accent': '6, 182, 212',
        '--theme-card-accent': '34, 197, 94',
        '--theme-sidebar-active': '34, 197, 94',
        '--theme-gradient-from': '34, 197, 94',
        '--theme-gradient-to': '6, 182, 212',
      },
      dark: {
        '--theme-primary': '74, 222, 128',
        '--theme-primary-hover': '34, 197, 94',
        '--theme-accent': '34, 211, 238',
        '--theme-card-accent': '74, 222, 128',
        '--theme-sidebar-active': '74, 222, 128',
        '--theme-gradient-from': '74, 222, 128',
        '--theme-gradient-to': '34, 211, 238',
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
