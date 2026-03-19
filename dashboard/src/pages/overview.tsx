import { lazy, Suspense, useMemo, useState, useTransition } from 'react'
import { useTranslation } from 'react-i18next'
import type { AnalyticsPeriod } from '@/api/endpoints/analytics'
import { PeriodFilter } from '@/components/analytics/period-filter'
import { MetricCards } from '@/components/analytics/metric-cards'
import { ChartSkeleton } from '@/components/analytics/chart-skeleton'
import { ErrorState } from '@/components/shared/error-state'
import {
  useOverview,
  useCharts,
  useFunnel,
  usePaymentMethods,
  useFailureReasons,
} from '@/hooks/use-analytics'
import { useContextStore } from '@/stores/context'

const PaymentsChart = lazy(() => import('@/components/analytics/payments-chart'))
const VolumeChart = lazy(() => import('@/components/analytics/volume-chart'))
const FunnelChart = lazy(() => import('@/components/analytics/funnel-chart'))
const PaymentMethodsChart = lazy(
  () => import('@/components/analytics/payment-methods-chart'),
)
const FailureReasonsChart = lazy(
  () => import('@/components/analytics/failure-reasons-chart'),
)

// Preload chart chunks immediately so they download in parallel with data fetches,
// eliminating the waterfall: data → then chunk JS.
void import('@/components/analytics/payments-chart')
void import('@/components/analytics/volume-chart')
void import('@/components/analytics/funnel-chart')
void import('@/components/analytics/payment-methods-chart')
void import('@/components/analytics/failure-reasons-chart')

export function OverviewPage() {
  const { t } = useTranslation()
  const merchantKey = useContextStore((s) => s.currentMerchantKey)
  const testMode = useContextStore((s) => s.testMode)
  const [period, setPeriod] = useState<AnalyticsPeriod>('7d')
  const [isPending, startTransition] = useTransition()

  const ctx = useMemo(() => ({ merchantKey, testMode }), [merchantKey, testMode])

  const overview = useOverview(period, ctx)
  const charts = useCharts(period, ctx)
  const funnel = useFunnel(period, ctx)
  const paymentMethods = usePaymentMethods(period, ctx)
  const failureReasons = useFailureReasons(period, ctx)

  const handlePeriodChange = (newPeriod: AnalyticsPeriod) => {
    startTransition(() => setPeriod(newPeriod))
  }

  if (!merchantKey) {
    return (
      <div className="space-y-6">
        <h1 className="text-3xl font-bold">{t('overview.title')}</h1>
        <div className="flex items-center justify-center rounded-lg border border-dashed p-12">
          <p className="text-muted-foreground text-lg">{t('overview.selectMerchant')}</p>
        </div>
      </div>
    )
  }

  return (
    <div className={`space-y-6${isPending ? 'opacity-60 transition-opacity' : ''}`}>
      <div className="flex items-center justify-between">
        <h1 className="text-3xl font-bold">{t('overview.title')}</h1>
        <PeriodFilter value={period} onChange={handlePeriodChange} />
      </div>

      {overview.isError ? (
        <ErrorState status={500} onRetry={() => void overview.refetch()} />
      ) : (
        <MetricCards data={overview.data} isLoading={overview.isLoading} />
      )}

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Suspense fallback={<ChartSkeleton />}>
          {charts.isError ? (
            <ErrorState status={500} onRetry={() => void charts.refetch()} />
          ) : charts.isLoading ? (
            <ChartSkeleton />
          ) : charts.data ? (
            <PaymentsChart data={charts.data.data} />
          ) : null}
        </Suspense>

        <Suspense fallback={<ChartSkeleton />}>
          {charts.isError ? (
            <ErrorState status={500} onRetry={() => void charts.refetch()} />
          ) : charts.isLoading ? (
            <ChartSkeleton />
          ) : charts.data ? (
            <VolumeChart data={charts.data.data} />
          ) : null}
        </Suspense>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Suspense fallback={<ChartSkeleton />}>
          {funnel.isError ? (
            <ErrorState status={500} onRetry={() => void funnel.refetch()} />
          ) : funnel.isLoading ? (
            <ChartSkeleton />
          ) : funnel.data ? (
            <FunnelChart data={funnel.data} />
          ) : null}
        </Suspense>

        <Suspense fallback={<ChartSkeleton />}>
          {paymentMethods.isError ? (
            <ErrorState status={500} onRetry={() => void paymentMethods.refetch()} />
          ) : paymentMethods.isLoading ? (
            <ChartSkeleton />
          ) : paymentMethods.data ? (
            <PaymentMethodsChart data={paymentMethods.data.data} />
          ) : null}
        </Suspense>

        <Suspense fallback={<ChartSkeleton />}>
          {failureReasons.isError ? (
            <ErrorState status={500} onRetry={() => void failureReasons.refetch()} />
          ) : failureReasons.isLoading ? (
            <ChartSkeleton />
          ) : failureReasons.data ? (
            <FailureReasonsChart data={failureReasons.data.data} />
          ) : null}
        </Suspense>
      </div>
    </div>
  )
}
