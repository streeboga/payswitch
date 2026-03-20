import { h, render as preactRender } from 'preact';
import { useState } from 'preact/hooks';
import type { PaymentMethodInfo, PaymentIntentResponse } from '../types';

// ─── Types ───────────────────────────────────────────────────

interface PaymentWidgetProps {
  payment: PaymentIntentResponse | null;
  methods: PaymentMethodInfo[];
  selectedMethod: string | null;
  onMethodChange: (method: string) => void;
  onPay: () => void;
  confirming?: boolean;
  result?: { status: string; redirectUrl?: string; error?: string } | null;
  loading?: boolean;
  error?: string | null;
}

// ─── Constants ───────────────────────────────────────────────

const METHOD_ICONS: Record<string, string> = {
  card: '\u{1F4B3}',
  bank_transfer: '\u{1F3E6}',
  sbp: '\u{1F4F1}',
  qr_code: '\u{1F4F7}',
  apple_pay: '\uF8FF',
  google_pay: 'G',
};

const METHOD_LABELS: Record<string, string> = {
  card: 'Bank Card',
  bank_transfer: 'Bank Transfer',
  sbp: 'SBP',
  qr_code: 'QR Code',
  apple_pay: 'Apple Pay',
  google_pay: 'Google Pay',
};

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
};

function injectKeyframes(): void {
  if (typeof document === 'undefined') return;
  if (document.getElementById('ps-widget-keyframes')) return;
  const el = document.createElement('style');
  el.id = 'ps-widget-keyframes';
  el.textContent = '@keyframes ps-spin { to { transform: rotate(360deg); } }';
  document.head.appendChild(el);
}

function formatAmount(amount: number, currency: string): string {
  try {
    return new Intl.NumberFormat('ru-RU', {
      style: 'currency',
      currency,
      minimumFractionDigits: 2,
    }).format(amount / 100);
  } catch {
    return `${(amount / 100).toFixed(2)} ${currency}`;
  }
}

// ─── Component ───────────────────────────────────────────────

function PaymentWidgetUI(props: PaymentWidgetProps) {
  const { payment, methods, selectedMethod, onMethodChange, onPay, confirming, result, loading, error } = props;

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
    if (result.status === 'requires_customer_action' && result.redirectUrl) {
      return (
        <div style={s.widget}>
          <div style={s.redirect}>
            <div style={{ marginBottom: '8px', fontWeight: 600 }}>Redirecting to payment provider...</div>
            <a href={result.redirectUrl} style={{ color: '#0066ff', wordBreak: 'break-all' as const }}>
              {result.redirectUrl}
            </a>
          </div>
        </div>
      );
    }
    if (result.status === 'succeeded') {
      return (
        <div style={s.widget}>
          <div style={s.success}>
            <div style={{ fontSize: '32px', marginBottom: '8px' }}>{'\u2705'}</div>
            <div style={{ fontWeight: 600 }}>Payment Succeeded</div>
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

  // No methods
  if (methods.length === 0) {
    return (
      <div style={s.widget}>
        <div style={s.center}>No payment methods available</div>
      </div>
    );
  }

  // Main checkout UI
  return (
    <div style={s.widget}>
      {/* Amount header */}
      {payment && (
        <div style={s.header}>
          <span style={s.amount}>{formatAmount(payment.amount, payment.currency)}</span>
          <span style={s.currency}>{payment.currency}</span>
        </div>
      )}

      {/* Payment methods */}
      <div style={s.methods}>
        {methods.map((m) => {
          const sel = selectedMethod === m.payment_method;
          return (
            <label
              key={m.payment_method}
              style={{ ...s.method, ...(sel ? s.methodSelected : {}) }}
              onMouseEnter={(e) => { if (!sel) (e.currentTarget as HTMLElement).style.borderColor = '#999'; }}
              onMouseLeave={(e) => { if (!sel) (e.currentTarget as HTMLElement).style.borderColor = '#e0e0e0'; }}
            >
              <input
                type="radio"
                name="ps-payment-method"
                value={m.payment_method}
                checked={sel}
                onChange={() => onMethodChange(m.payment_method)}
                style={s.hidden}
              />
              <span style={s.icon}>{METHOD_ICONS[m.payment_method] ?? '\u{1F4B0}'}</span>
              <span style={s.label}>{METHOD_LABELS[m.payment_method] ?? m.payment_method}</span>
              {sel && <span style={s.checkmark}>{'\u2713'}</span>}
            </label>
          );
        })}
      </div>

      {/* Pay button */}
      <button
        type="button"
        style={{ ...s.payBtn, ...(confirming || !selectedMethod ? s.payBtnDisabled : {}) }}
        disabled={confirming || !selectedMethod}
        onClick={onPay}
        onMouseEnter={(e) => { if (!confirming) (e.currentTarget as HTMLElement).style.backgroundColor = '#0052cc'; }}
        onMouseLeave={(e) => { if (!confirming) (e.currentTarget as HTMLElement).style.backgroundColor = '#0066ff'; }}
      >
        {confirming
          ? (<><span style={s.spinnerInline} />Processing...</>)
          : payment
            ? `Pay ${formatAmount(payment.amount, payment.currency)}`
            : 'Pay'}
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
