import { describe, it, expect, vi, beforeEach } from 'vitest';
import { loadPayswitch } from './index';

beforeEach(() => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
    ok: true,
    json: () => Promise.resolve({ data: { attributes: { status: 'requires_payment_method', amount: 1000, currency: 'RUB' } } }),
  }));
});

describe('loadPayswitch', () => {
  it('returns a PayswitchInstance', async () => {
    const ps = await loadPayswitch('pk_test_xxx');
    expect(ps).toBeDefined();
    expect(ps.widgets).toBeTypeOf('function');
    expect(ps.confirmPayment).toBeTypeOf('function');
    expect(ps.retrievePaymentIntent).toBeTypeOf('function');
  });

  it('rejects empty publishable key', async () => {
    await expect(loadPayswitch('')).rejects.toThrow('publishableKey is required');
  });

  it('uses custom backend URL when provided', async () => {
    const ps = await loadPayswitch('pk_test_xxx', {
      customBackendUrl: 'https://custom.example.com',
    });
    expect(ps).toBeDefined();
  });
});
