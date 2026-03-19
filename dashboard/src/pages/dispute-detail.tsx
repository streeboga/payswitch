import { useState, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams, Link } from '@tanstack/react-router'
import {
  ArrowLeft,
  ShieldAlert,
  Upload,
  CheckCircle2,
  Clock,
  FileText,
  AlertTriangle,
} from 'lucide-react'

import type { DisputeTimelineEvent } from '@/api/endpoints/dashboard-disputes'
import { useDisputeDetail, useUploadEvidence } from '@/hooks/use-disputes'
import { StatusBadge } from '@/components/shared/status-badge'
import { MoneyFormat } from '@/components/shared/money-format'
import { DateFormat } from '@/components/shared/date-format'
import { CopyButton } from '@/components/shared/copy-button'
import { ErrorState } from '@/components/shared/error-state'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Textarea } from '@/components/ui/textarea'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'

// ─── Constants ───────────────────────────────────────────────

const DISPUTE_TYPE_LABELS: Record<string, string> = {
  chargeback: 'Chargeback',
  inquiry: 'Inquiry',
  pre_arbitration: 'Pre-Arbitration',
}

const DISPUTE_STATUS_LABELS: Record<string, string> = {
  open: 'Open',
  under_review: 'Under Review',
  won: 'Won',
  lost: 'Lost',
  evidence_submitted: 'Evidence Submitted',
  expired: 'Expired',
}

// ─── Timeline Icon Helper ────────────────────────────────────

function getTimelineIcon(event: string) {
  if (event.includes('opened') || event.includes('created')) {
    return { icon: AlertTriangle, iconClass: 'text-red-500' }
  }
  if (event.includes('evidence') || event.includes('submitted')) {
    return { icon: FileText, iconClass: 'text-blue-500' }
  }
  if (event.includes('resolved') || event.includes('won')) {
    return { icon: CheckCircle2, iconClass: 'text-emerald-500' }
  }
  if (event.includes('lost') || event.includes('expired')) {
    return { icon: AlertTriangle, iconClass: 'text-red-500' }
  }
  return { icon: Clock, iconClass: 'text-amber-500' }
}

// ─── Loading Skeleton ────────────────────────────────────────

