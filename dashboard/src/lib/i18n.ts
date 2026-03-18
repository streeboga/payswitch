import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import LanguageDetector from 'i18next-browser-languagedetector'

export const supportedLanguages = [
  { code: 'ru', label: 'Русский', dir: 'ltr' },
  { code: 'en', label: 'English', dir: 'ltr' },
] as const

export type LanguageCode = (typeof supportedLanguages)[number]['code']

export function getLanguageDir(lng: string): 'ltr' | 'rtl' {
  return supportedLanguages.find((l) => l.code === lng)?.dir ?? 'ltr'
}

// Detect locale before initialising so we can eager-load only what's needed.
const detectedLng =
  (localStorage.getItem('payswitch-language') ?? navigator.language.split('-')[0]) === 'en'
    ? 'en'
    : 'ru'

async function initI18n() {
  // Dynamically import only the detected locale bundle.
  const eagerModule =
    detectedLng === 'en'
      ? await import('@/locales/en.json')
      : await import('@/locales/ru.json')

  const eagerResources = {
    [detectedLng]: { translation: eagerModule.default },
  }

  await i18n
    .use(LanguageDetector)
    .use(initReactI18next)
    .init({
      resources: eagerResources,
      supportedLngs: supportedLanguages.map((l) => l.code),
      fallbackLng: 'ru',
      interpolation: {
        escapeValue: false,
      },
      detection: {
        order: ['localStorage', 'navigator'],
        lookupLocalStorage: 'payswitch-language',
        caches: ['localStorage'],
        convertDetectedLanguage: (lng: string) => lng.split('-')[0]!,
      },
    })

  // Lazy-load the other locale in the background so it's ready when the
  // user switches language at runtime.
  if (detectedLng === 'en') {
    import('@/locales/ru.json').then(({ default: ruTranslation }) => {
      i18n.addResourceBundle('ru', 'translation', ruTranslation, true, false)
    })
  } else {
    import('@/locales/en.json').then(({ default: enTranslation }) => {
      i18n.addResourceBundle('en', 'translation', enTranslation, true, false)
    })
  }
}

void initI18n()

export default i18n
