import { h, render as preactRender } from 'preact';
import type { PaymentMethodInfo } from '../types';

interface PaymentWidgetProps {
  methods: PaymentMethodInfo[];
  selectedMethod: string | null;
  onMethodChange: (method: string) => void;
  loading?: boolean;
  error?: string | null;
}

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

const styles = {
  widget: {
    fontFamily: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
    fontSize: '14px',
    color: '#1a1a1a',
  },
  methods: {
    display: 'flex',
    flexDirection: 'column' as const,
    gap: '8px',
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
  methodHidden: {
    display: 'none' as const,
  },
  icon: {
    fontSize: '20px',
    flexShrink: 0,
  },
  label: {
    fontWeight: 500,
  },
  loading: {
    padding: '24px',
    textAlign: 'center' as const,
    color: '#666',
  },
  error: {
    padding: '24px',
    textAlign: 'center' as const,
    color: '#dc2626',
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
};

function injectKeyframes(): void {
  if (typeof document === 'undefined') return;
  if (document.getElementById('ps-widget-keyframes')) return;
  const style = document.createElement('style');
  style.id = 'ps-widget-keyframes';
  style.textContent = '@keyframes ps-spin { to { transform: rotate(360deg); } }';
  document.head.appendChild(style);
}

function PaymentWidgetUI({ methods, selectedMethod, onMethodChange, loading, error }: PaymentWidgetProps) {
  if (loading) {
    injectKeyframes();
    return (
      <div style={styles.widget}>
        <div style={styles.loading}>
          <div style={styles.spinner} />
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div style={styles.widget}>
        <div style={styles.error}>{error}</div>
      </div>
    );
  }

  if (methods.length === 0) {
    return (
      <div style={styles.widget}>
        <div style={styles.loading}>No payment methods available</div>
      </div>
    );
  }

  return (
    <div style={styles.widget}>
      <div style={styles.methods}>
        {methods.map((m) => {
          const isSelected = selectedMethod === m.payment_method;
          return (
            <label
              key={m.payment_method}
              style={{
                ...styles.method,
                ...(isSelected ? styles.methodSelected : {}),
              }}
              onMouseEnter={(e) => {
                if (!isSelected) (e.currentTarget as HTMLElement).style.borderColor = '#999';
              }}
              onMouseLeave={(e) => {
                if (!isSelected) (e.currentTarget as HTMLElement).style.borderColor = '#e0e0e0';
              }}
            >
              <input
                type="radio"
                name="ps-payment-method"
                value={m.payment_method}
                checked={isSelected}
                onChange={() => onMethodChange(m.payment_method)}
                style={styles.methodHidden}
              />
              <span style={styles.icon}>{METHOD_ICONS[m.payment_method] ?? '\u{1F4B0}'}</span>
              <span style={styles.label}>{METHOD_LABELS[m.payment_method] ?? m.payment_method}</span>
            </label>
          );
        })}
      </div>
    </div>
  );
}

export function renderWidget(
  container: HTMLElement,
  props: PaymentWidgetProps,
): void {
  preactRender(h(PaymentWidgetUI, props), container);
}

export function unmountWidget(container: HTMLElement): void {
  preactRender(null, container);
}
