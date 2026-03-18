import React, { useMemo, useState, useTransition } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams, useNavigate } from '@tanstack/react-router'
import { useReactTable, getCoreRowModel, type ColumnDef } from '@tanstack/react-table'
import { ArrowLeft, Activity, CircleCheck, CircleAlert, CircleX } from 'lucide-react'

import type {
  HealthPeriod,
  HealthStatus,
  ConnectorHealthError,
} from '@/api/endpoints/dashboard-connector-health'
import {
  useConnectorHealth,
  useConnectorHealthHistory,
} from '@/hooks/use-connector-health'
import { DataTable } from '@/components/data-table'
import { DateFormat } from '@/components/shared/date-format'
import { ErrorState } from '@/components/shared/error-state'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { usePreferencesStore } from '@/stores/preferences'

// ─── Lazy-loaded chart components ───────────────────────────

const LatencyChart = React.lazy(() => import('./charts/latency-chart'))
const ErrorRateChart = React.lazy(() => import('./charts/error-rate-chart'))
const OperationsBarChart = React.lazy(() => import('./charts/operations-bar-chart'))
const ComparisonBarChart = React.lazy(() => import('./charts/comparison-bar-chart'))

// ─── Status Helpers ─────────────────────────────────────────

const STATUS_CONFIG: Record<
  HealthStatus,
  { label: string; icon: typeof CircleCheck; className: string }
> = {
  healthy: {
    label: 'Healthy',
    icon: CircleCheck,
    className:
      'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
  },
  degraded: {
    label: 'Degraded',
    icon: CircleAlert,
    className: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
  },
  down: {
    label: 'Down',
    icon: CircleX,
    className: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
  },
}

// ─── Error Table Columns ────────────────────────────────────

type ErrorRow = ConnectorHealthError

// ─── Chart Fallback ─────────────────────────────────────────

function ChartSkeleton() {
  return <Skeleton className="h-64 w-full" />
}

// ─── Loading Skeleton ───────────────────────────────────────

function HealthSkeleton() {
  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <Skeleton className="h-7 w-7" />
        <Skeleton className="h-8 w-48" />
      </div>
      <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
        <Skeleton className="h-24" />
        <Skeleton className="h-24" />
        <Skeleton className="h-24" />
        <Skeleton className="h-24" />
      </div>
      <Skeleton className="h-64" />
      <Skeleton className="h-64" />
    </div>
  )
}

// ─── Page Component ─────────────────────────────────────────

