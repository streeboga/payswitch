import { PaymentApi } from './api';
import { getTranslations } from './i18n';
import { renderWidget, unmountWidget } from './ui/PaymentWidget';
import type {
  PayswitchInstance,
  WidgetOptions,
  WidgetCollection,
  PaymentWidget,
  ConfirmPaymentParams,
  ConfirmPaymentResult,
  PaymentIntentResponse,
  PaymentMethodInfo,
  WidgetTranslations,
  WidgetEvent,
  WidgetEventHandler,
} from './types';

function extractPaymentKey(clientSecret: string): string {
  const idx = clientSecret.indexOf('_secret_');
  if (idx === -1) throw new Error('Invalid client_secret format');
  return clientSecret.substring(0, idx);
}

class PaymentWidgetImpl implements PaymentWidget {
  private container: HTMLElement | null = null;
  private listeners: Map<WidgetEvent, Set<WidgetEventHandler>> = new Map();
  private selectedMethod: string | null = null;
  private methods: PaymentMethodInfo[] = [];
  private payment: PaymentIntentResponse | null = null;
  private confirming = false;
  private result: { status: string; redirectUrl?: string; error?: string; widgetData?: import('./types').WidgetData } | null = null;
  private destroyed = false;
  private parentInstance: PayswitchInstance | null = null;
  private parentWidgets: WidgetCollection | null = null;
  private locale: string | undefined;
  private t: WidgetTranslations;

  constructor(
    private api: PaymentApi,
    private clientSecret: string,
    locale: string | undefined,
    translations: Partial<WidgetTranslations> | undefined,
    private _options?: Record<string, unknown>,
  ) {
    this.locale = locale;
    this.t = getTranslations(locale, translations);
  }

  setParent(instance: PayswitchInstance, widgets: WidgetCollection): void {
    this.parentInstance = instance;
    this.parentWidgets = widgets;
  }

  mount(selector: string | HTMLElement): void {
    if (this.destroyed) throw new Error('Widget has been destroyed');

    this.container =
      typeof selector === 'string' ? document.querySelector<HTMLElement>(selector) : selector;

    if (!this.container) {
      throw new Error(`Element not found: ${selector}`);
    }

    this.render({ loading: true });

    const paymentKey = extractPaymentKey(this.clientSecret);

    // Load payment info and methods in parallel
    Promise.all([
      this.api.getPayment(paymentKey, this.clientSecret),
      this.api.getPaymentMethods(paymentKey, this.clientSecret, this.locale),
    ])
      .then(([payment, methods]) => {
        if (this.destroyed || !this.container) return;
        this.payment = payment;
        this.methods = methods;
        if (methods.length > 0) {
          this.selectedMethod = methods[0].payment_method;
        }
        this.render();
        this.emit('ready', {});
      })
      .catch((err) => {
        if (this.destroyed || !this.container) return;
        this.render({ error: err.message });
        this.emit('error', { error: err.message });
      });
  }

  unmount(): void {
    if (this.container) {
      unmountWidget(this.container);
      this.container = null;
    }
  }

  destroy(): void {
    this.unmount();
    this.listeners.clear();
    this.destroyed = true;
  }

  on(event: WidgetEvent, handler: WidgetEventHandler): void {
    if (!this.listeners.has(event)) {
      this.listeners.set(event, new Set());
    }
    this.listeners.get(event)!.add(handler);
  }

  update(_options: Record<string, unknown>): void {
    this.render();
  }

  getSelectedMethod(): string | null {
    return this.selectedMethod;
  }

  private async handlePay(): Promise<void> {
    if (!this.parentInstance || !this.parentWidgets || !this.selectedMethod) return;

    this.confirming = true;
    this.render();

    const result = await this.parentInstance.confirmPayment({
      widgets: this.parentWidgets,
      confirmParams: { return_url: window.location.href },
      redirect: 'if_required', // Don't auto-redirect, show result in widget
    });

    this.confirming = false;

    if (result.status === 'requires_widget') {
      this.result = { status: 'requires_widget', widgetData: result.widgetData };
      this.render();
      this.emit('change', { status: 'requires_widget', widgetData: result.widgetData });
    } else if (result.status === 'requires_customer_action') {
      this.result = { status: 'requires_customer_action', redirectUrl: result.redirectUrl };
      this.render();
      this.emit('redirect', { url: result.redirectUrl });
      // Auto-redirect after brief delay
      setTimeout(() => {
        if (result.status === 'requires_customer_action') {
          window.location.href = result.redirectUrl;
        }
      }, 2000);
    } else if (result.status === 'succeeded') {
      this.result = { status: 'succeeded' };
      this.render();
    } else if (result.status === 'error') {
      this.result = { status: 'error', error: result.error.message };
      this.render();
      this.emit('error', result.error);
    }
  }

