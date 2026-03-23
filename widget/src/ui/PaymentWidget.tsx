import { h, render as preactRender } from 'preact';
import type {
  PaymentMethodInfo,
  PaymentIntentResponse,
  WidgetTranslations,
  WidgetData,
  ConnectorInfo,
  PaymentMethodsMode,
  FormRedirectData,
  QrData,
  ExternalWidgetData,
} from '../types';
import type { PaymentApi } from '../api';
import { getMethodDisplayName } from '../i18n';
import { FormRedirect } from './FormRedirect';
import { QrPayment } from './QrPayment';

// ─── Types ───────────────────────────────────────────────────

export interface PaymentWidgetProps {
  payment: PaymentIntentResponse | null;
  mode: PaymentMethodsMode;
  methods: PaymentMethodInfo[];
  connectors: ConnectorInfo[];
  selectedMethod: string | null;
  selectedConnector: string | null;
  onMethodChange: (method: string) => void;
  onConnectorChange: (connectorKey: string) => void;
  onPay: () => void;
  confirming?: boolean;
  result?: WidgetResult | null;
  loading?: boolean;
  error?: string | null;
  t: WidgetTranslations;
  locale?: string;
  // For QR polling
  api?: PaymentApi;
  paymentKey?: string;
  clientSecret?: string;
  onQrSuccess?: () => void;
  onQrError?: (message: string) => void;
}

export interface WidgetResult {
  status: string;
  redirectUrl?: string;
  error?: string;
  widgetData?: WidgetData;
  formRedirect?: FormRedirectData;
  qrData?: QrData;
  externalWidget?: ExternalWidgetData;
}

// ─── Constants ───────────────────────────────────────────────

const GENERIC_PAYMENT_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>';

// ─── Styles ──────────────────────────────────────────────────

const s = {
  widget: {
    fontFamily: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
    fontSize: '14px',
    color: '#1a1a1a',
    maxWidth: '420px',
  },
  header: {
    display: 'flex',
    justifyContent: 'space-between',
    alignItems: 'baseline',
    marginBottom: '16px',
    paddingBottom: '12px',
    borderBottom: '1px solid #e0e0e0',
  },
  amount: {
    fontSize: '24px',
    fontWeight: 700,
  },
  currency: {
    fontSize: '14px',
    color: '#666',
  },
  methods: {
    display: 'flex',
    flexDirection: 'column' as const,
    gap: '8px',
    marginBottom: '16px',
  },
  method: {
    display: 'flex',
    alignItems: 'center',
    gap: '12px',
    padding: '12px 16px',
    border: '1px solid #e0e0e0',
    borderRadius: '8px',
    cursor: 'pointer',
    transition: 'border-color 0.15s, background-color 0.15s',
    background: 'transparent',
  },
  methodSelected: {
    borderColor: '#0066ff',
    backgroundColor: '#f0f7ff',
  },
  hidden: { display: 'none' as const },
  icon: { fontSize: '20px', flexShrink: 0 },
  label: { fontWeight: 500, flex: 1 },
  checkmark: { color: '#0066ff', fontSize: '16px' },
  payBtn: {
    width: '100%',
    padding: '14px',
    fontSize: '16px',
    fontWeight: 600,
    color: '#fff',
    backgroundColor: '#0066ff',
    border: 'none',
    borderRadius: '8px',
    cursor: 'pointer',
    transition: 'background-color 0.15s',
  },
  payBtnDisabled: {
    backgroundColor: '#99c2ff',
    cursor: 'not-allowed',
  },
  center: {
    padding: '24px',
    textAlign: 'center' as const,
    color: '#666',
  },
  error: {
    padding: '16px',
    textAlign: 'center' as const,
    color: '#dc2626',
    backgroundColor: '#fef2f2',
    borderRadius: '8px',
    marginTop: '12px',
  },
  success: {
    padding: '16px',
    textAlign: 'center' as const,
    color: '#166534',
    backgroundColor: '#f0fdf4',
    borderRadius: '8px',
  },
  redirect: {
    padding: '16px',
    textAlign: 'center' as const,
    color: '#1e40af',
    backgroundColor: '#eff6ff',
    borderRadius: '8px',
  },
  spinner: {
    width: '24px',
    height: '24px',
    margin: '0 auto',
    border: '3px solid #e0e0e0',
    borderTopColor: '#0066ff',
    borderRadius: '50%',
    animation: 'ps-spin 0.6s linear infinite',
  },
  spinnerInline: {
    width: '16px',
    height: '16px',
    display: 'inline-block',
    marginRight: '8px',
    verticalAlign: 'middle',
    border: '2px solid rgba(255,255,255,0.3)',
    borderTopColor: '#fff',
    borderRadius: '50%',
    animation: 'ps-spin 0.6s linear infinite',
  },
  separator: {
    display: 'flex',
    alignItems: 'center',
    gap: '12px',
    margin: '16px 0',
    color: '#999',
    fontSize: '13px',
  },
  separatorLine: {
    flex: 1,
    height: '1px',
    backgroundColor: '#e0e0e0',
  },
  connectorLogo: {
    width: '24px',
    height: '24px',
    borderRadius: '4px',
    objectFit: 'contain' as const,
  },
};

