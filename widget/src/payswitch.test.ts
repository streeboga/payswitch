import { describe, it, expect, vi, beforeEach } from 'vitest';
import { createPayswitchInstance } from './payswitch';

const mockFetch = vi.fn();

beforeEach(() => {
  vi.stubGlobal('fetch', mockFetch);
  mockFetch.mockReset();
});

function mockPaymentAndMethods() {
  // getPayment
  mockFetch.mockResolvedValueOnce({
    ok: true,
    json: () => Promise.resolve({
      data: { attributes: { status: 'requires_payment_method', amount: 5000, currency: 'RUB' } },
    }),
  });
  // getPaymentMethods (v2)
  mockFetch.mockResolvedValueOnce({
    ok: true,
    json: () => Promise.resolve({
      data: {
        attributes: {
          mode: 'direct_methods',
          methods: [
            { payment_method: 'card', display_name: 'Card', type: 'direct', connector: 'stripe', session_type: 'redirect' },
            { payment_method: 'sbp', display_name: 'SBP', type: 'direct', connector: 'yookassa', session_type: 'qr' },
          ],
          connectors: [],
        },
      },
    }),
  });
}

describe('createPayswitchInstance', () => {
  it('confirmPayment handles redirect type', async () => {
    const instance = createPayswitchInstance('pk_test_xxx', 'https://api.example.com');
    const widgets = instance.widgets({ clientSecret: 'pi_xxx_secret_yyy' });

    // Create and mount widget to set selected method
    const container = document.createElement('div');
    const widget = widgets.create('payment');
    mockPaymentAndMethods();
    widget.mount(container);

    // Wait for mount to complete
    await new Promise((r) => setTimeout(r, 50));

    // Confirm with redirect response
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        data: {
          attributes: {
            status: 'requires_customer_action',
            metadata: { type: 'redirect', url: 'https://stripe.com/pay/123' },
          },
        },
      }),
    });

    const result = await instance.confirmPayment({
      widgets,
      confirmParams: { return_url: 'https://example.com/return' },
      redirect: 'if_required',
    });

    expect(result.status).toBe('requires_customer_action');
    if (result.status === 'requires_customer_action') {
      expect(result.redirectUrl).toBe('https://stripe.com/pay/123');
    }
  });

  it('confirmPayment handles form_redirect type', async () => {
    const instance = createPayswitchInstance('pk_test_xxx', 'https://api.example.com');
    const widgets = instance.widgets({ clientSecret: 'pi_xxx_secret_yyy' });

    const container = document.createElement('div');
    const widget = widgets.create('payment');
    mockPaymentAndMethods();
    widget.mount(container);
    await new Promise((r) => setTimeout(r, 50));

    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        data: {
          attributes: {
            status: 'requires_customer_action',
            metadata: {
              type: 'form_redirect',
              url: 'https://bank.com/3ds',
              method: 'POST',
              params: { MD: 'abc', PaReq: 'xyz' },
            },
          },
        },
      }),
    });

    const result = await instance.confirmPayment({
      widgets,
      confirmParams: { return_url: 'https://example.com/return' },
      redirect: 'if_required',
    });

    expect(result.status).toBe('requires_form_redirect');
    if (result.status === 'requires_form_redirect') {
      expect(result.formRedirect.url).toBe('https://bank.com/3ds');
      expect(result.formRedirect.method).toBe('POST');
      expect(result.formRedirect.params).toEqual({ MD: 'abc', PaReq: 'xyz' });
    }
  });

  it('confirmPayment handles qr type', async () => {
    const instance = createPayswitchInstance('pk_test_xxx', 'https://api.example.com');
    const widgets = instance.widgets({ clientSecret: 'pi_xxx_secret_yyy' });

    const container = document.createElement('div');
    const widget = widgets.create('payment');
    mockPaymentAndMethods();
    widget.mount(container);
    await new Promise((r) => setTimeout(r, 50));

    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        data: {
          attributes: {
            status: 'requires_customer_action',
            metadata: {
              type: 'qr',
              qr_data: '<svg>qr</svg>',
              format: 'svg',
              payment_id: 'qr_abc',
              expires_at: '2026-03-23T12:00:00Z',
            },
          },
        },
      }),
    });

    const result = await instance.confirmPayment({
      widgets,
      confirmParams: { return_url: 'https://example.com/return' },
      redirect: 'if_required',
    });

    expect(result.status).toBe('requires_qr');
    if (result.status === 'requires_qr') {
      expect(result.qrData.data).toBe('<svg>qr</svg>');
      expect(result.qrData.format).toBe('svg');
      expect(result.qrData.paymentId).toBe('qr_abc');
      expect(result.qrData.expiresAt).toBe('2026-03-23T12:00:00Z');
    }
  });

  it('confirmPayment handles widget type (v2 external)', async () => {
    const instance = createPayswitchInstance('pk_test_xxx', 'https://api.example.com');
    const widgets = instance.widgets({ clientSecret: 'pi_xxx_secret_yyy' });

    const container = document.createElement('div');
    const widget = widgets.create('payment');
    mockPaymentAndMethods();
    widget.mount(container);
    await new Promise((r) => setTimeout(r, 50));

    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        data: {
          attributes: {
            status: 'requires_customer_action',
            metadata: {
              type: 'widget',
              provider: 'cloudpayments',
              script_url: 'https://widget.cloudpayments.ru/bundles/checkout.js',
              params: { publicId: 'pk_xxx', amount: 50 },
            },
          },
        },
      }),
    });

    const result = await instance.confirmPayment({
      widgets,
      confirmParams: { return_url: 'https://example.com/return' },
      redirect: 'if_required',
    });

    expect(result.status).toBe('requires_external_widget');
    if (result.status === 'requires_external_widget') {
      expect(result.externalWidget.provider).toBe('cloudpayments');
      expect(result.externalWidget.scriptUrl).toBe('https://widget.cloudpayments.ru/bundles/checkout.js');
      expect(result.externalWidget.params.publicId).toBe('pk_xxx');
    }
  });

  it('confirmPayment handles legacy widget_data format', async () => {
    const instance = createPayswitchInstance('pk_test_xxx', 'https://api.example.com');
    const widgets = instance.widgets({ clientSecret: 'pi_xxx_secret_yyy' });

    const container = document.createElement('div');
    const widget = widgets.create('payment');
    mockPaymentAndMethods();
    widget.mount(container);
    await new Promise((r) => setTimeout(r, 50));

    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        data: {
          attributes: {
            status: 'requires_customer_action',
            metadata: {
              widget_data: { script_url: 'https://cdn.example.com/widget.js', params: { id: '123' } },
            },
          },
        },
      }),
    });

    const result = await instance.confirmPayment({
      widgets,
      confirmParams: { return_url: 'https://example.com/return' },
      redirect: 'if_required',
    });

    expect(result.status).toBe('requires_widget');
    if (result.status === 'requires_widget') {
      expect(result.widgetData.script_url).toBe('https://cdn.example.com/widget.js');
    }
  });

  it('confirmPayment handles legacy redirect_url format', async () => {
    const instance = createPayswitchInstance('pk_test_xxx', 'https://api.example.com');
    const widgets = instance.widgets({ clientSecret: 'pi_xxx_secret_yyy' });

    const container = document.createElement('div');
    const widget = widgets.create('payment');
    mockPaymentAndMethods();
    widget.mount(container);
    await new Promise((r) => setTimeout(r, 50));

    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        data: {
          attributes: {
            status: 'requires_customer_action',
            metadata: { redirect_url: 'https://psp.example.com/pay' },
          },
        },
      }),
    });

    const result = await instance.confirmPayment({
      widgets,
      confirmParams: { return_url: 'https://example.com/return' },
      redirect: 'if_required',
    });

    expect(result.status).toBe('requires_customer_action');
    if (result.status === 'requires_customer_action') {
      expect(result.redirectUrl).toBe('https://psp.example.com/pay');
    }
  });

  it('confirmPayment sends connector when connector is selected', async () => {
    const instance = createPayswitchInstance('pk_test_xxx', 'https://api.example.com');
    const widgets = instance.widgets({ clientSecret: 'pi_xxx_secret_yyy' });

    const container = document.createElement('div');
    const widget = widgets.create('payment');

    // Return connector_selection mode
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        data: { attributes: { status: 'requires_payment_method', amount: 5000, currency: 'RUB' } },
      }),
    });
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        data: {
          attributes: {
            mode: 'connector_selection',
            methods: [],
            connectors: [
              { connector_name: 'stripe', connector_key: 'conn_stripe', display_name: 'Stripe', logo_url: '/stripe.png', session_type: 'redirect' },
            ],
          },
        },
      }),
    });

    widget.mount(container);
    await new Promise((r) => setTimeout(r, 50));

    // Confirm — should send connector
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        data: { attributes: { status: 'succeeded', amount: 5000, currency: 'RUB' } },
      }),
    });

    const result = await instance.confirmPayment({
      widgets,
      confirmParams: { return_url: 'https://example.com/return' },
      redirect: 'if_required',
    });

    // Check the body sent to confirm
    const confirmCall = mockFetch.mock.calls[2];
    const body = JSON.parse(confirmCall[1].body);
    expect(body.connector).toBe('conn_stripe');
    expect(result.status).toBe('succeeded');
  });
});
