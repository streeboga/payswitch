import type { PaymentIntentResponse, PaymentMethodInfo, PaymentMethodsResponse, ConnectorInfo } from './types';

export class PaymentApi {
  constructor(
    private baseUrl: string,
    private publishableKey: string,
  ) {}

  async getPayment(paymentKey: string, clientSecret: string): Promise<PaymentIntentResponse> {
    const url = `${this.baseUrl}/api/v1/payments/${paymentKey}?client_secret=${encodeURIComponent(clientSecret)}`;
    const res = await this.request(url, { method: 'GET' });
    return res.data.attributes;
  }

  async getPaymentMethods(paymentKey: string, clientSecret: string, locale?: string): Promise<PaymentMethodsResponse> {
    let url = `${this.baseUrl}/api/v1/payments/${paymentKey}/payment-methods?client_secret=${encodeURIComponent(clientSecret)}`;
    if (locale) url += `&locale=${encodeURIComponent(locale)}`;
    const res = await this.request(url, { method: 'GET' });

    // New v2 format: { data: { attributes: { mode, methods[], connectors[] } } }
    if (res.data?.attributes?.mode) {
      const attrs = res.data.attributes;
      return {
        mode: attrs.mode,
        methods: attrs.methods ?? [],
        connectors: attrs.connectors ?? [],
      };
    }

    // Legacy v1 format: { data: [{type, id, attributes: {payment_method}}] } or { data: [{payment_method}] }
    const methods: PaymentMethodInfo[] = (res.data as any[]).map((item: any) =>
      item.attributes ? item.attributes : item,
    );

    return {
      mode: methods.length > 0 ? 'direct_methods' : 'none',
      methods,
      connectors: [],
    };
  }

  async confirmPayment(
    paymentKey: string,
    body: { client_secret: string; payment_method?: string; connector?: string; [key: string]: unknown },
  ): Promise<PaymentIntentResponse> {
    const url = `${this.baseUrl}/api/v1/payments/${paymentKey}/confirm`;
    const res = await this.request(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/vnd.api+json' },
      body: JSON.stringify(body),
    });
    return res.data.attributes;
  }

  async getPaymentStatus(paymentKey: string, clientSecret: string): Promise<{ status: string }> {
    const url = `${this.baseUrl}/api/v1/payments/${paymentKey}/status?client_secret=${encodeURIComponent(clientSecret)}`;
    const res = await this.request(url, { method: 'GET' });
    return res.data?.attributes ?? res.data ?? res;
  }

  private async request(url: string, init: RequestInit = {}): Promise<any> {
    const res = await fetch(url, {
      ...init,
      headers: {
        'api-key': this.publishableKey,
        Accept: 'application/vnd.api+json',
        ...init.headers,
      },
    });

    if (!res.ok) {
      const error = await res.json().catch(() => ({}));
      throw new Error(error.errors?.[0]?.detail ?? `HTTP ${res.status}`);
    }

    return res.json();
  }
}
