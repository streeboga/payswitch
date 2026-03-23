/** Backend enum types — mirrors PHP enums */

export type PaymentStatus =
  | 'requires_payment_method'
  | 'requires_confirmation'
  | 'requires_customer_action'
  | 'requires_merchant_action'
  | 'processing'
  | 'requires_capture'
  | 'succeeded'
  | 'failed'
  | 'cancelled'
  | 'expired'
  | 'partially_captured'
  | 'partially_captured_and_capturable'

export type RefundStatus = 'succeeded' | 'failed' | 'pending' | 'manual_review'

export type CaptureMethod = 'automatic' | 'manual'

export type AuthenticationType = 'three_ds' | 'no_three_ds'

export type ConnectorName = 'stripe' | 'cloudpayments' | 'yookassa' | 'test'

export type ConnectorType = 'fiz_operations' | 'payout_processor'

export type ApiKeyType = 'admin' | 'secret' | 'publishable'

export type RoutingRuleType = 'priority' | 'rule_based' | 'volume_split'

export type WebhookEventType =
  | 'payment_succeeded'
  | 'payment_captured'
  | 'payment_cancelled'
  | 'payment_authorized'
  | 'payment_status_changed'
  | 'payment_failed'
  | 'payment_processing'
  | 'action_required'
  | 'refund_succeeded'
  | 'refund_failed'

export const TERMINAL_PAYMENT_STATUSES: PaymentStatus[] = [
  'succeeded',
  'failed',
  'cancelled',
  'expired',
]

export const PAYMENT_STATUS_LABELS: Record<PaymentStatus, string> = {
  requires_payment_method: 'Requires Payment Method',
  requires_confirmation: 'Requires Confirmation',
  requires_customer_action: 'Requires Customer Action',
  requires_merchant_action: 'Requires Merchant Action',
  processing: 'Processing',
  requires_capture: 'Requires Capture',
  succeeded: 'Succeeded',
  failed: 'Failed',
  cancelled: 'Cancelled',
  expired: 'Expired',
  partially_captured: 'Partially Captured',
  partially_captured_and_capturable: 'Partially Captured & Capturable',
}

export const REFUND_STATUS_LABELS: Record<RefundStatus, string> = {
  succeeded: 'Succeeded',
  failed: 'Failed',
  pending: 'Pending',
  manual_review: 'Manual Review',
}
