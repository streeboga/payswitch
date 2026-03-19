import type { PaymentIntentResponse, PaymentMethodInfo } from './types';

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

  async getPaymentMethods(paymentKey: string, clientSecret: string): Promise<PaymentMethodInfo[]> {
    const url = `${this.baseUrl}/api/v1/payments/${paymentKey}/payment-methods?client_secret=${encodeURIComponent(clientSecret)}`;
    const res = await this.request(url, { method: 'GET' });
    return res.data;
  }

  async confirmPayment(
    paymentKey: string,
    body: { client_secret: string; payment_method: string; [key: string]: unknown },
  ): Promise<PaymentIntentResponse> {
    const url = `${this.baseUrl}/api/v1/payments/${paymentKey}/confirm`;
    const res = await this.request(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/vnd.api+json' },
      body: JSON.stringify(body),
    });
    return res.data.attributes;
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