  private render(overrides?: { loading?: boolean; error?: string | null }): void {
    if (!this.container) return;
    renderWidget(this.container, {
      payment: this.payment,
      methods: this.methods,
      selectedMethod: this.selectedMethod,
      onMethodChange: (method) => {
        this.selectedMethod = method;
        this.render();
        this.emit('change', { paymentMethod: method });
      },
      onPay: () => this.handlePay(),
      confirming: this.confirming,
      result: this.result,
      loading: overrides?.loading,
      error: overrides?.error,
      t: this.t,
      locale: this.locale,
    });
  }

  private emit(event: WidgetEvent, data: unknown): void {
    const handlers = this.listeners.get(event);
    if (handlers) {
      handlers.forEach((handler) => handler(data));
    }
  }
}

class WidgetCollectionImpl implements WidgetCollection {
  private elements: Map<string, PaymentWidgetImpl> = new Map();
  private parentInstance: PayswitchInstance | null = null;

  constructor(
    private api: PaymentApi,
    private options: WidgetOptions,
  ) {}

  setParent(instance: PayswitchInstance): void {
    this.parentInstance = instance;
  }

  create(type: 'payment', options?: Record<string, unknown>): PaymentWidget {
    const widget = new PaymentWidgetImpl(this.api, this.options.clientSecret, this.options.locale, this.options.translations, options);
    if (this.parentInstance) {
      widget.setParent(this.parentInstance, this);
    }
    this.elements.set(type, widget);
    return widget;
  }

  getElement(type: string): PaymentWidget | null {
    return this.elements.get(type) ?? null;
  }

  update(options: Partial<WidgetOptions>): void {
    Object.assign(this.options, options);
  }

  getSelectedMethod(): string | null {
    const payment = this.elements.get('payment');
    return payment ? payment.getSelectedMethod() : null;
  }

  getClientSecret(): string {
    return this.options.clientSecret;
  }
}

export function createPayswitchInstance(
  publishableKey: string,
  baseUrl: string,
): PayswitchInstance {
  const api = new PaymentApi(baseUrl, publishableKey);

  const instance: PayswitchInstance = {
    widgets(options: WidgetOptions): WidgetCollection {
      const collection = new WidgetCollectionImpl(api, options);
      collection.setParent(instance);
      return collection;
    },

    async confirmPayment(params: ConfirmPaymentParams): Promise<ConfirmPaymentResult> {
      const collection = params.widgets as WidgetCollectionImpl;
      const clientSecret = collection.getClientSecret();
      const paymentKey = extractPaymentKey(clientSecret);
      const selectedMethod = collection.getSelectedMethod();

      if (!selectedMethod) {
        return {
          status: 'error',
          error: { type: 'validation_error', message: 'No payment method selected' },
        };
      }

      try {
        const result = await api.confirmPayment(paymentKey, {
          client_secret: clientSecret,
          payment_method: selectedMethod,
        });

        if (result.status === 'requires_customer_action') {
          // Embedded PSP widget mode
          if (result.metadata?.widget_data) {
            return { status: 'requires_widget', widgetData: result.metadata.widget_data };
          }

          // Redirect mode
          const redirectUrl = result.metadata?.redirect_url;
          if (redirectUrl) {
            if (params.redirect !== 'if_required') {
              window.location.href = redirectUrl;
            }
            return { status: 'requires_customer_action', redirectUrl };
          }
        }

        if (result.status === 'succeeded') {
          return { status: 'succeeded', paymentIntent: result };
        }

        return {
          status: 'error',
          error: { type: 'api_error', message: `Unexpected status: ${result.status}` },
        };
      } catch (err) {
        return {
          status: 'error',
          error: {
            type: 'api_error',
            message: err instanceof Error ? err.message : 'Unknown error',
          },
        };
      }
    },

    async retrievePaymentIntent(clientSecret: string): Promise<PaymentIntentResponse> {
      const paymentKey = extractPaymentKey(clientSecret);
      return api.getPayment(paymentKey, clientSecret);
    },
  };

  return instance;
}
