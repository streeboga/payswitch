import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams, Link } from '@tanstack/react-router'
import {
  ArrowLeft,
  CircleDot,
  CreditCard,
  Eye,
  EyeOff,
  RotateCcw,
  XCircle,
  Download,
  AlertTriangle,
  CheckCircle2,
  Clock,
  Zap,
  Ban,
  Timer,
} from 'lucide-react'

import type { PaymentAttemptAttributes } from '@/api/endpoints/dashboard-payment-detail'
import type { RefundAttributes, PaymentStatus } from '@/api/types'
import { TERMINAL_PAYMENT_STATUSES } from '@/api/types'

const TERMINAL_STATUSES_SET = new Set(TERMINAL_PAYMENT_STATUSES)
import { usePaymentDetail } from '@/hooks/use-payment-detail'
import { StatusBadge } from '@/components/shared/status-badge'
import { MoneyFormat } from '@/components/shared/money-format'
import { DateFormat } from '@/components/shared/date-format'
import { CopyButton } from '@/components/shared/copy-button'
import { JsonViewer } from '@/components/shared/json-viewer'
import { EmptyState } from '@/components/shared/empty-state'
import { ErrorState } from '@/components/shared/error-state'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import { cn } from '@/lib/utils'

// ─── Timeline Event Types ────────────────────────────────────

interface TimelineEvent {
  id: string
  icon: typeof CircleDot
  iconClass: string
  label: string
  timestamp: string
}

// ─── Helpers ─────────────────────────────────────────────────

function maskSecret(secret: string): string {
  if (secret.length <= 8) return '********'
  return secret.slice(0, 4) + '****' + secret.slice(-4)
}

function getTimelineIcon(status: PaymentStatus) {
  switch (status) {
    case 'succeeded':
      return { icon: CheckCircle2, iconClass: 'text-emerald-500' }
    case 'failed':
      return { icon: AlertTriangle, iconClass: 'text-red-500' }
    case 'cancelled':
      return { icon: Ban, iconClass: 'text-gray-500' }
    case 'expired':
      return { icon: Timer, iconClass: 'text-red-500' }
    case 'processing':
      return { icon: Clock, iconClass: 'text-amber-500' }
    case 'requires_capture':
      return { icon: Download, iconClass: 'text-blue-500' }
    default:
      return { icon: CircleDot, iconClass: 'text-blue-500' }
  }
}

function buildTimeline(
  payment: { status: PaymentStatus; created_at: string },
  attempts: (PaymentAttemptAttributes & { id: string })[],
  t: (key: string, options?: Record<string, unknown>) => string,
): TimelineEvent[] {
  const events: TimelineEvent[] = []

  events.push({
    id: 'created',
    icon: Zap,
    iconClass: 'text-blue-500',
    label: t('paymentDetail.paymentCreated'),
    timestamp: payment.created_at,
  })

  for (let i = 0; i < attempts.length; i++) {
    const attempt = attempts[i]!
    const { icon, iconClass } = getTimelineIcon(attempt.status as PaymentStatus)
    events.push({
      id: `attempt-${attempt.id}`,
      icon,
      iconClass,
      label: t('paymentDetail.attemptLabel', { n: i + 1, connector: attempt.connector, status: attempt.status }),
      timestamp: attempt.created_at,
    })
  }

  if (payment.status !== 'requires_payment_method') {
    const { icon, iconClass } = getTimelineIcon(payment.status)
    const statusLabel = payment.status
      .split('_')
      .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
      .join(' ')

    events.push({
      id: 'final-status',
      icon,
      iconClass,
      label: statusLabel,
      timestamp: payment.created_at,
    })
  }

  return events
}

// ─── Loading Skeleton ────────────────────────────────────────

function PaymentDetailSkeleton() {
  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <Skeleton className="h-8 w-8" />
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-6 w-24" />
      </div>
      <div className="grid gap-6 md:grid-cols-2">
        <Skeleton className="h-48" />
        <Skeleton className="h-48" />
      </div>
      <Skeleton className="h-64" />
    </div>
  )
}

// ─── Page Component ──────────────────────────────────────────

