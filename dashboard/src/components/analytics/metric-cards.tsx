import { useMemo } from 'react'
import { ArrowDownIcon, ArrowUpIcon, type LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import type { OverviewData } from '@/api/endpoints/analytics'

interface MetricCardProps {
  label: string
  value: string
  icon?: LucideIcon
  trend?: 'up' | 'down'
}

function MetricCard({ label, value, trend }: MetricCardProps) {
  return (
    <Card className="gap-2 py-4">
      <CardContent className="flex flex-col gap-1">
        <span className="text-muted-foreground text-sm">{label}</span>
        <div className="flex items-center gap-2">
          <span className="text-2xl font-bold tabular-nums">{value}</span>
          {trend === 'up' ? (
            <ArrowUpIcon className="h-4 w-4 text-emerald-500" />
          ) : trend === 'down' ? (
            <ArrowDownIcon className="h-4 w-4 text-red-500" />
          ) : null}
        </div>
      </CardContent>
    </Card>
  )
}

function MetricCardSkeleton() {
  return (
    <Card className="gap-2 py-4">
      <CardContent className="flex flex-col gap-2">
        <Skeleton className="h-4 w-20" />
        <Skeleton className="h-7 w-24" />
      </CardContent>
    </Card>
  )
}

function formatMoney(amount: number): string {
  return new Intl.NumberFormat('ru-RU', {
    style: 'currency',
    currency: 'RUB',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(amount / 100)
}

function formatPercent(value: number): string {
  return `${value.toFixed(1)}%`
}

function formatNumber(value: number): string {
  return new Intl.NumberFormat('ru-RU').format(value)
}

interface MetricCardsProps {
  data?: OverviewData
  isLoading: boolean
}

export function MetricCards({ data, isLoading }: MetricCardsProps) {
  const { t } = useTranslation()

  const cards = useMemo<MetricCardProps[]>(
    () =>
      data
        ? [
            { label: t('analytics.volume'), value: formatMoney(data.total_volume) },
            {
              label: t('analytics.transactions'),
              value: formatNumber(data.transaction_count),
            },
            {
              label: t('analytics.conversion'),
              value: formatPercent(data.conversion_rate),
            },
            {
              label: t('analytics.refundsMetric'),
              value: formatPercent(data.refund_rate),
            },
            { label: t('analytics.avgTicket'), value: formatMoney(data.avg_ticket) },
            {
              label: t('analytics.disputesMetric'),
              value: formatNumber(data.dispute_count),
            },
          ]
        : [],
    [data, t],
  )

  if (isLoading || !data) {
    return (
      <div
        className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6"
        data-testid="metric-cards-skeleton"
      >
        {Array.from({ length: 6 }).map((_, i) => (
          <MetricCardSkeleton key={i} />
        ))}
      </div>
    )
  }

  return (
    <div
      className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6"
      data-testid="metric-cards"
    >
      {cards.map((card) => (
        <MetricCard key={card.label} {...card} />
      ))}
    </div>
  )
}
