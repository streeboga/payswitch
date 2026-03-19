import { PaymentApi } from './api';
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

    this.container.innerHTML = '<div class="payswitch-loading">Loading payment methods...</div>';

    const paymentKey = extractPaymentKey(this.clientSecret);

    this.api
      .getPaymentMethods(paymentKey, this.clientSecret)
      .then((methods) => {
        if (this.destroyed || !this.container) return;
        this.renderMethods(methods);
        this.emit('ready', {});
      })
      .catch((err) => {
        if (this.destroyed || !this.container) return;
        this.container.innerHTML = `<div class="payswitch-error">${err.message}</div>`;
        this.emit('error', { error: err.message });
      });
  }

  unmount(): void {
    if (this.container) {
      this.container.innerHTML = '';
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
    // Will be extended in Task 7 with Preact UI
  }

  getSelectedMethod(): string | null {
    return this.selectedMethod;
  }

  private renderMethods(methods: PaymentMethodInfo[]): void {
    if (!this.container) return;

    if (methods.length === 0) {
      this.container.innerHTML =
        '<div class="payswitch-empty">No payment methods available</div>';
      return;
    }

    const html = methods
      .map(
        (m, i) => `
        <label class="payswitch-method">
          <input type="radio" name="payswitch-method" value="${m.payment_method}" ${i === 0 ? 'checked' : ''} />
          <span>${m.payment_method}</span>
        </label>
      `,
      )
      .join('');

    this.container.innerHTML = `<div class="payswitch-methods">${html}</div>`;

    // Auto-select first method
    this.selectedMethod = methods[0].payment_method;

    // Listen for changes
    this.container.querySelectorAll<HTMLInputElement>('input[name="payswitch-method"]').forEach((input) => {
      input.addEventListener('change', () => {
        this.selectedMethod = input.value;
        this.emit('change', { paymentMethod: input.value });
      });
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
