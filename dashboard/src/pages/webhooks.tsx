import { useMemo, useState, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import {
  useReactTable,
  getCoreRowModel,
  getExpandedRowModel,
  type ColumnDef,
  type ExpandedState,
} from '@tanstack/react-table'
import { Link } from '@tanstack/react-router'
import {
  Webhook,
  CheckCircle2,
  XCircle,
  RefreshCw,
  RotateCcw,
  ChevronDown,
  ChevronRight,
} from 'lucide-react'

import type { WebhookEventAttributes } from '@/api/types'
import type { WebhookEventType } from '@/api/types'
import { type WebhookListParams } from '@/api/endpoints/dashboard-webhooks'
import { useWebhooksList, useRetryWebhook } from '@/hooks/use-webhooks'
import {
  DataTable,
  ColumnHeader,
  TableFilters,
  TableDensityToggle,
  TableSelection,
  createSelectionColumn,
  useTableSorting,
  useTablePagination,
  useTableFilters,
  type FilterDef,
} from '@/components/data-table'
import { DateFormat } from '@/components/shared/date-format'
import { CopyButton } from '@/components/shared/copy-button'
import { JsonViewer } from '@/components/shared/json-viewer'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { usePreferencesStore } from '@/stores/preferences'

// ─── Row Type ────────────────────────────────────────────────

type WebhookRow = WebhookEventAttributes & {
  id: string
  payload?: Record<string, unknown>
  delivery_log?: DeliveryAttempt[]
  payment_id?: string
}

interface DeliveryAttempt {
  attempt: number
  status_code: number | null
  created_at: string
}

// ─── Constants ──────────────────────────────────────────────

const EVENT_TYPE_OPTIONS: { value: WebhookEventType; label: string }[] = [
  { value: 'payment_succeeded', label: 'payment.succeeded' },
  { value: 'payment_captured', label: 'payment.captured' },
  { value: 'payment_cancelled', label: 'payment.cancelled' },
  { value: 'payment_authorized', label: 'payment.authorized' },
  { value: 'payment_status_changed', label: 'payment.status_changed' },
  { value: 'payment_failed', label: 'payment.failed' },
  { value: 'payment_processing', label: 'payment.processing' },
  { value: 'action_required', label: 'action.required' },
  { value: 'refund_succeeded', label: 'refund.succeeded' },
  { value: 'refund_failed', label: 'refund.failed' },
]

// ─── Event Type Display ──────────────────────────────────────

function formatEventType(eventType: string): string {
  return eventType.replace(/_/g, '.')
}

// ─── Delivery Status Icon ────────────────────────────────────

function DeliveryIcon({
  delivered,
  nextRetryAt,
}: {
  delivered: boolean
  nextRetryAt: string | null
}) {
  const { t } = useTranslation()

  if (delivered) {
    return (
      <Tooltip>
        <TooltipTrigger asChild>
          <CheckCircle2 className="h-4 w-4 text-emerald-600" />
        </TooltipTrigger>
        <TooltipContent>{t('webhooks.deliveryIconDelivered')}</TooltipContent>
      </Tooltip>
    )
  }

  if (nextRetryAt) {
    return (
      <Tooltip>
        <TooltipTrigger asChild>
          <RefreshCw className="h-4 w-4 text-amber-500" />
        </TooltipTrigger>
        <TooltipContent>{t('webhooks.deliveryIconPending')}</TooltipContent>
      </Tooltip>
    )
  }

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <XCircle className="h-4 w-4 text-red-500" />
      </TooltipTrigger>
      <TooltipContent>{t('webhooks.deliveryIconFailed')}</TooltipContent>
    </Tooltip>
  )
}

// ─── Expanded Row Content ────────────────────────────────────