function DisputeDetailSkeleton() {
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

export function DisputeDetailPage() {
  const { t } = useTranslation()
  const { disputeKey } = useParams({ strict: false }) as { disputeKey: string }
  const { data, isLoading, isError, error, refetch } = useDisputeDetail(disputeKey)
  const uploadEvidence = useUploadEvidence()

  const [evidenceText, setEvidenceText] = useState('')
  const [evidenceFiles, setEvidenceFiles] = useState<File[]>([])

  const handleFileChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files) {
      setEvidenceFiles(Array.from(e.target.files))
    }
  }, [])

  const handleSubmitEvidence = useCallback(() => {
    if (!evidenceText && evidenceFiles.length === 0) return
    uploadEvidence.mutate(
      {
        key: disputeKey,
        data: {
          text: evidenceText || undefined,
          files: evidenceFiles.length > 0 ? evidenceFiles : undefined,
        },
      },
      {
        onSuccess: () => {
          setEvidenceText('')
          setEvidenceFiles([])
        },
      },
    )
  }, [disputeKey, evidenceText, evidenceFiles, uploadEvidence])

  if (isLoading) {
    return (
      <div className="space-y-6">
        <Link
          to="/disputes"
          className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('disputeDetail.backToDisputes')}
        </Link>
        <DisputeDetailSkeleton />
      </div>
    )
  }

  if (isError) {
    return (
      <div className="space-y-6">
        <Link
          to="/disputes"
          className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('disputeDetail.backToDisputes')}
        </Link>
        <ErrorState
          status={(error as { status?: number })?.status ?? 500}
          onRetry={() => void refetch()}
        />
      </div>
    )
  }

  if (!data) return null

  const dispute = data
  const canSubmitEvidence = dispute.status === 'open' || dispute.status === 'under_review'

  return (
    <div className="space-y-6">
      {/* Back link */}
      <Link
        to="/disputes"
        className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
      >
        <ArrowLeft className="h-4 w-4" />
        {t('disputeDetail.backToDisputes')}
      </Link>

      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-3">
          <ShieldAlert className="text-muted-foreground h-7 w-7" />
          <h1 className="font-mono text-2xl font-bold">{dispute.id}</h1>
          <CopyButton value={dispute.id} />
          <StatusBadge
            status={dispute.status}
            label={DISPUTE_STATUS_LABELS[dispute.status]}
            className="text-sm"
          />
        </div>
        <Link
          to="/payments/$paymentKey"
          params={{ paymentKey: dispute.payment_id }}
          className="text-sm text-blue-600 hover:underline dark:text-blue-400"
        >
          {t('disputeDetail.paymentLink')} {dispute.payment_id}
        </Link>
      </div>

      {/* Info Card */}
      <Card>
        <CardHeader>
          <CardTitle>{t('disputeDetail.cardInfo')}</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          <InfoRow label={t('disputeDetail.labelAmount')}>
            <MoneyFormat
              amount={dispute.amount}
              currency={dispute.currency}
              className="text-lg font-semibold"
            />
          </InfoRow>
          <InfoRow label={t('disputeDetail.labelCurrency')}>
            <span className="text-muted-foreground uppercase">{dispute.currency}</span>
          </InfoRow>
          <InfoRow label={t('disputeDetail.labelType')}>
            <Badge
              variant={
                dispute.type === 'chargeback'
                  ? 'destructive'
                  : dispute.type === 'pre_arbitration'
                    ? 'secondary'
                    : 'outline'
              }
            >
              {DISPUTE_TYPE_LABELS[dispute.type] ?? dispute.type}
            </Badge>
          </InfoRow>
          <InfoRow label={t('disputeDetail.labelReason')}>{dispute.reason}</InfoRow>
          <InfoRow label={t('disputeDetail.labelDeadline')}>
            <DateFormat date={dispute.deadline} />
          </InfoRow>
          <InfoRow label={t('disputeDetail.labelConnector')}>
            {dispute.connector ?? '—'}
          </InfoRow>
          <InfoRow label={t('disputeDetail.labelCreated')}>
            <DateFormat date={dispute.created_at} />
          </InfoRow>
        </CardContent>
      </Card>

      {/* Evidence Section */}
      {canSubmitEvidence && (
        <Card>
          <CardHeader>
            <CardTitle>{t('disputeDetail.evidenceTitle')}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="evidence-text">{t('disputeDetail.evidenceDesc')}</Label>
              <Textarea
                id="evidence-text"
                placeholder={t('disputeDetail.evidenceDescPlaceholder')}
                value={evidenceText}
                onChange={(e) => setEvidenceText(e.target.value)}
                rows={4}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="evidence-files">{t('disputeDetail.evidenceFiles')}</Label>
              <Input
                id="evidence-files"
                type="file"
                multiple
                onChange={handleFileChange}
                className="cursor-pointer"
              />
              {evidenceFiles.length > 0 && (
                <p className="text-muted-foreground text-sm">
                  {t('disputeDetail.evidenceFilesCount', { count: evidenceFiles.length })}
                </p>
              )}
            </div>
            <Button
              onClick={handleSubmitEvidence}
              disabled={
                uploadEvidence.isPending || (!evidenceText && evidenceFiles.length === 0)
              }
            >
              <Upload className="mr-2 h-4 w-4" />
              {uploadEvidence.isPending
                ? t('disputeDetail.submitting')
                : t('disputeDetail.submitButton')}
            </Button>
          </CardContent>
        </Card>
      )}

      {/* Timeline Section */}
      {dispute.timeline.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>{t('disputeDetail.timelineTitle')}</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="relative space-y-0">
              {dispute.timeline.map((event: DisputeTimelineEvent, index: number) => {
                const { icon: Icon, iconClass } = getTimelineIcon(event.event)
                return (
                  <div
                    key={`${event.event}-${event.timestamp}`}
                    className="relative flex gap-4 pb-6 last:pb-0"
                  >
                    {/* Vertical line */}
                    {index < dispute.timeline.length - 1 && (
                      <div className="bg-border absolute top-6 left-3 h-full w-px" />
                    )}
                    {/* Icon */}
                    <div className="bg-background relative z-10 flex h-6 w-6 shrink-0 items-center justify-center">
                      <Icon className={cn('h-5 w-5', iconClass)} />
                    </div>
                    {/* Content */}
                    <div className="flex flex-1 items-baseline justify-between gap-2 pt-0.5">
                      <span className="text-sm font-medium">{event.event}</span>
                      <DateFormat
                        date={event.timestamp}
                        className="text-muted-foreground text-xs"
                      />
                    </div>
                  </div>
                )
              })}
            </div>
          </CardContent>
        </Card>
      )}
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
