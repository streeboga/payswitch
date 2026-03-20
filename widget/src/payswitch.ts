import { PaymentApi } from './api';
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
  private destroyed = false;

  constructor(
    private api: PaymentApi,
    private clientSecret: string,
    private _options?: Record<string, unknown>,
  ) {}

  mount(selector: string | HTMLElement): void {
    if (this.destroyed) throw new Error('Widget has been destroyed');

    this.container =
      typeof selector === 'string' ? document.querySelector<HTMLElement>(selector) : selector;

    if (!this.container) {
      throw new Error(`Element not found: ${selector}`);
    }

    this.render({ loading: true });

    const paymentKey = extractPaymentKey(this.clientSecret);

    this.api
      .getPaymentMethods(paymentKey, this.clientSecret)
      .then((methods) => {
        if (this.destroyed || !this.container) return;
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

  private render(overrides?: { loading?: boolean; error?: string | null }): void {
    if (!this.container) return;
    renderWidget(this.container, {
      methods: this.methods,
      selectedMethod: this.selectedMethod,
      onMethodChange: (method) => {
        this.selectedMethod = method;
        this.render();
        this.emit('change', { paymentMethod: method });
      },
      loading: overrides?.loading,
      error: overrides?.error,
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

  constructor(
    private api: PaymentApi,
    private options: WidgetOptions,
  ) {}

  create(type: 'payment', options?: Record<string, unknown>): PaymentWidget {
    const widget = new PaymentWidgetImpl(this.api, this.options.clientSecret, options);
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

  return {
    widgets(options: WidgetOptions): WidgetCollection {
      return new WidgetCollectionImpl(api, options);
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

        if (
          result.status === 'requires_customer_action' &&
          result.metadata?.redirect_url
        ) {
          const redirectUrl = result.metadata.redirect_url;

          if (params.redirect !== 'if_required') {
            window.location.href = redirectUrl;
          }

          return { status: 'requires_customer_action', redirectUrl };
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
}