function ExpandedRowContent({
  row,
  onRetry,
  isRetrying,
}: {
  row: WebhookRow
  onRetry: (id: string) => void
  isRetrying: boolean
}) {
  const { t } = useTranslation()

  return (
    <div className="space-y-4 p-4">
      {/* Payload */}
      {row.payload && (
        <div>
          <h4 className="mb-2 text-sm font-medium">{t('webhooks.expandedPayload')}</h4>
          <JsonViewer data={row.payload} defaultExpanded={false} className="max-h-64" />
        </div>
      )}

      {/* Delivery attempts */}
      {row.delivery_log && row.delivery_log.length > 0 && (
        <div>
          <h4 className="mb-2 text-sm font-medium">{t('webhooks.expandedAttempts')}</h4>
          <div className="rounded-md border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-20">{t('webhooks.expandedAttemptIndex')}</TableHead>
                  <TableHead className="w-32">{t('webhooks.expandedStatusCode')}</TableHead>
                  <TableHead>{t('webhooks.columnDate')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {row.delivery_log.map((attempt) => (
                  <TableRow key={attempt.attempt}>
                    <TableCell className="font-mono text-sm">{attempt.attempt}</TableCell>
                    <TableCell>
                      <Badge
                        variant="outline"
                        className={
                          attempt.status_code !== null &&
                          attempt.status_code >= 200 &&
                          attempt.status_code < 300
                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                            : 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300'
                        }
                      >
                        {attempt.status_code ?? 'timeout'}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      <DateFormat date={attempt.created_at} />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </div>
      )}

      {/* Retry button */}
      {!row.delivered && (
        <Button
          variant="outline"
          size="sm"
          disabled={isRetrying}
          onClick={() => onRetry(row.id)}
        >
          <RotateCcw className="mr-2 h-3.5 w-3.5" />
          {isRetrying ? t('webhooks.retrying') : t('webhooks.retryButton')}
        </Button>
      )}
    </div>
  )
}

// ─── Page Component ─────────────────────────────────────────

export function WebhooksPage() {
  const { t } = useTranslation()
  const density = usePreferencesStore((s) => s.density)
  const retryMutation = useRetryWebhook()
  const [expanded, setExpanded] = useState<ExpandedState>({})
  const [rowSelection, setRowSelection] = useState<Record<string, boolean>>({})

  const DELIVERY_STATUS_OPTIONS = useMemo(
    () => [
      { value: 'true', label: t('webhooks.deliveryDelivered') },
      { value: 'false', label: t('webhooks.deliveryNotDelivered') },
    ],
    [t],
  )

  const filterDefs: FilterDef[] = useMemo(
    () => [
      {
        type: 'select',
        key: 'type',
        label: t('webhooks.filterEventType'),
        options: EVENT_TYPE_OPTIONS,
      },
      {
        type: 'select',
        key: 'delivered',
        label: t('webhooks.filterDelivery'),
        options: DELIVERY_STATUS_OPTIONS,
      },
      {
        type: 'date-range',
        key: 'date',
        label: t('webhooks.columnDate'),
      },
      {
        type: 'text',
        key: 'search',
        label: t('common.search'),
        placeholder: 'evt_...',
      },
    ],
    [t, DELIVERY_STATUS_OPTIONS],
  )

  // ─── Columns ────────────────────────────────────────────────

  const columns: ColumnDef<WebhookRow, unknown>[] = useMemo(
    () => [
      createSelectionColumn<WebhookRow>(),
      {
        id: 'expander',
        header: '',
        size: 32,
        cell: ({ row }) => (
          <button
            type="button"
            className="cursor-pointer p-1"
            onClick={() => row.toggleExpanded()}
            aria-label={row.getIsExpanded() ? t('common.collapse') : t('common.expand')}
          >
            {row.getIsExpanded() ? (
              <ChevronDown className="h-4 w-4" />
            ) : (
              <ChevronRight className="h-4 w-4" />
            )}
          </button>
        ),
        enableSorting: false,
      },
      {
        accessorKey: 'id',
        header: t('common.id'),
        size: 220,
        cell: ({ row }) => {
          const id = row.original.id
          return (
            <div className="flex items-center gap-1">
              <span className="font-mono text-sm">{id}</span>
              <CopyButton value={id} />
            </div>
          )
        },
        enableSorting: false,
      },
      {
        accessorKey: 'event_type',
        header: t('webhooks.columnType'),
        size: 200,
        cell: ({ row }) => (
          <Badge variant="outline" className="font-mono text-xs">
            {formatEventType(row.original.event_type)}
          </Badge>
        ),
        enableSorting: false,
      },
      {
        accessorKey: 'payment_id',
        header: t('webhooks.columnPayment'),
        size: 220,
        cell: ({ row }) => {
          const paymentId = row.original.payment_id
          if (!paymentId) return <span className="text-muted-foreground">—</span>
          return (
            <Link
              to="/payments/$paymentKey"
              params={{ paymentKey: paymentId }}
              className="text-primary font-mono text-sm hover:underline"
            >
              {paymentId}
            </Link>
          )
        },
        enableSorting: false,
      },
      {
        accessorKey: 'delivered',
        header: t('webhooks.columnDelivered'),
        size: 100,
        cell: ({ row }) => (
          <DeliveryIcon
            delivered={row.original.delivered}
            nextRetryAt={row.original.next_retry_at}
          />
        ),
        enableSorting: false,
      },
      {
        accessorKey: 'delivery_attempts',
        header: ({ column }) => <ColumnHeader column={column} title={t('webhooks.columnAttempts')} />,
        size: 100,
        cell: ({ row }) => (
          <span className="font-mono text-sm">{row.original.delivery_attempts}</span>
        ),
        enableSorting: true,
      },
      {
        accessorKey: 'last_error',
        header: t('webhooks.columnError'),
        size: 200,
        cell: ({ row }) => {
          const error = row.original.last_error
          if (!error) return <span className="text-muted-foreground">—</span>
          return (
            <Tooltip>
              <TooltipTrigger asChild>
                <span className="text-destructive block max-w-[180px] truncate text-sm">
                  {error}
                </span>
              </TooltipTrigger>
              <TooltipContent className="max-w-sm">
                <p className="break-words">{error}</p>
              </TooltipContent>
            </Tooltip>
          )
        },
        enableSorting: false,
        meta: { hiddenOnMobile: true },
      },
      {
        accessorKey: 'created_at',
        header: ({ column }) => <ColumnHeader column={column} title={t('webhooks.columnDate')} />,
        cell: ({ row }) => <DateFormat date={row.original.created_at} />,
        enableSorting: true,
      },
    ],
    [t],
  )

  // URL-synced sorting, pagination, filters
  const { sorting, onSortingChange } = useTableSorting({
    defaultSort: 'created_at',
    defaultOrder: 'desc',
  })
  const {
    values: filterValues,
    onChange: onFilterChange,
    onReset: onFilterReset,
  } = useTableFilters(filterDefs)

  // Build API params from URL state
  const baseParams = useMemo<WebhookListParams>(() => {
    const params: WebhookListParams = {}

    if (filterValues.type) params.type = filterValues.type
    if (filterValues.delivered) params.delivered = filterValues.delivered
    if (filterValues.search) params.search = filterValues.search

    // Date range: stored as "from,to"
    if (filterValues.date) {
      const [from, to] = filterValues.date.split(',')
      if (from) params.from = from
      if (to) params.to = to
    }

    // Sorting
    if (sorting.length > 0) {
      params.sort = sorting[0]!.id
      params.direction = sorting[0]!.desc ? 'desc' : 'asc'
    }

    return params
  }, [filterValues, sorting])

  const { pagination, currentPage, perPage, onPageChange, onPerPageChange } =
    useTablePagination(undefined)

  // Full params including pagination
  const fullParams = useMemo<WebhookListParams>(
    () => ({
      ...baseParams,
      page: currentPage,
      per_page: perPage,
    }),
    [baseParams, currentPage, perPage],
  )

  // Fetch data
  const query = useWebhooksList(fullParams)

  // Transform data for table
  const rows = useMemo<WebhookRow[]>(() => {
    if (!query.data?.items) return []
    return query.data.items.map((item) => ({
      ...item,
      payload: (
        item as WebhookEventAttributes & {
          id: string
          payload?: Record<string, unknown>
        }
      ).payload,
      delivery_log: (
        item as WebhookEventAttributes & {
          id: string
          delivery_log?: DeliveryAttempt[]
        }
      ).delivery_log,
      payment_id: (
        item as WebhookEventAttributes & {
          id: string
          payment_id?: string
        }
      ).payment_id,
    }))
  }, [query.data])

  const table = useReactTable({
    data: rows,
    columns,
    getCoreRowModel: getCoreRowModel(),
    getExpandedRowModel: getExpandedRowModel(),
    manualSorting: true,
    manualPagination: true,
    enableRowSelection: (row) => !row.original.delivered,
    state: { sorting, expanded, rowSelection },
    onSortingChange,
    onExpandedChange: setExpanded,
    onRowSelectionChange: setRowSelection,
    getRowId: (row) => row.id,
  })

  const paginationState = query.data?.meta
    ? {
        currentPage: query.data.meta.current_page,
        lastPage: query.data.meta.last_page,
        perPage: query.data.meta.per_page,
        total: query.data.meta.total,
      }
    : pagination

  // Bulk retry handler
  const selectedRows = table.getFilteredSelectedRowModel().rows
  const selectedCount = selectedRows.length

  const handleRetry = useCallback(
    (id: string) => {
      retryMutation.mutate(id)
    },
    [retryMutation],
  )

  function handleBulkRetry() {
    for (const row of selectedRows) {
      retryMutation.mutate(row.original.id)
    }
    setRowSelection({})
  }

  // Expanded row IDs for rendering detail panels
  const expandedRowIds = useMemo((): Set<string> => {
    if (expanded === true) return new Set(rows.map((r) => r.id))
    const ids = new Set<string>()
    for (const [k, v] of Object.entries(expanded)) {
      if (v) ids.add(k)
    }
    return ids
  }, [expanded, rows])

  const expandedRowMap = useMemo(() => {
    const map = new Map<string, WebhookRow>()
    for (const row of rows) {
      if (expandedRowIds.has(row.id)) {
        map.set(row.id, row)
      }
    }
    return map
  }, [rows, expandedRowIds])

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Webhook className="text-muted-foreground h-7 w-7" />
          <h1 className="text-3xl font-bold">{t('webhooks.title')}</h1>
        </div>
        <TableDensityToggle />
      </div>

      {selectedCount > 0 && (
        <TableSelection
          selectedCount={selectedCount}
          totalCount={table.getFilteredRowModel().rows.length}
          onClearSelection={() => setRowSelection({})}
        >
          <Button
            variant="outline"
            size="sm"
            disabled={retryMutation.isPending}
            onClick={handleBulkRetry}
          >
            <RotateCcw className="mr-2 h-3.5 w-3.5" />
            {t('webhooks.bulkRetry')} ({selectedCount})
          </Button>
        </TableSelection>
      )}

      <DataTable
        table={table}
        columns={columns}
        isLoading={query.isLoading}
        isError={query.isError}
        onRetry={() => void query.refetch()}
        emptyTitle={t('webhooks.emptyTitle')}
        emptyDescription={t('webhooks.emptyDesc')}
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

      {/* Expanded row detail panels */}
      {expandedRowMap.size > 0 &&
        Array.from(expandedRowMap.entries()).map(([id, row]) => (
          <div key={id} className="rounded-md border">
            <div className="bg-muted/30 border-b px-4 py-2">
              <span className="font-mono text-sm font-medium">{id}</span>
              <Badge variant="outline" className="ml-2 font-mono text-xs">
                {formatEventType(row.event_type)}
              </Badge>
            </div>
            <ExpandedRowContent
              row={row}
              onRetry={handleRetry}
              isRetrying={retryMutation.isPending}
            />
          </div>
        ))}
    </div>
  )
}
