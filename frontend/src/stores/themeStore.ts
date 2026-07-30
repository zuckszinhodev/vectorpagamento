import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import { type ThemeVariant, themes, applyThemeCssVars } from '@/config/themes';

type Theme = 'light' | 'dark';

interface ThemeState {
  theme: Theme;
  themeVariant: ThemeVariant;
  primaryColor: string;
  platformName: string;
  platformDescription: string;
  logoUrl: string;
  setTheme: (theme: Theme) => void;
  toggleTheme: () => void;
  setThemeVariant: (variant: ThemeVariant) => void;
  setPrimaryColor: (color: string) => void;
  setPlatformName: (name: string) => void;
  setPlatformDescription: (desc: string) => void;
  setLogoUrl: (url: string) => void;
  applyPrimaryColor: (color: string) => void;
  applyThemeVariant: (variant: ThemeVariant, mode?: Theme) => void;
}

// Função para converter hex para RGB
function hexToRgb(hex: string): string {
  const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
  if (result) {
    return `${parseInt(result[1], 16)}, ${parseInt(result[2], 16)}, ${parseInt(result[3], 16)}`;
  }
  return '16, 185, 129'; // fallback verde
}

// Apply Tailwind color overrides for the selected theme
function applyTailwindColors(variant: ThemeVariant) {
  const theme = themes[variant];
  if (!theme) return;

  const root = document.documentElement;
  const { primary, accent } = theme.colors;

  // Set CSS custom properties for each primary shade
  Object.entries(primary).forEach(([shade, value]) => {
    root.style.setProperty(`--color-primary-${shade}`, value);
  });
  Object.entries(accent).forEach(([shade, value]) => {
    root.style.setProperty(`--color-accent-${shade}`, value);
  });

  // Set dark mode background colors
  const { dark } = theme.colors;
  root.style.setProperty('--color-dark-bg', dark.DEFAULT);
  root.style.setProperty('--color-dark-50', dark[50]);
  root.style.setProperty('--color-dark-100', dark[100]);
  root.style.setProperty('--color-dark-200', dark[200]);
  root.style.setProperty('--color-dark-300', dark[300]);
  root.style.setProperty('--color-dark-400', dark[400]);
  root.style.setProperty('--color-dark-500', dark[500]);
}

export const useThemeStore = create<ThemeState>()(
  persist(
    (set, get) => ({
      theme: 'light',
      themeVariant: 'classic' as ThemeVariant,
      primaryColor: '#16a34a',
      platformName: 'VectorPagamentos',
      platformDescription: 'Gateway de pagamentos',
      logoUrl: '',
      setTheme: (theme: Theme) => {
        set({ theme });
        // Reapply theme variant CSS vars for the new mode
        const variant = get().themeVariant;
        applyThemeCssVars(variant, theme);
        applyTailwindColors(variant);
      },
      toggleTheme: () => {
        const newTheme = get().theme === 'dark' ? 'light' : 'dark';
        set({ theme: newTheme });
        const variant = get().themeVariant;
        applyThemeCssVars(variant, newTheme);
        applyTailwindColors(variant);
      },
      setThemeVariant: (variant: ThemeVariant) => {
        const theme = themes[variant];
        if (!theme) return;
        set({ themeVariant: variant, primaryColor: theme.preview.primary });
        get().applyThemeVariant(variant, get().theme);
      },
      setPrimaryColor: (color: string) => {
        set({ primaryColor: color });
        get().applyPrimaryColor(color);
      },
      setPlatformName: (name: string) => set({ platformName: name }),
      setPlatformDescription: (desc: string) => set({ platformDescription: desc }),
      setLogoUrl: (url: string) => set({ logoUrl: url }),
      applyPrimaryColor: (color: string) => {
        const rgb = hexToRgb(color);
        document.documentElement.style.setProperty('--primary', rgb);
      },
      applyThemeVariant: (variant: ThemeVariant, mode?: Theme) => {
        const currentMode = mode || get().theme;
        applyThemeCssVars(variant, currentMode);
        applyTailwindColors(variant);
      },
    }),
    {
      name: 'vectorpagamentos-theme',
      onRehydrateStorage: () => (state) => {
        // Aplicar tema quando o store for hidratado
        if (state) {
          const variant = state.themeVariant || 'classic';
          const mode = state.theme || 'light';
          applyThemeCssVars(variant, mode);
          applyTailwindColors(variant);
          if (state.primaryColor) {
            state.applyPrimaryColor(state.primaryColor);
          }
        }
      },
    }
  )
);