export function ConnectorHealthPage() {
  const { t } = useTranslation()
  const { connectorKey } = useParams({ strict: false }) as {
    connectorKey: string
  }
  const navigate = useNavigate()
  const density = usePreferencesStore((s) => s.density)
  const [period, setPeriod] = useState<HealthPeriod>('24h')
  const [isPending, startTransition] = useTransition()

  const healthQuery = useConnectorHealth(connectorKey)
  const historyQuery = useConnectorHealthHistory(connectorKey, period)

  const health = healthQuery.data
  const history = historyQuery.data?.data ?? []

  const PERIOD_OPTIONS = useMemo<{ value: HealthPeriod; label: string }[]>(
    () => [
      { value: '24h', label: t('connectorHealth.period24h') },
      { value: '7d', label: t('connectorHealth.period7d') },
      { value: '30d', label: t('connectorHealth.period30d') },
    ],
    [t],
  )

  const handlePeriodChange = (v: string) => {
    startTransition(() => setPeriod(v as HealthPeriod))
  }

  const errorColumns: ColumnDef<ErrorRow, unknown>[] = useMemo(
    () => [
      {
        accessorKey: 'timestamp',
        header: t('connectorHealth.columnTime'),
        size: 180,
        cell: ({ row }) => <DateFormat date={row.original.timestamp} />,
        enableSorting: false,
      },
      {
        accessorKey: 'operation',
        header: t('connectorHealth.columnOperation'),
        size: 140,
        cell: ({ row }) => <Badge variant="outline">{row.original.operation}</Badge>,
        enableSorting: false,
      },
      {
        accessorKey: 'error_code',
        header: t('connectorHealth.columnErrorCode'),
        size: 140,
        cell: ({ row }) => (
          <span className="font-mono text-sm">{row.original.error_code}</span>
        ),
        enableSorting: false,
      },
      {
        accessorKey: 'error_message',
        header: t('connectorHealth.columnMessage'),
        cell: ({ row }) => (
          <span className="text-muted-foreground text-sm">{row.original.error_message}</span>
        ),
        enableSorting: false,
      },
    ],
    [t],
  )

  // Error table
  const errorRows = useMemo<ErrorRow[]>(() => health?.recent_errors ?? [], [health])

  const errorTable = useReactTable({
    data: errorRows,
    columns: errorColumns,
    getCoreRowModel: getCoreRowModel(),
  })

  if (healthQuery.isLoading) return <HealthSkeleton />

  if (healthQuery.isError) {
    return <ErrorState status={500} onRetry={() => void healthQuery.refetch()} />
  }

  if (!health) {
    return <ErrorState status={404} />
  }

  const statusConfig = STATUS_CONFIG[health.status]
  const StatusIcon = statusConfig.icon

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Button
            variant="ghost"
            size="icon"
            onClick={() => void navigate({ to: '/connectors' })}
          >
            <ArrowLeft className="h-5 w-5" />
          </Button>
          <Activity className="text-muted-foreground h-7 w-7" />
          <div>
            <h1 className="text-3xl font-bold">{health.connector_name}</h1>
            <span className="text-muted-foreground font-mono text-sm">
              {health.connector_key}
            </span>
          </div>
          <Badge variant="outline" className={statusConfig.className}>
            <StatusIcon className="mr-1 h-3.5 w-3.5" />
            {statusConfig.label}
          </Badge>
        </div>

        <Select value={period} onValueChange={handlePeriodChange}>
          <SelectTrigger className="w-36">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {PERIOD_OPTIONS.map((opt) => (
              <SelectItem key={opt.value} value={opt.value}>
                {opt.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      {/* KPI Cards */}
      <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-muted-foreground text-sm font-medium">
              {t('connectorHealth.uptime')}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-bold">{health.uptime_percent.toFixed(2)}%</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-muted-foreground text-sm font-medium">
              {t('connectorHealth.avgLatency')}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-bold">{health.avg_latency_ms} мс</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-muted-foreground text-sm font-medium">
              {t('connectorHealth.errorRate')}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-bold">{health.error_rate_percent.toFixed(2)}%</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-muted-foreground text-sm font-medium">
              {t('connectorHealth.successRate')}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-bold">
              {health.success_rate_percent.toFixed(2)}%
            </p>
          </CardContent>
        </Card>
      </div>

      {/* Charts */}
      <div className={`grid grid-cols-1 gap-6 lg:grid-cols-2${isPending ? ' opacity-60 transition-opacity' : ''}`}>
        <Card>
          <CardHeader>
            <CardTitle>{t('connectorHealth.chartLatency')}</CardTitle>
          </CardHeader>
          <CardContent>
            <React.Suspense fallback={<ChartSkeleton />}>
              <LatencyChart data={history} />
            </React.Suspense>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{t('connectorHealth.chartErrorRate')}</CardTitle>
          </CardHeader>
          <CardContent>
            <React.Suspense fallback={<ChartSkeleton />}>
              <ErrorRateChart data={history} />
            </React.Suspense>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{t('connectorHealth.chartOperations')}</CardTitle>
          </CardHeader>
          <CardContent>
            <React.Suspense fallback={<ChartSkeleton />}>
              <OperationsBarChart data={health.operations} />
            </React.Suspense>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{t('connectorHealth.chartComparison')}</CardTitle>
          </CardHeader>
          <CardContent>
            <React.Suspense fallback={<ChartSkeleton />}>
              <ComparisonBarChart data={health.comparison} />
            </React.Suspense>
          </CardContent>
        </Card>
      </div>

      {/* Recent Errors Table */}
      <Card>
        <CardHeader>
          <CardTitle>{t('connectorHealth.cardErrors')}</CardTitle>
        </CardHeader>
        <CardContent>
          <DataTable
            table={errorTable}
            columns={errorColumns}
            isLoading={false}
            isError={false}
            emptyTitle={t('connectorHealth.emptyErrors')}
            emptyDescription={t('connectorHealth.emptyErrorsDesc')}
            density={density}
          />
        </CardContent>
      </Card>
    </div>
  )
}
