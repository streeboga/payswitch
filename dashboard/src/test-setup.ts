import { vi } from 'vitest'

// Global mock for react-i18next — returns keys as-is
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      if (params) {
        return Object.entries(params).reduce(
          (str, [k, v]) => str.replace(`{{${k}}}`, String(v)),
          key,
        )
      }
      return key
    },
    i18n: {
      language: 'ru',
      changeLanguage: vi.fn(),
    },
  }),
  Trans: ({ children }: { children: React.ReactNode }) => children,
  initReactI18next: { type: '3rdParty', init: vi.fn() },
}))

// Global mock for next-themes
vi.mock('next-themes', () => ({
  useTheme: () => ({ theme: 'system', setTheme: vi.fn(), resolvedTheme: 'light' }),
  ThemeProvider: ({ children }: { children: React.ReactNode }) => children,
}))

// Global mock for i18n config
vi.mock('@/lib/i18n', () => ({
  default: {},
  supportedLanguages: [
    { code: 'ru', label: 'Русский', dir: 'ltr' },
    { code: 'en', label: 'English', dir: 'ltr' },
  ],
  getLanguageDir: () => 'ltr',
}))
