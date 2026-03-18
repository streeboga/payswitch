import { cn } from '@/lib/utils'
import { Badge } from '@/components/ui/badge'

type StatusVariant = 'success' | 'destructive' | 'warning' | 'info' | 'muted'

const variantClasses: Record<StatusVariant, string> = {
  success: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
  destructive: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
  warning: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
  info: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
  muted: 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300',
}

const STATUS_VARIANT_MAP: Record<string, StatusVariant> = {
  succeeded: 'success',
  failed: 'destructive',
  expired: 'destructive',
  cancelled: 'muted',
  processing: 'warning',
  pending: 'warning',
  manual_review: 'warning',
  requires_payment_method: 'info',
  requires_confirmation: 'info',
  requires_customer_action: 'info',
  requires_merchant_action: 'info',
  requires_capture: 'info',
  partially_captured: 'info',
  partially_captured_and_capturable: 'info',
  active: 'success',
  revoked: 'destructive',
  open: 'warning',
  under_review: 'info',
  won: 'success',
  lost: 'destructive',
  evidence_submitted: 'info',
}

const STATUS_LABELS: Record<string, string> = {
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
  pending: 'Pending',
  manual_review: 'Manual Review',
}

function humanize(status: string): string {
  return status
    .split('_')
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ')
}

interface StatusBadgeProps {
  status: string
  label?: string
  className?: string
}

export function StatusBadge({ status, label, className }: StatusBadgeProps) {
  const variant = STATUS_VARIANT_MAP[status] ?? 'muted'
  const displayLabel = label ?? STATUS_LABELS[status] ?? humanize(status)

  return (
    <Badge variant="outline" className={cn(variantClasses[variant], className)}>
      {displayLabel}
    </Badge>
  )
}
