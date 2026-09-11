import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderWidget } from './PaymentWidget';
import { getTranslations } from '../i18n';
import { createPayswitchInstance } from '../payswitch';
import type { PaymentWidgetProps } from './PaymentWidget';

/**
 * Окно провайдера не должно оставлять плательщика в тупике.
 *
 * Ровно та поломка, что видел владелец: крутилка без предела, без кнопки и без
 * объяснения, потому что колбэки CloudPayments уходили в null, а у ожидания не
 * было срока.
 */

const t = getTranslations('ru');

function mount(overrides: Partial<PaymentWidgetProps> = {}): HTMLElement {
  const container = document.createElement('div');
  document.body.appendChild(container);

  renderWidget(container, {
    payment: { status: 'requires_payment_method', amount: 25000, currency: 'RUB' },
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
      status: 'requires_external_widget',
      externalWidget: {
        provider: 'cloudpayments',
        scriptUrl: 'https://widget.cloudpayments.ru/bundles/cloudpayments.js',
        params: { publicId: 'pk_test', amount: 250, currency: 'RUB', invoiceId: 'pay_x' },
      },
    },
    ...overrides,
  });

  return container;
}

/** Подсовываем window.cp, который ведёт себя как настоящий: зовёт колбэки. */
function stubCloudPayments(behaviour: 'success' | 'dismiss' | 'never_opens') {
  const pay = vi.fn((_type: string, _opts: unknown, cb: Record<string, (x?: unknown) => void>) => {
    if (behaviour === 'success') cb.onSuccess();
    if (behaviour === 'dismiss') cb.onComplete({ success: false });
  });

  if (behaviour === 'never_opens') {
    // Скрипт «загрузился», объекта нет — самый коварный случай.
    delete (window as unknown as Record<string, unknown>).cp;
  } else {
    (window as unknown as Record<string, unknown>).cp = { CloudPayments: function () { return { pay }; } };
  }

  return pay;
}

/**
 * Скрипт провайдера в тестах подменяется инертным узлом.
 *
 * Настоящий <script> в happy-dom сам пытается сходить в сеть и сам поднимает
 * error — тест начинает мерить его поведение, а не наше. Инертный узел даёт
 * ровно один источник события: тот, который проверяем. 'silent' не зовёт
 * ничего — так проверяется предел ожидания.
 */
function stubScriptLoading(mode: 'load' | 'error' | 'silent') {
  const orig = document.createElement.bind(document);
  vi.spyOn(document, 'createElement').mockImplementation((tag: string) => {
    if (tag !== 'script') return orig(tag);

    const el = orig('div') as unknown as HTMLScriptElement;
    if (mode !== 'silent') {
      queueMicrotask(() => {
        if (mode === 'load') el.onload?.(new Event('load'));
        else el.onerror?.(new Event('error'));
      });
    }
    return el;
  });
}

const flush = () => new Promise((r) => setTimeout(r, 0));

beforeEach(() => {
  document.body.innerHTML = '';
  document.getElementById('cp-widget-script')?.remove();
  delete (window as unknown as Record<string, unknown>).cp;
});

afterEach(() => {
  vi.restoreAllMocks();
  vi.useRealTimers();
});

describe('ExternalPspWidget', () => {
  it('успех у провайдера доходит до виджета', async () => {
    stubScriptLoading('load');
    stubCloudPayments('success');
    const onExternalSuccess = vi.fn();

    mount({ onExternalSuccess });
    await flush();

    expect(onExternalSuccess).toHaveBeenCalledTimes(1);
  });

  it('закрытое окно возвращает к выбору способа, а не в тупик', async () => {
    stubScriptLoading('load');
    stubCloudPayments('dismiss');
    const onExternalDismissed = vi.fn();

    mount({ onExternalDismissed });
    await flush();

    expect(onExternalDismissed).toHaveBeenCalledTimes(1);
  });

  it('о выходе из окна виджет сообщает наружу: интент уже не переиспользовать', async () => {
    stubScriptLoading('load');
    stubCloudPayments('dismiss');

    const seen: string[] = [];
    const instance = createPayswitchInstance('pk_test', 'https://api.example.com');
    const widget = instance.widgets({ clientSecret: 'pay_x_secret_y' }).create('payment');
    widget.on('cancel', () => seen.push('cancel'));

    // Дёргаем тот же путь, что и настоящее закрытие окна провайдера.
    (widget as unknown as { result: unknown; container: HTMLElement }).container =
      document.createElement('div');
    (widget as unknown as { handleExternalDismissed: () => void }).handleExternalDismissed();

    expect(seen).toEqual(['cancel']);
  });

  it('скрипт провайдера не загрузился — сообщение и выход, а не крутилка', async () => {
    stubScriptLoading('error');

    const c = mount();
    await flush();

    expect(c.textContent).toContain(t.externalWidgetFailed);
    expect(c.textContent).toContain(t.chooseAnotherMethod);
  });

  it('скрипт есть, объекта провайдера нет — тоже сообщение', async () => {
    stubScriptLoading('load');
    stubCloudPayments('never_opens');

    const c = mount();
    await flush();

    expect(c.textContent).toContain(t.externalWidgetFailed);
  });

  it('у ожидания есть предел: провайдер молчит — виджет сдаётся', async () => {
    vi.useFakeTimers();
    // Скрипт не отвечает ни onload, ни onerror — ровно тот случай, что раньше
    // давал вечную крутилку.
    stubScriptLoading('silent');
    const c = mount();
    await vi.advanceTimersByTimeAsync(15001);

    expect(c.textContent).toContain(t.externalWidgetFailed);
  });
});
