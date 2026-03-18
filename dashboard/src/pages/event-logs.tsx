import { useMemo, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { useReactTable, getCoreRowModel, type ColumnDef } from '@tanstack/react-table'
import { Link } from '@tanstack/react-router'
import { ScrollText } from 'lucide-react'

import type {
  EventLogAttributes,
  EventLogListParams,
} from '@/api/endpoints/dashboard-event-logs'
import { useEventLogsList } from '@/hooks/use-event-logs'
import {
  DataTable,
  TableFilters,
  TableDensityToggle,
  useTablePagination,
  useTableFilters,
  type FilterDef,
} from '@/components/data-table'
import { DateFormat } from '@/components/shared/date-format'
import { Badge } from '@/components/ui/badge'
import { usePreferencesStore } from '@/stores/preferences'

// ─── Row Type ────────────────────────────────────────────────

type EventLogRow = EventLogAttributes & { id: string }

// ─── Resource Link Helper ────────────────────────────────────

function ResourceLink({ resourceId }: { resourceId: string | number | null }) {
  const id = String(resourceId ?? '')
  if (id.startsWith('pay_')) {
    return (
      <Link
        to="/payments/$paymentKey"
        params={{ paymentKey: id }}
        className="text-primary font-mono text-xs hover:underline"
      >
        {id}
      </Link>
    )
  }

  if (id.startsWith('ref_')) {
    return (
      <Link to="/refunds" className="text-primary font-mono text-xs hover:underline">
        {id}
      </Link>
    )
  }

  return <span className="font-mono text-xs">{id}</span>
}

// ─── Page Component ─────────────────────────────────────────

export function EventLogsPage() {
  const { t } = useTranslation()
  const density = usePreferencesStore((s) => s.density)

  // ─── Filter Definitions ─────────────────────────────────────

  const EVENT_TYPE_OPTIONS = [
    { value: 'webhook', label: t('eventLogs.typeWebhook') },
    { value: 'status_change', label: t('eventLogs.typeStatusChange') },
  ]

  const filterDefs: FilterDef[] = [
    {
      type: 'select',
      key: 'type',
      label: t('eventLogs.filterType'),
      options: EVENT_TYPE_OPTIONS,
    },
    {
      type: 'date-range',
      key: 'date',
      label: t('eventLogs.filterDate'),
    },
  ]

  // ─── Columns ────────────────────────────────────────────────

  const columns: ColumnDef<EventLogRow, unknown>[] = [
    {
      accessorKey: 'created_at',
      header: t('eventLogs.columnTime'),
      cell: ({ row }) => <DateFormat date={row.original.created_at} />,
      enableSorting: false,
    },
    {
      accessorKey: 'event_type',
      header: t('eventLogs.columnType'),
      cell: ({ row }) => (
        <Badge variant="outline" className="font-mono text-xs">
          {row.original.event_type}
        </Badge>
      ),
      enableSorting: false,
    },
    {
      accessorKey: 'action',
      header: t('eventLogs.columnAction'),
      cell: ({ row }) => (
        <Badge variant="secondary" className="font-mono text-xs">
          {row.original.action}
        </Badge>
      ),
      enableSorting: false,
    },
    {
      accessorKey: 'resource_id',
      header: t('eventLogs.columnResource'),
      cell: ({ row }) => <ResourceLink resourceId={row.original.resource_id} />,
      enableSorting: false,
    },
    {
      accessorKey: 'status',
      header: t('eventLogs.columnStatus'),
      cell: ({ row }) => (
        <Badge variant="outline" className="text-xs">
          {row.original.status}
        </Badge>
      ),
      enableSorting: false,
    },
    {
      accessorKey: 'detail',
      header: t('eventLogs.columnDetail'),
      cell: ({ row }) => (
        <span className="text-muted-foreground text-sm">{row.original.detail ?? '—'}</span>
      ),
      enableSorting: false,
      meta: { hiddenOnMobile: true },
    },
  ]

  const {
    values: filterValues,
    onChange: onFilterChange,
    onReset: onFilterReset,
  } = useTableFilters(filterDefs)

  const baseParams = useMemo<EventLogListParams>(() => {
    const params: EventLogListParams = {}

    if (filterValues.type) params.type = filterValues.type

    if (filterValues.date) {
      const [from, to] = filterValues.date.split(',')
      if (from) params.from = from
      if (to) params.to = to
    }

    return params
  }, [filterValues])

  const { pagination, currentPage, perPage, onPageChange, onPerPageChange } =
    useTablePagination(undefined)

  const fullParams = useMemo<EventLogListParams>(
    () => ({
      ...baseParams,
      page: currentPage,
      per_page: perPage,
    }),
    [baseParams, currentPage, perPage],
  )

  const query = useEventLogsList(fullParams)

  const rows = useMemo<EventLogRow[]>(() => {
    return query.data?.items ?? []
  }, [query.data])

  const table = useReactTable({
    data: rows,
    columns,
    getCoreRowModel: getCoreRowModel(),
    manualPagination: true,
  })

  const handleRetry = useCallback(() => {
    void query.refetch()
  }, [query])

  const paginationState = query.data?.meta
    ? {
        currentPage: query.data.meta.current_page,
        lastPage: query.data.meta.last_page,
        perPage: query.data.meta.per_page,
        total: query.data.meta.total,
      }
    : pagination

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <ScrollText className="text-muted-foreground h-7 w-7" />
          <h1 className="text-3xl font-bold">{t('eventLogs.title')}</h1>
        </div>
        <TableDensityToggle />
      </div>

      <DataTable
        table={table}
        columns={columns}
        isLoading={query.isLoading}
        isError={query.isError}
        onRetry={handleRetry}
        emptyTitle={t('eventLogs.emptyTitle')}
        emptyDescription={t('eventLogs.emptyDesc')}
        pagination={paginationState}
        onPageChange={onPageChange}
        onPerPageChange={onPerPageChange}
        density={density}
        toolbar={
          <TableFilters
            filters={filterDefs}
            values={filterValues}
            onChange={onFilterChange}
            onReset={onFilterReset}
          />
        }
      />
    </div>
  )
}
