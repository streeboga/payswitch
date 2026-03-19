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
};

const METHOD_LABELS: Record<string, string> = {
  card: 'Bank Card',
  bank_transfer: 'Bank Transfer',
  sbp: 'SBP (\u0421\u0411\u041F)',
  qr_code: 'QR Code',
};

function PaymentWidgetUI({ methods, selectedMethod, onMethodChange, loading, error }: PaymentWidgetProps) {
  if (loading) {
    return <div class="ps-widget ps-loading"><div class="ps-spinner" /></div>;
  }

  if (error) {
    return <div class="ps-widget ps-error">{error}</div>;
  }

  if (methods.length === 0) {
    return <div class="ps-widget ps-empty">No payment methods available</div>;
  }

  return (
    <div class="ps-widget">
      <div class="ps-methods">
        {methods.map((m) => (
          <label
            key={m.payment_method}
            class={`ps-method ${selectedMethod === m.payment_method ? 'ps-method--selected' : ''}`}
          >
            <input
              type="radio"
              name="ps-payment-method"
              value={m.payment_method}
              checked={selectedMethod === m.payment_method}
              onChange={() => onMethodChange(m.payment_method)}
            />
            <span class="ps-method-icon">{METHOD_ICONS[m.payment_method] ?? '\u{1F4B0}'}</span>
            <span class="ps-method-label">{METHOD_LABELS[m.payment_method] ?? m.payment_method}</span>
          </label>
        ))}
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
