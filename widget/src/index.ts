import { createPayswitchInstance } from './payswitch';
import type { PayswitchInstance, LoadOptions } from './types';

export type {
  PayswitchInstance,
  LoadOptions,
  WidgetOptions,
  WidgetCollection,
  PaymentWidget,
  ConfirmPaymentParams,
  ConfirmPaymentResult,
  PaymentIntentResponse,
  WidgetTranslations,
} from './types';

const DEFAULT_URLS: Record<string, string> = {
  sandbox: '',
  production: '',
};

export async function loadPayswitch(
  publishableKey: string,
  options?: LoadOptions,
): Promise<PayswitchInstance> {
  if (!publishableKey) {
    throw new Error('publishableKey is required');
  }

  const env = options?.env ?? (publishableKey.startsWith('pk_prd_') ? 'production' : 'sandbox');
  const baseUrl = options?.customBackendUrl ?? DEFAULT_URLS[env] ?? '';

  return createPayswitchInstance(publishableKey, baseUrl);
}