function injectKeyframes(): void {
  if (typeof document === 'undefined') return;
  if (document.getElementById('ps-widget-keyframes')) return;
  const el = document.createElement('style');
  el.id = 'ps-widget-keyframes';
  el.textContent = '@keyframes ps-spin { to { transform: rotate(360deg); } }';
  document.head.appendChild(el);
}

function formatAmount(amount: number, currency: string, locale?: string): string {
  try {
    return new Intl.NumberFormat(locale ?? 'en', {
      style: 'currency',
      currency,
      minimumFractionDigits: 2,
    }).format(amount / 100);
  } catch {
    return `${(amount / 100).toFixed(2)} ${currency}`;
  }
}

// ─── Embedded PSP Widget (legacy) ───────────────────────────

function EmbeddedPspWidget({ widgetData }: { widgetData: WidgetData }) {
  const containerRef = (el: HTMLDivElement | null) => {
    if (!el || el.dataset.loaded) return;
    el.dataset.loaded = '1';

    const script = document.createElement('script');
    script.src = widgetData.script_url;
    for (const [key, value] of Object.entries(widgetData.params)) {
      script.dataset[key] = value;
    }
    el.appendChild(script);
  };

  return <div ref={containerRef} style={{ minHeight: '200px' }} />;
}

// ─── External PSP Widget (v2) ───────────────────────────────

function ExternalPspWidget({ data }: { data: ExternalWidgetData }) {
  const containerRef = (el: HTMLDivElement | null) => {
    if (!el || el.dataset.loaded) return;
    el.dataset.loaded = '1';

    const script = document.createElement('script');
    script.src = data.scriptUrl;
    for (const [key, value] of Object.entries(data.params)) {
      script.dataset[key] = String(value);
    }
    el.appendChild(script);
  };

  return <div ref={containerRef} style={{ minHeight: '200px' }} />;
}

// ─── Method List ─────────────────────────────────────────────

