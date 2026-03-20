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

/** Fallback display names when server doesn't provide display_name */
const methodNames: Record<string, Record<string, string>> = {
  en: {
    card: 'Bank Card',
    bank_transfer: 'Bank Transfer',
    sbp: 'SBP',
    qr_code: 'QR Code',
    apple_pay: 'Apple Pay',
    google_pay: 'Google Pay',
    sberbank: 'SberPay',
    tinkoff_bank: 'T-Pay',
    yoo_money: 'YooMoney',
    mir_pay: 'Mir Pay',
  },
  ru: {
    card: 'Банковская карта',
    bank_transfer: 'Банковский перевод',
    sbp: 'СБП',
    qr_code: 'QR-код',
    apple_pay: 'Apple Pay',
    google_pay: 'Google Pay',
    sberbank: 'SberPay',
    tinkoff_bank: 'T-Pay',
    yoo_money: 'ЮMoney',
    mir_pay: 'Mir Pay',
  },
};

export function getTranslations(
  locale?: string,
  overrides?: Partial<WidgetTranslations>,
): WidgetTranslations {
  const base = translations[locale ?? 'en'] ?? translations.en;
  return overrides ? { ...base, ...overrides } : base;
}

/** Get localized display name for a payment method (fallback when server doesn't provide one) */
export function getMethodDisplayName(method: string, locale?: string): string {
  const names = methodNames[locale ?? 'en'] ?? methodNames.en;
  return names[method] ?? method;
}
