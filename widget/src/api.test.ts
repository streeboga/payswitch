import { describe, it, expect, vi, beforeEach } from 'vitest';
import { PaymentApi } from './api';

const mockFetch = vi.fn();

beforeEach(() => {
  vi.stubGlobal('fetch', mockFetch);
  mockFetch.mockReset();
});

describe('PaymentApi', () => {
  const api = new PaymentApi('https://api.example.com', 'pk_test_xxx');

  it('getPayment sends correct headers and client_secret', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({ data: { attributes: { status: 'requires_payment_method', amount: 1000, currency: 'RUB' } } }),
    });

    const result = await api.getPayment('pi_xxx', 'pi_xxx_secret_yyy');
    expect(mockFetch).toHaveBeenCalledWith(
      'https://api.example.com/api/v1/payments/pi_xxx?client_secret=pi_xxx_secret_yyy',
      expect.objectContaining({
        headers: expect.objectContaining({ 'api-key': 'pk_test_xxx' }),
      }),
    );
    expect(result.status).toBe('requires_payment_method');
  });

  it('getPaymentMethods returns array of methods', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({ data: [{ payment_method: 'card' }] }),
    });

    const result = await api.getPaymentMethods('pi_xxx', 'pi_xxx_secret_yyy');
    expect(result).toEqual([{ payment_method: 'card' }]);
  });

  it('confirmPayment sends client_secret in body', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        data: { attributes: { status: 'requires_customer_action', metadata: { redirect_url: 'https://psp.example.com/pay' } } },
      }),
    });

    const result = await api.confirmPayment('pi_xxx', {
      client_secret: 'pi_xxx_secret_yyy',
      payment_method: 'card',
    });

    const [, opts] = mockFetch.mock.calls[0];
    const body = JSON.parse(opts.body);
    expect(body.client_secret).toBe('pi_xxx_secret_yyy');
    expect(body.payment_method).toBe('card');
    expect(result.status).toBe('requires_customer_action');
  });

  it('throws on non-ok response', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: false,
      status: 403,
      json: () => Promise.resolve({ errors: [{ detail: 'Invalid client_secret' }] }),
    });

    await expect(api.getPayment('pi_xxx', 'bad_secret')).rejects.toThrow('Invalid client_secret');
  });
});
