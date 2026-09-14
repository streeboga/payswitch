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
      'https://api.example.com/api/v1/payments/pi_xxx',
      expect.objectContaining({
        headers: expect.objectContaining({ 'api-key': 'pk_test_xxx', 'X-Client-Secret': 'pi_xxx_secret_yyy' }),
      }),
    );
    expect(result.status).toBe('requires_payment_method');
  });

  it('getPaymentMethods parses v2 response with mode', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        data: {
          attributes: {
            mode: 'mixed',
            methods: [{ payment_method: 'card', display_name: 'Bank Card', type: 'direct', connector: 'stripe', session_type: 'redirect' }],
            connectors: [{ connector_name: 'yookassa', connector_key: 'conn_abc', display_name: 'YooKassa', logo_url: '/logo.png', session_type: 'redirect' }],
          },
        },
      }),
    });

    const result = await api.getPaymentMethods('pi_xxx', 'pi_xxx_secret_yyy', 'ru');
    const [url, opts] = mockFetch.mock.calls[0];
    expect(url).toBe('https://api.example.com/api/v1/payments/pi_xxx/payment-methods?locale=ru');
    expect(opts.headers['X-Client-Secret']).toBe('pi_xxx_secret_yyy');
    expect(result.mode).toBe('mixed');
    expect(result.methods).toHaveLength(1);
    expect(result.methods[0].payment_method).toBe('card');
    expect(result.methods[0].type).toBe('direct');
    expect(result.connectors).toHaveLength(1);
    expect(result.connectors[0].connector_name).toBe('yookassa');
  });

  it('getPaymentMethods parses legacy flat array response', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({ data: [{ payment_method: 'card' }] }),
    });

    const result = await api.getPaymentMethods('pi_xxx', 'pi_xxx_secret_yyy');
    expect(result.mode).toBe('direct_methods');
    expect(result.methods).toEqual([{ payment_method: 'card' }]);
    expect(result.connectors).toEqual([]);
  });

  it('getPaymentMethods returns none mode for empty legacy array', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({ data: [] }),
    });

    const result = await api.getPaymentMethods('pi_xxx', 'pi_xxx_secret_yyy');
    expect(result.mode).toBe('none');
    expect(result.methods).toEqual([]);
  });

  it('getPaymentMethods parses legacy JSON:API format', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        data: [
          { type: 'payment_method', id: '1', attributes: { payment_method: 'card', display_name: 'Bank Card' } },
        ],
      }),
    });

    const result = await api.getPaymentMethods('pi_xxx', 'pi_xxx_secret_yyy');
    expect(result.mode).toBe('direct_methods');
    expect(result.methods[0].payment_method).toBe('card');
    expect(result.methods[0].display_name).toBe('Bank Card');
  });

  it('confirmPayment sends connector in body', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        data: { attributes: { status: 'requires_customer_action', metadata: { type: 'redirect', redirect_url: 'https://psp.example.com/pay' } } },
      }),
    });

    const result = await api.confirmPayment('pi_xxx', {
      client_secret: 'pi_xxx_secret_yyy',
      payment_method: 'card',
      connector: 'stripe',
    });

    const [, opts] = mockFetch.mock.calls[0];
    const body = JSON.parse(opts.body);
    expect(body.client_secret).toBe('pi_xxx_secret_yyy');
    expect(body.payment_method).toBe('card');
    expect(body.connector).toBe('stripe');
    expect(result.status).toBe('requires_customer_action');
  });

  it('confirmPayment returns form_redirect metadata', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        data: {
          attributes: {
            status: 'requires_customer_action',
            metadata: {
              type: 'form_redirect',
              url: 'https://bank.example.com/3ds',
              method: 'POST',
              params: { MD: 'xxx', PaReq: 'yyy', TermUrl: 'https://example.com/return' },
            },
          },
        },
      }),
    });

    const result = await api.confirmPayment('pi_xxx', {
      client_secret: 'pi_xxx_secret_yyy',
      payment_method: 'card',
    });

    expect(result.metadata?.type).toBe('form_redirect');
    expect(result.metadata?.url).toBe('https://bank.example.com/3ds');
    expect((result.metadata?.params as Record<string, string>)?.MD).toBe('xxx');
  });

  it('confirmPayment returns qr metadata', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        data: {
          attributes: {
            status: 'requires_customer_action',
            metadata: {
              type: 'qr',
              qr_data: '<svg>...</svg>',
              format: 'svg',
              payment_id: 'qr_123',
              expires_at: '2026-03-23T12:00:00Z',
            },
          },
        },
      }),
    });

    const result = await api.confirmPayment('pi_xxx', {
      client_secret: 'pi_xxx_secret_yyy',
      payment_method: 'sbp',
    });

    expect(result.metadata?.type).toBe('qr');
    expect(result.metadata?.qr_data).toBe('<svg>...</svg>');
    expect(result.metadata?.format).toBe('svg');
  });

  it('getPaymentStatus returns status', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({ data: { attributes: { status: 'succeeded' } } }),
    });

    const result = await api.getPaymentStatus('pi_xxx', 'pi_xxx_secret_yyy');
    expect(result.status).toBe('succeeded');
    const [url, opts] = mockFetch.mock.calls[0];
    expect(url).toBe('https://api.example.com/api/v1/payments/pi_xxx/status');
    expect(opts.headers['X-Client-Secret']).toBe('pi_xxx_secret_yyy');
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
