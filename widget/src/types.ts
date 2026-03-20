export interface LoadOptions {
  customBackendUrl?: string;
  env?: 'sandbox' | 'production';
}

export interface PayswitchInstance {
  widgets(options: WidgetOptions): WidgetCollection;
  confirmPayment(params: ConfirmPaymentParams): Promise<ConfirmPaymentResult>;
  retrievePaymentIntent(clientSecret: string): Promise<PaymentIntentResponse>;
}

export interface WidgetOptions {
  clientSecret: string;
  appearance?: AppearanceOptions;
  locale?: string;
  translations?: Partial<WidgetTranslations>;
}

export interface WidgetTranslations {
  pay: string;
  payAmount: string;
  processing: string;
  noMethods: string;
  redirecting: string;
  paymentSucceeded: string;
  error: string;
}

export interface WidgetCollection {
  create(type: 'payment', options?: Record<string, unknown>): PaymentWidget;
  getElement(type: string): PaymentWidget | null;
  update(options: Partial<WidgetOptions>): void;
}

export interface PaymentWidget {
  mount(selector: string | HTMLElement): void;
  unmount(): void;
  destroy(): void;
  on(event: WidgetEvent, handler: WidgetEventHandler): void;
  update(options: Record<string, unknown>): void;
}

export type WidgetEvent = 'ready' | 'change' | 'redirect' | 'error';
export type WidgetEventHandler = (data: unknown) => void;

export interface ConfirmPaymentParams {
  widgets: WidgetCollection;
  confirmParams: { return_url: string };
  redirect?: 'always' | 'if_required';
}

export type ConfirmPaymentResult =
  | { status: 'succeeded'; paymentIntent: PaymentIntentResponse }
  | { status: 'requires_customer_action'; redirectUrl: string }
  | { status: 'requires_widget'; widgetData: WidgetData }
  | { status: 'error'; error: { type: string; message: string } };

export interface PaymentIntentResponse {
  status: string;
  amount: number;
  currency: string;
  description?: string;
  metadata?: {
    redirect_url?: string;
    redirect_method?: string;
    widget_data?: WidgetData;
  };
}

export interface WidgetData {
  script_url: string;
  params: Record<string, string>;
}

export interface PaymentMethodInfo {
  payment_method: string;
  display_name?: string;
  icon_url?: string;
  mode?: 'redirect' | 'widget' | 'inline';
}

export interface AppearanceOptions {
  theme?: 'default' | 'dark' | 'minimal';
  variables?: Record<string, string>;
}
