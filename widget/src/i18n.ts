import type { WidgetTranslations } from './types';

const translations: Record<string, WidgetTranslations> = {
  en: {
    pay: 'Pay',
    payAmount: 'Pay {amount}',
    processing: 'Processing...',
    noMethods: 'No payment methods available',
    redirecting: 'Redirecting to payment provider...',
    paymentSucceeded: 'Payment Succeeded',
    error: 'Error',
  },
  ru: {
    pay: 'Оплатить',
    payAmount: 'Оплатить {amount}',
    processing: 'Обработка...',
    noMethods: 'Нет доступных методов оплаты',
    redirecting: 'Перенаправление на платёжный провайдер...',
    paymentSucceeded: 'Оплата прошла успешно',
    error: 'Ошибка',
  },
};

export function getTranslations(
  locale?: string,
  overrides?: Partial<WidgetTranslations>,
): WidgetTranslations {
  const base = translations[locale ?? 'en'] ?? translations.en;
  return overrides ? { ...base, ...overrides } : base;
}
