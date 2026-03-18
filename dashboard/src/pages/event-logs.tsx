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
  ColumnHeader,
  TableFilters,
  TableDensityToggle,
  useTableSorting,
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

function ResourceLink({ resourceId }: { resourceId: string }) {
  if (resourceId.startsWith('pay_')) {
    return (
      <Link
        to="/payments/$paymentKey"
        params={{ paymentKey: resourceId }}
        className="text-primary font-mono text-xs hover:underline"
      >
        {resourceId}
      </Link>
    )
  }

  if (resourceId.startsWith('ref_')) {
    return (
      <Link to="/refunds" className="text-primary font-mono text-xs hover:underline">
        {resourceId}
      </Link>
    )
  }

  return <span className="font-mono text-xs">{resourceId}</span>
}

// ─── Page Component ─────────────────────────────────────────

export function EventLogsPage() {
  const { t } = useTranslation()
  const density = usePreferencesStore((s) => s.density)

  // ─── Filter Definitions ─────────────────────────────────────

  const EVENT_TYPE_OPTIONS = [
    { value: 'payment.succeeded', label: 'payment.succeeded' },
    { value: 'payment.failed', label: 'payment.failed' },
    { value: 'payment.processing', label: 'payment.processing' },
    { value: 'payment.cancelled', label: 'payment.cancelled' },
    { value: 'payment.captured', label: 'payment.captured' },
    { value: 'payment.authorized', label: 'payment.authorized' },
    { value: 'refund.created', label: 'refund.created' },
    { value: 'refund.succeeded', label: 'refund.succeeded' },
    { value: 'refund.failed', label: 'refund.failed' },
  ]

  const RESOURCE_TYPE_OPTIONS = [
    { value: 'payment', label: t('eventLogs.resourcePayments') },
    { value: 'refund', label: t('eventLogs.resourceRefunds') },
    { value: 'customer', label: t('eventLogs.resourceCustomers') },
    { value: 'connector', label: t('eventLogs.resourceConnectors') },
  ]

  const filterDefs: FilterDef[] = [
    {
      type: 'select',
      key: 'type',
      label: t('eventLogs.filterType'),
      options: EVENT_TYPE_OPTIONS,
    },
    {
      type: 'select',
      key: 'resource_type',
      label: t('eventLogs.filterResource'),
      options: RESOURCE_TYPE_OPTIONS,
    },
    {
      type: 'date-range',
      key: 'date',
      label: t('eventLogs.filterDate'),
    },
    {
      type: 'text',
      key: 'search',
      label: t('eventLogs.filterSearch'),
      placeholder: t('eventLogs.filterSearchPlaceholder'),
    },
  ]

  // ─── Columns ────────────────────────────────────────────────

  const columns: ColumnDef<EventLogRow, unknown>[] = [
    {
      accessorKey: 'created_at',
      header: ({ column }) => <ColumnHeader column={column} title={t('eventLogs.columnTime')} />,
      cell: ({ row }) => <DateFormat date={row.original.created_at} />,
      enableSorting: true,
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
      accessorKey: 'resource_id',
      header: t('eventLogs.columnResource'),
      cell: ({ row }) => <ResourceLink resourceId={row.original.resource_id} />,
      enableSorting: false,
    },
    {
      accessorKey: 'description',
      header: t('eventLogs.columnDescription'),
      cell: ({ row }) => (
        <span className="text-muted-foreground text-sm">{row.original.description}</span>
      ),
      enableSorting: false,
    },
    {
      accessorKey: 'connector',
      header: t('eventLogs.columnConnector'),
      cell: ({ row }) => row.original.connector ?? '—',
      enableSorting: false,
      meta: { hiddenOnMobile: true },
    },
  ]

  const { sorting, onSortingChange } = useTableSorting({
    defaultSort: 'created_at',
    defaultOrder: 'desc',
  })

  const {
    values: filterValues,
    onChange: onFilterChange,
    onReset: onFilterReset,
  } = useTableFilters(filterDefs)

  const baseParams = useMemo<EventLogListParams>(() => {
    const params: EventLogListParams = {}

    if (filterValues.type) params.type = filterValues.type
    if (filterValues.resource_type) params.resource_type = filterValues.resource_type
    if (filterValues.search) params.search = filterValues.search

    if (filterValues.date) {
      const [from, to] = filterValues.date.split(',')
      if (from) params.from = from
      if (to) params.to = to
    }

    if (sorting.length > 0) {
      params.sort = sorting[0]!.id
      params.direction = sorting[0]!.desc ? 'desc' : 'asc'
    }

    return params
  }, [filterValues, sorting])

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
    manualSorting: true,
    manualPagination: true,
    state: { sorting },
    onSortingChange,
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
