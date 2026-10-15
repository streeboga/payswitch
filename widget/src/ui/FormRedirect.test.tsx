import { describe, it, expect, vi, afterEach } from 'vitest';
import { renderWidget } from './PaymentWidget';
import { getTranslations } from '../i18n';

/**
 * Robokassa (и любой form_redirect) открывается из виджета POST-формой.
 * Подпись и Receipt считает сервер: виджет обязан отдать значения как есть,
 * а возврат браузера (?status=success) оплатой не считать — только ответ сервера.
 */

const t = getTranslations('ru');

afterEach(() => {
  vi.restoreAllMocks();
  document.body.innerHTML = '';
  window.history.replaceState({}, '', '/');
});

function mountForm(params: Record<string, string>) {
  const container = document.createElement('div');
  document.body.appendChild(container);
  renderWidget(container, {
    payment: { status: 'requires_customer_action', amount: 10050, currency: 'RUB' },
    mode: 'connector_selection',
    methods: [],
    connectors: [],
    selectedMethod: null,
    selectedConnector: 'mca_x',
    onMethodChange: () => {},
    onConnectorChange: () => {},
    onPay: () => {},
    t,
    locale: 'ru',
    result: {
      status: 'requires_form_redirect',
      formRedirect: { url: 'https://auth.robokassa.ru/Merchant/Index.aspx', method: 'POST', params },
    },
  });
  return container;
}

describe('FormRedirect (Robokassa)', () => {
  it('posts the signed params untouched and submits once', async () => {
    const submit = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    const params = {
      MerchantLogin: 'shop',
      OutSum: '100.50',
      InvId: '42',
      SignatureValue: 'abc123',
      Receipt: '%7B%22sno%22%3A%22usn_income%22%7D',
      Shp_payment_id: 'pay_01TEST',
      Shp_currency: 'RUB',
    };

    const container = mountForm(params);
    await new Promise((r) => setTimeout(r, 10));

    const form = container.querySelector('form')!;
    expect(form.getAttribute('action')).toBe('https://auth.robokassa.ru/Merchant/Index.aspx');
    expect(form.getAttribute('method')?.toUpperCase()).toBe('POST');
    const sent = Object.fromEntries(
      Array.from(form.querySelectorAll('input')).map((i) => [i.name, i.value]),
    );
    expect(sent).toEqual(params);
    expect(submit).toHaveBeenCalledTimes(1);
  });

  it('does not treat a success-looking return query as a payment', async () => {
    vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    window.history.replaceState({}, '', '/?status=success&InvId=42&OutSum=100.50&payment_id=pay_01TEST');

    const container = mountForm({ InvId: '42' });
    await new Promise((r) => setTimeout(r, 10));

    expect(container.textContent).toContain(t.formRedirecting);
    expect(container.textContent).not.toContain(t.paymentSucceeded);
  });
});