export function PaymentDetailPage() {
  const { t } = useTranslation()
  const { paymentKey } = useParams({ strict: false }) as { paymentKey: string }
  const { data, isLoading, isError, error, refetch } = usePaymentDetail(paymentKey)

  const [secretVisible, setSecretVisible] = useState(false)
  const [metadataOpen, setMetadataOpen] = useState(false)
  const [confirmAction, setConfirmAction] = useState<
    'capture' | 'cancel' | 'refund' | null
  >(null)

  const timeline = useMemo(() => {
    if (!data) return []
    return buildTimeline(data.payment, data.attempts, t)
  }, [data, t])

  if (isLoading) {
    return (
      <div className="space-y-6">
        <Link
          to="/payments"
          className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('paymentDetail.backToPayments')}
        </Link>
        <PaymentDetailSkeleton />
      </div>
    )
  }

  if (isError) {
    return (
      <div className="space-y-6">
        <Link
          to="/payments"
          className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('paymentDetail.backToPayments')}
        </Link>
        <ErrorState
          status={(error as { status?: number })?.status ?? 500}
          onRetry={() => void refetch()}
        />
      </div>
    )
  }

  if (!data) return null

  const { payment, attempts, refunds } = data

  const isTerminal = TERMINAL_STATUSES_SET.has(payment.status)
  const canCapture = payment.status === 'requires_capture'
  const canCancel = !isTerminal
  const canRefund = payment.status === 'succeeded'

  return (
    <div className="space-y-6">
      {/* Back link */}
      <Link
        to="/payments"
        className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
      >
        <ArrowLeft className="h-4 w-4" />
        {t('paymentDetail.backToPayments')}
      </Link>

      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-3">
          <CreditCard className="text-muted-foreground h-7 w-7" />
          <h1 className="font-mono text-2xl font-bold">{payment.id}</h1>
          <CopyButton value={payment.id} />
          <StatusBadge status={payment.status} className="text-sm" />
        </div>
        <div className="flex items-center gap-2">
          {canCapture && (
            <Button
              variant="default"
              size="sm"
              onClick={() => setConfirmAction('capture')}
              disabled
            >
              <Download className="mr-1 h-4 w-4" />
              {t('paymentDetail.buttonCapture')}
            </Button>
          )}
          {canCancel && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => setConfirmAction('cancel')}
              disabled
            >
              <XCircle className="mr-1 h-4 w-4" />
              {t('paymentDetail.buttonCancel')}
            </Button>
          )}
          {canRefund && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => setConfirmAction('refund')}
              disabled
            >
              <RotateCcw className="mr-1 h-4 w-4" />
              {t('paymentDetail.buttonRefund')}
            </Button>
          )}
        </div>
      </div>

      {/* Error Alert */}
      {payment.error_code && (
        <Alert variant="destructive">
          <AlertTriangle className="h-4 w-4" />
          <AlertTitle>Error: {payment.error_code}</AlertTitle>
          <AlertDescription>
            {payment.error_message ?? 'An unknown error occurred.'}
          </AlertDescription>
        </Alert>
      )}

      {/* Info Section */}
      <div className="grid gap-6 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>{t('paymentDetail.cardDetails')}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            <InfoRow label={t('paymentDetail.labelAmount')}>
              <MoneyFormat
                amount={payment.amount}
                currency={payment.currency}
                className="text-lg font-semibold"
              />
            </InfoRow>
            <InfoRow label={t('paymentDetail.labelCurrency')}>
              <span className="text-muted-foreground uppercase">{payment.currency}</span>
            </InfoRow>
            <InfoRow label={t('paymentDetail.labelCaptureMethod')}>
              <span className="capitalize">{payment.capture_method}</span>
            </InfoRow>
            <InfoRow label={t('paymentDetail.labelConnector')}>{payment.connector ?? '—'}</InfoRow>
            <InfoRow label={t('paymentDetail.labelCustomer')}>
              {payment.customer_id ? (
                <span className="font-mono text-sm">{payment.customer_id}</span>
              ) : (
                '—'
              )}
            </InfoRow>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{t('paymentDetail.cardAdditional')}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            <InfoRow label={t('paymentDetail.labelCreated')}>
              <DateFormat date={payment.created_at} />
            </InfoRow>
            <InfoRow label={t('paymentDetail.labelDescription')}>{payment.description ?? '—'}</InfoRow>
            <InfoRow label={t('paymentDetail.labelReturnUrl')}>
              {payment.return_url ? (
                <a
                  href={payment.return_url}
                  className="text-sm text-blue-600 hover:underline dark:text-blue-400"
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  {payment.return_url}
                </a>
              ) : (
                '—'
              )}
            </InfoRow>
            <InfoRow label={t('paymentDetail.labelClientSecret')}>
              <div className="flex items-center gap-1">
                <span className="font-mono text-sm">
                  {secretVisible
                    ? payment.client_secret
                    : maskSecret(payment.client_secret)}
                </span>
                <Button
                  variant="ghost"
                  size="icon-xs"
                  onClick={() => setSecretVisible(!secretVisible)}
                  aria-label={secretVisible ? t('paymentDetail.hideSecret') : t('paymentDetail.showSecret')}
                >
                  {secretVisible ? (
                    <EyeOff className="h-3.5 w-3.5" />
                  ) : (
                    <Eye className="h-3.5 w-3.5" />
                  )}
                </Button>
                <CopyButton value={payment.client_secret} />
              </div>
            </InfoRow>
          </CardContent>
        </Card>
      </div>

      {/* Attempts Section */}
      <Card>
        <CardHeader>
          <CardTitle>{t('paymentDetail.cardAttempts', { count: attempts.length })}</CardTitle>
        </CardHeader>
        <CardContent>
          {attempts.length === 0 ? (
            <p className="text-muted-foreground text-sm">{t('paymentDetail.noAttempts')}</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-12">{t('paymentDetail.attemptColumns.index')}</TableHead>
                  <TableHead>{t('paymentDetail.attemptColumns.connector')}</TableHead>
                  <TableHead>{t('paymentDetail.attemptColumns.status')}</TableHead>
                  <TableHead>{t('paymentDetail.attemptColumns.amount')}</TableHead>
                  <TableHead>{t('paymentDetail.attemptColumns.transactionId')}</TableHead>
                  <TableHead>{t('paymentDetail.attemptColumns.error')}</TableHead>
                  <TableHead>{t('paymentDetail.attemptColumns.date')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {attempts.map((attempt, index) => (
                  <TableRow key={attempt.id}>
                    <TableCell className="text-muted-foreground">{index + 1}</TableCell>
                    <TableCell>{attempt.connector}</TableCell>
                    <TableCell>
                      <StatusBadge status={attempt.status} />
                    </TableCell>
                    <TableCell>
                      <MoneyFormat amount={attempt.amount} currency={attempt.currency} />
                    </TableCell>
                    <TableCell>
                      {attempt.transaction_id ? (
                        <div className="flex items-center gap-1">
                          <span className="font-mono text-xs">
                            {attempt.transaction_id}
                          </span>
                          <CopyButton value={attempt.transaction_id} />
                        </div>
                      ) : (
                        '—'
                      )}
                    </TableCell>
                    <TableCell>
                      {attempt.error_code ? (
                        <span className="text-xs text-red-600 dark:text-red-400">
                          {attempt.error_code}: {attempt.error_message}
                        </span>
                      ) : (
                        '—'
                      )}
                    </TableCell>
                    <TableCell>
                      <DateFormat date={attempt.created_at} />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {/* Refunds Section */}
      <Card>
        <CardHeader>
          <CardTitle>{t('paymentDetail.cardRefunds', { count: refunds.length })}</CardTitle>
        </CardHeader>
        <CardContent>
          {refunds.length === 0 ? (
            <EmptyState
              title={t('paymentDetail.noRefunds')}
              description={t('paymentDetail.noRefundsDesc')}
              className="py-6"
            />
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('paymentDetail.refundColumns.id')}</TableHead>
                  <TableHead>{t('paymentDetail.refundColumns.amount')}</TableHead>
                  <TableHead>{t('paymentDetail.refundColumns.status')}</TableHead>
                  <TableHead>{t('paymentDetail.refundColumns.reason')}</TableHead>
                  <TableHead>{t('paymentDetail.refundColumns.connector')}</TableHead>
                  <TableHead>{t('paymentDetail.refundColumns.date')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {refunds.map((refund) => (
                  <RefundRow key={refund.id} refund={refund} />
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {/* Timeline Section */}
      {timeline.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>{t('paymentDetail.cardTimeline')}</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="relative space-y-0">
              {timeline.map((event, index) => (
                <div key={event.id} className="relative flex gap-4 pb-6 last:pb-0">
                  {/* Vertical line */}
                  {index < timeline.length - 1 && (
                    <div className="bg-border absolute top-6 left-3 h-full w-px" />
                  )}
                  {/* Icon */}
                  <div className="bg-background relative z-10 flex h-6 w-6 shrink-0 items-center justify-center">
                    <event.icon className={cn('h-5 w-5', event.iconClass)} />
                  </div>
                  {/* Content */}
                  <div className="flex flex-1 items-baseline justify-between gap-2 pt-0.5">
                    <span className="text-sm font-medium">{event.label}</span>
                    <DateFormat
                      date={event.timestamp}
                      className="text-muted-foreground text-xs"
                    />
                  </div>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      )}

      {/* Metadata Section */}
      {payment.metadata && Object.keys(payment.metadata).length > 0 && (
        <Collapsible open={metadataOpen} onOpenChange={setMetadataOpen}>
          <Card>
            <CardHeader>
              <CollapsibleTrigger asChild>
                <button
                  type="button"
                  className="flex w-full items-center justify-between"
                >
                  <CardTitle>{t('paymentDetail.cardMetadata')}</CardTitle>
                  <span className="text-muted-foreground text-sm">
                    {metadataOpen ? t('common.collapse') : t('common.expand')}
                  </span>
                </button>
              </CollapsibleTrigger>
            </CardHeader>
            <CollapsibleContent>
              <CardContent>
                <JsonViewer data={payment.metadata} defaultExpanded={false} />
              </CardContent>
            </CollapsibleContent>
          </Card>
        </Collapsible>
      )}

      {/* Confirm Dialogs — TODO: implement actual actions */}
      <ConfirmDialog
        open={confirmAction === 'capture'}
        onConfirm={() => {
          // TODO: implement capture action
          setConfirmAction(null)
        }}
        onCancel={() => setConfirmAction(null)}
        title={t('paymentDetail.dialogCapture')}
        description={t('paymentDetail.dialogCaptureDesc', { currency: payment.currency, amount: payment.amount })}
        confirmLabel={t('paymentDetail.buttonCapture')}
      />

      <ConfirmDialog
        open={confirmAction === 'cancel'}
        onConfirm={() => {
          // TODO: implement cancel action
          setConfirmAction(null)
        }}
        onCancel={() => setConfirmAction(null)}
        title={t('paymentDetail.dialogCancel')}
        description={t('paymentDetail.dialogCancelDesc')}
        confirmLabel={t('paymentDetail.buttonCancel')}
        destructive
      />

      <ConfirmDialog
        open={confirmAction === 'refund'}
        onConfirm={() => {
          // TODO: implement refund action
          setConfirmAction(null)
        }}
        onCancel={() => setConfirmAction(null)}
        title={t('paymentDetail.dialogRefund')}
        description={t('paymentDetail.dialogRefundDesc')}
        confirmLabel={t('paymentDetail.buttonRefund')}
        destructive
      />
    </div>
  )
}

// ─── Sub-components ──────────────────────────────────────────

function InfoRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-baseline justify-between gap-4">
      <span className="text-muted-foreground text-sm">{label}</span>
      <div className="text-right text-sm">{children}</div>
    </div>
  )
}

function RefundRow({ refund }: { refund: RefundAttributes & { id: string } }) {
  return (
    <TableRow>
      <TableCell>
        <span className="font-mono text-xs">{refund.id}</span>
      </TableCell>
      <TableCell>
        <MoneyFormat amount={refund.amount} currency={refund.currency} />
      </TableCell>
      <TableCell>
        <StatusBadge status={refund.status} />
      </TableCell>
      <TableCell>{refund.reason ?? '—'}</TableCell>
      <TableCell>{refund.connector ?? '—'}</TableCell>
      <TableCell>
        <DateFormat date={refund.created_at} />
      </TableCell>
    </TableRow>
  )
}