function MethodList({
  methods,
  selectedMethod,
  onMethodChange,
  locale,
}: {
  methods: PaymentMethodInfo[];
  selectedMethod: string | null;
  onMethodChange: (method: string) => void;
  locale?: string;
}) {
  return (
    <div style={s.methods}>
      {methods.map((m) => {
        const methodId = m.method ?? m.payment_method ?? '';
        const sel = selectedMethod === methodId;
        return (
          <label
            key={methodId}
            style={{ ...s.method, ...(sel ? s.methodSelected : {}) }}
            onMouseEnter={(e) => { if (!sel) (e.currentTarget as HTMLElement).style.borderColor = '#999'; }}
            onMouseLeave={(e) => { if (!sel) (e.currentTarget as HTMLElement).style.borderColor = '#e0e0e0'; }}
          >
            <input
              type="radio"
              name="ps-payment-method"
              value={methodId}
              checked={sel}
              onChange={() => onMethodChange(methodId)}
              style={s.hidden}
            />
            <span style={s.icon}>
              {m.icon_url
                ? <img src={m.icon_url} alt="" width="20" height="20" style={{ display: 'block' }} />
                : <span dangerouslySetInnerHTML={{ __html: GENERIC_PAYMENT_ICON }} />}
            </span>
            <span style={s.label}>{m.display_name ?? getMethodDisplayName(methodId, locale)}</span>
            {sel && <span style={s.checkmark}>{'\u2713'}</span>}
          </label>
        );
      })}
    </div>
  );
}

// ─── Connector List ──────────────────────────────────────────

function ConnectorList({
  connectors,
  selectedConnector,
  onConnectorChange,
}: {
  connectors: ConnectorInfo[];
  selectedConnector: string | null;
  onConnectorChange: (connectorKey: string) => void;
}) {
  return (
    <div style={s.methods}>
      {connectors.map((c) => {
        const sel = selectedConnector === c.connector_key;
        return (
          <label
            key={c.connector_key}
            style={{ ...s.method, ...(sel ? s.methodSelected : {}) }}
            onMouseEnter={(e) => { if (!sel) (e.currentTarget as HTMLElement).style.borderColor = '#999'; }}
            onMouseLeave={(e) => { if (!sel) (e.currentTarget as HTMLElement).style.borderColor = '#e0e0e0'; }}
          >
            <input
              type="radio"
              name="ps-connector"
              value={c.connector_key}
              checked={sel}
              onChange={() => onConnectorChange(c.connector_key)}
              style={s.hidden}
            />
            <span style={s.icon}>
              {c.logo_url
                ? <img src={c.logo_url} alt="" style={s.connectorLogo} />
                : <span dangerouslySetInnerHTML={{ __html: GENERIC_PAYMENT_ICON }} />}
            </span>
            <span style={s.label}>{c.display_name}</span>
            {sel && <span style={s.checkmark}>{'\u2713'}</span>}
          </label>
        );
      })}
    </div>
  );
}

// ─── Separator ───────────────────────────────────────────────

function Separator({ text }: { text: string }) {
  return (
    <div style={s.separator}>
      <div style={s.separatorLine} />
      <span>{text}</span>
      <div style={s.separatorLine} />
    </div>
  );
}

// ─── Component ───────────────────────────────────────────────

