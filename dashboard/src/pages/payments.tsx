import { useMemo, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { useReactTable, getCoreRowModel, type ColumnDef } from '@tanstack/react-table'
import { CreditCard } from 'lucide-react'

import type { PaymentIntentAttributes } from '@/api/types'
import { PAYMENT_STATUS_LABELS } from '@/api/types'
import type { CaptureMethod } from '@/api/types'
import {
  dashboardPayments,
  type PaymentListParams,
} from '@/api/endpoints/dashboard-payments'
import { usePaymentsList } from '@/hooks/use-payments'
import {
  DataTable,
  ColumnHeader,
  TableFilters,
  TableExport,
  TableDensityToggle,
  useTableSorting,
  useTablePagination,
  useTableFilters,
  type FilterDef,
} from '@/components/data-table'
import { StatusBadge } from '@/components/shared/status-badge'
import { MoneyFormat } from '@/components/shared/money-format'
import { DateFormat } from '@/components/shared/date-format'
import { CopyButton } from '@/components/shared/copy-button'
import { usePreferencesStore } from '@/stores/preferences'

// ─── Row Type ────────────────────────────────────────────────

type PaymentRow = PaymentIntentAttributes & { id: string }

// ─── Module-level Constants ──────────────────────────────────

const STATUS_OPTIONS = Object.entries(PAYMENT_STATUS_LABELS).map(([value, label]) => ({
  value,
  label,
}))

const CAPTURE_METHOD_OPTIONS = [
  { value: 'automatic', label: 'Automatic' },
  { value: 'manual', label: 'Manual' },
]

// ─── Page Component ─────────────────────────────────────────

export function PaymentsPage() {
  const { t } = useTranslation()
  const density = usePreferencesStore((s) => s.density)

  // ─── Filter Definitions ─────────────────────────────────────

  const filterDefs: FilterDef[] = useMemo(
    () => [
      {
        type: 'select',
        key: 'status',
        label: t('payments.filterStatus'),
        options: STATUS_OPTIONS,
      },
      {
        type: 'select',
        key: 'connector',
        label: t('payments.filterConnector'),
        options: [
          { value: 'stripe', label: 'Stripe' },
          { value: 'cloudpayments', label: 'CloudPayments' },
          { value: 'test', label: 'Test' },
        ],
      },
      {
        type: 'select',
        key: 'currency',
        label: t('payments.filterCurrency'),
        options: [
          { value: 'RUB', label: 'RUB' },
          { value: 'USD', label: 'USD' },
          { value: 'EUR', label: 'EUR' },
        ],
      },
      {
        type: 'number-range',
        key: 'amount',
        label: t('payments.filterAmount'),
        min: 0,
      },
      {
        type: 'date-range',
        key: 'date',
        label: t('payments.filterDate'),
      },
      {
        type: 'select',
        key: 'capture_method',
        label: t('payments.filterCaptureMethod'),
        options: CAPTURE_METHOD_OPTIONS,
      },
      {
        type: 'text',
        key: 'search',
        label: t('payments.filterSearch'),
        placeholder: t('payments.filterSearchPlaceholder'),
      },
    ],
    [t],
  )

  // ─── Columns ────────────────────────────────────────────────

  const columns: ColumnDef<PaymentRow, unknown>[] = useMemo(
    () => [
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
        accessorKey: 'amount',
        header: ({ column }) => (
          <ColumnHeader column={column} title={t('payments.columnAmount')} />
        ),
        cell: ({ row }) => (
          <MoneyFormat amount={row.original.amount} currency={row.original.currency} />
        ),
        enableSorting: true,
      },
      {
        accessorKey: 'currency',
        header: t('payments.filterCurrency'),
        size: 80,
        cell: ({ row }) => (
          <span className="text-muted-foreground text-xs uppercase">
            {row.original.currency}
          </span>
        ),
        enableSorting: false,
      },
      {
        accessorKey: 'status',
        header: ({ column }) => (
          <ColumnHeader column={column} title={t('payments.columnStatus')} />
        ),
        cell: ({ row }) => <StatusBadge status={row.original.status} />,
        enableSorting: true,
      },
      {
        accessorKey: 'connector',
        header: t('payments.columnConnector'),
        cell: ({ row }) => row.original.connector ?? '—',
        enableSorting: false,
        meta: { hiddenOnMobile: true },
      },
      {
        accessorKey: 'customer_id',
        header: t('payments.columnCustomer'),
        cell: ({ row }) => (
          <span className="font-mono text-xs">{row.original.customer_id ?? '—'}</span>
        ),
        enableSorting: false,
        meta: { hiddenOnMobile: true },
      },
      {
        accessorKey: 'capture_method',
        header: t('payments.columnCaptureMethod'),
        cell: ({ row }) => (
          <span className="text-muted-foreground text-xs capitalize">
            {row.original.capture_method}
          </span>
        ),
        enableSorting: false,
        meta: { hiddenOnMobile: true },
      },
      {
        accessorKey: 'attempt_count',
        header: t('payments.columnAttempts'),
        size: 90,
        cell: ({ row }) => row.original.attempt_count,
        enableSorting: false,
        meta: { hiddenOnMobile: true },
      },
      {
        accessorKey: 'created_at',
        header: ({ column }) => (
          <ColumnHeader column={column} title={t('payments.columnDate')} />
        ),
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
  const baseParams = useMemo<PaymentListParams>(() => {
    const params: PaymentListParams = {}

    if (filterValues.status) params.status = filterValues.status
    if (filterValues.connector) params.connector = filterValues.connector
    if (filterValues.currency) params.currency = filterValues.currency
    if (filterValues.capture_method)
      params.capture_method = filterValues.capture_method as CaptureMethod
    if (filterValues.search) params.search = filterValues.search

    // Amount range: stored as "min,max"
    if (filterValues.amount) {
      const [from, to] = filterValues.amount.split(',')
      if (from) params.amount_min = from
      if (to) params.amount_max = to
    }

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

  // We need pagination meta from a preliminary fetch to set up useTablePagination,
  // but we also need currentPage/perPage from URL. Use a single query with URL params.
  const { pagination, currentPage, perPage, onPageChange, onPerPageChange } =
    useTablePagination(undefined)

  // Full params including pagination
  const fullParams = useMemo<PaymentListParams>(
    () => ({
      ...baseParams,
      page: currentPage,
      per_page: perPage,
    }),
    [baseParams, currentPage, perPage],
  )

  // Fetch data
  const query = usePaymentsList(fullParams)

  // Transform data for table
  const rows = useMemo<PaymentRow[]>(() => {
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

  // Export handler
  const handleExport = useCallback(
    () => dashboardPayments.exportCsv(fullParams),
    [fullParams],
  )

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
          <CreditCard className="text-muted-foreground h-7 w-7" />
          <h1 className="text-3xl font-bold">{t('payments.title')}</h1>
        </div>
        <TableDensityToggle />
      </div>

      <DataTable
        table={table}
        columns={columns}
        isLoading={query.isLoading}
        isError={query.isError}
        onRetry={() => void query.refetch()}
        emptyTitle={t('payments.emptyTitle')}
        emptyDescription={t('payments.emptyDesc')}
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
            extra={<TableExport onExport={handleExport} filename="payments.csv" />}
          />
        }
      />
    </div>
  )
}
