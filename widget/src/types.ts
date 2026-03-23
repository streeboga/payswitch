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
  or: string;
  scanWithBankApp: string;
  qrExpired: string;
  qrPaymentFailed: string;
  formRedirecting: string;
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
  | { status: 'requires_form_redirect'; formRedirect: FormRedirectData }
  | { status: 'requires_qr'; qrData: QrData }
  | { status: 'requires_external_widget'; externalWidget: ExternalWidgetData }
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
    // New v2 fields
    type?: 'redirect' | 'form_redirect' | 'widget' | 'qr';
    transaction_id?: string;
    form_url?: string;
    form_method?: string;
    form_params?: Record<string, string>;
    widget_provider?: string;
    widget_script_url?: string;
    widget_params?: Record<string, unknown>;
    qr_data?: string;
    qr_format?: 'svg' | 'base64_png' | 'payload';
    qr_payment_id?: string;
    qr_expires_at?: string;
  };
}

export interface WidgetData {
  script_url: string;
  params: Record<string, string>;
}

export type SessionType = 'redirect' | 'form_redirect' | 'widget' | 'qr';

export interface PaymentMethodInfo {
  /** Method identifier: 'card', 'sbp', etc. API v2 uses 'method', v1 used 'payment_method' */
  method?: string;
  payment_method?: string;
  display_name?: string;
  icon_url?: string;
  mode?: 'redirect' | 'widget' | 'inline';
  // New v2 fields
  type?: 'direct' | 'connector';
  connector?: string;
  connector_key?: string;
  session_type?: SessionType;
}

export interface ConnectorInfo {
  connector_name: string;
  connector_key: string;
  display_name: string;
  logo_url: string;
  session_type: SessionType;
}

export type PaymentMethodsMode = 'direct_methods' | 'connector_selection' | 'mixed' | 'none';

export interface PaymentMethodsResponse {
  mode: PaymentMethodsMode;
  methods: PaymentMethodInfo[];
  connectors: ConnectorInfo[];
}

export interface ConfirmResponse {
  status: string;
  type?: 'redirect' | 'form_redirect' | 'widget' | 'qr';
  transaction_id?: string;
  redirect_url?: string;
  redirect_method?: string;
  form_url?: string;
  form_method?: string;
  form_params?: Record<string, string>;
  widget_provider?: string;
  widget_script_url?: string;
  widget_params?: Record<string, unknown>;
  qr_data?: string;
  qr_format?: string;
  qr_payment_id?: string;
  qr_expires_at?: string;
}

export interface FormRedirectData {
  url: string;
  params: Record<string, string>;
  method: string;
}

export interface QrData {
  data: string;
  format: string;
  paymentId?: string;
  expiresAt?: string;
}

export interface ExternalWidgetData {
  provider: string;
  scriptUrl: string;
  params: Record<string, unknown>;
}

export interface AppearanceOptions {
  theme?: 'default' | 'dark' | 'minimal';
  variables?: Record<string, string>;
}