function PaymentWidgetUI(props: PaymentWidgetProps) {
  const {
    payment, mode, methods, connectors,
    selectedMethod, selectedConnector,
    onMethodChange, onConnectorChange,
    onPay, confirming, result, loading, error,
    t, locale,
    api, paymentKey, clientSecret,
    onQrSuccess, onQrError,
  } = props;

  injectKeyframes();

  // Loading state
  if (loading) {
    return (
      <div style={s.widget}>
        <div style={s.center}><div style={s.spinner} /></div>
      </div>
    );
  }

  // Error state
  if (error) {
    return (
      <div style={s.widget}>
        <div style={s.error}>{error}</div>
      </div>
    );
  }

  // Result state — payment confirmed
  if (result) {
    // QR code display
    if (result.status === 'requires_qr' && result.qrData && api && paymentKey && clientSecret) {
      return (
        <div style={s.widget}>
          <QrPayment
            qrData={result.qrData}
            api={api}
            paymentKey={paymentKey}
            clientSecret={clientSecret}
            t={t}
            onSuccess={onQrSuccess ?? (() => {})}
            onError={onQrError ?? (() => {})}
          />
        </div>
      );
    }
    // Form redirect
    if (result.status === 'requires_form_redirect' && result.formRedirect) {
      return (
        <div style={s.widget}>
          <FormRedirect data={result.formRedirect} t={t} />
        </div>
      );
    }
    // External widget (v2)
    if (result.status === 'requires_external_widget' && result.externalWidget) {
      return (
        <div style={s.widget}>
          <ExternalPspWidget data={result.externalWidget} />
        </div>
      );
    }
    // Legacy redirect
    if (result.status === 'requires_customer_action' && result.redirectUrl) {
      return (
        <div style={s.widget}>
          <div style={s.redirect}>
            <div style={{ marginBottom: '8px', fontWeight: 600 }}>{t.redirecting}</div>
            <a href={result.redirectUrl} style={{ color: '#0066ff', wordBreak: 'break-all' as const }}>
              {result.redirectUrl}
            </a>
          </div>
        </div>
      );
    }
    // Legacy embedded widget
    if (result.status === 'requires_widget' && result.widgetData) {
      return (
        <div style={s.widget}>
          <EmbeddedPspWidget widgetData={result.widgetData} />
        </div>
      );
    }
    if (result.status === 'succeeded') {
      return (
        <div style={s.widget}>
          <div style={s.success}>
            <div style={{ fontSize: '32px', marginBottom: '8px' }}>{'\u2705'}</div>
            <div style={{ fontWeight: 600 }}>{t.paymentSucceeded}</div>
          </div>
        </div>
      );
    }
    if (result.error) {
      return (
        <div style={s.widget}>
          <div style={s.error}>{result.error}</div>
        </div>
      );
    }
  }

  // No methods and no connectors
  const hasSelection = mode === 'none' || selectedMethod || selectedConnector;

  if (mode !== 'none' && methods.length === 0 && connectors.length === 0) {
    return (
      <div style={s.widget}>
        <div style={s.center}>{t.noMethods}</div>
      </div>
    );
  }

  // Main checkout UI
  return (
    <div style={s.widget}>
      {/* Amount header */}
      {payment && (
        <div style={s.header}>
          <span style={s.amount}>{formatAmount(payment.amount, payment.currency, locale)}</span>
          <span style={s.currency}>{payment.currency}</span>
        </div>
      )}

      {/* Mode: direct_methods — show method buttons */}
      {(mode === 'direct_methods' || mode === 'mixed') && methods.length > 0 && (
        <MethodList
          methods={methods}
          selectedMethod={selectedMethod}
          onMethodChange={onMethodChange}
          locale={locale}
        />
      )}

      {/* Mode: mixed — separator */}
      {mode === 'mixed' && methods.length > 0 && connectors.length > 0 && (
        <Separator text={t.or} />
      )}

      {/* Mode: connector_selection or mixed — show connector buttons */}
      {(mode === 'connector_selection' || mode === 'mixed') && connectors.length > 0 && (
        <ConnectorList
          connectors={connectors}
          selectedConnector={selectedConnector}
          onConnectorChange={onConnectorChange}
        />
      )}

      {/* Pay button */}
      <button
        type="button"
        style={{ ...s.payBtn, ...(confirming || !hasSelection ? s.payBtnDisabled : {}) }}
        disabled={confirming || !hasSelection}
        onClick={onPay}
        onMouseEnter={(e) => { if (!confirming) (e.currentTarget as HTMLElement).style.backgroundColor = '#0052cc'; }}
        onMouseLeave={(e) => { if (!confirming) (e.currentTarget as HTMLElement).style.backgroundColor = '#0066ff'; }}
      >
        {confirming
          ? (<><span style={s.spinnerInline} />{t.processing}</>)
          : payment
            ? t.payAmount.replace('{amount}', formatAmount(payment.amount, payment.currency, locale))
            : t.pay}
      </button>
    </div>
  );
}

// ─── Render API ──────────────────────────────────────────────

export function renderWidget(container: HTMLElement, props: PaymentWidgetProps): void {
  preactRender(h(PaymentWidgetUI, props), container);
}

export function unmountWidget(container: HTMLElement): void {
  preactRender(null, container);
}
