import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { useReactTable, getCoreRowModel, type ColumnDef } from '@tanstack/react-table'
import { Link } from '@tanstack/react-router'
import { RotateCcw } from 'lucide-react'

import type { RefundAttributes } from '@/api/types'
import { REFUND_STATUS_LABELS } from '@/api/types'
import { type RefundListParams } from '@/api/endpoints/dashboard-refunds'
import { useRefundsList } from '@/hooks/use-refunds'
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
import { StatusBadge } from '@/components/shared/status-badge'
import { MoneyFormat } from '@/components/shared/money-format'
import { DateFormat } from '@/components/shared/date-format'
import { CopyButton } from '@/components/shared/copy-button'
import { usePreferencesStore } from '@/stores/preferences'

// ─── Row Type ────────────────────────────────────────────────

type RefundRow = RefundAttributes & { id: string }

// ─── Module-level Constants ──────────────────────────────────

const STATUS_OPTIONS = Object.entries(REFUND_STATUS_LABELS).map(([value, label]) => ({
  value,
  label,
}))

// ─── Page Component ─────────────────────────────────────────

export function RefundsPage() {
  const { t } = useTranslation()
  const density = usePreferencesStore((s) => s.density)

  // ─── Filter Definitions ─────────────────────────────────────

  const filterDefs: FilterDef[] = useMemo(
    () => [
      {
        type: 'select',
        key: 'status',
        label: t('common.status'),
        options: STATUS_OPTIONS,
      },
      {
        type: 'date-range',
        key: 'date',
        label: t('common.date'),
      },
      {
        type: 'text',
        key: 'search',
        label: t('payments.filterSearch'),
        placeholder: 'ref_...',
      },
    ],
    [t],
  )

  // ─── Columns ────────────────────────────────────────────────

  const columns: ColumnDef<RefundRow, unknown>[] = useMemo(
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
        accessorKey: 'payment_id',
        header: t('refunds.columnPayment'),
        size: 220,
        cell: ({ row }) => {
          const paymentId = row.original.payment_id
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
        accessorKey: 'amount',
        header: ({ column }) => (
          <ColumnHeader column={column} title={t('common.amount')} />
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
          <ColumnHeader column={column} title={t('common.status')} />
        ),
        cell: ({ row }) => <StatusBadge status={row.original.status} />,
        enableSorting: false,
      },
      {
        accessorKey: 'reason',
        header: t('refunds.columnReason'),
        cell: ({ row }) => row.original.reason ?? '—',
        enableSorting: false,
        meta: { hiddenOnMobile: true },
      },
      {
        accessorKey: 'connector',
        header: t('common.connector'),
        cell: ({ row }) => row.original.connector ?? '—',
        enableSorting: false,
        meta: { hiddenOnMobile: true },
      },
      {
        accessorKey: 'created_at',
        header: ({ column }) => <ColumnHeader column={column} title={t('common.date')} />,
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
  const baseParams = useMemo<RefundListParams>(() => {
    const params: RefundListParams = {}

    if (filterValues.status) params.status = filterValues.status
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
  const fullParams = useMemo<RefundListParams>(
    () => ({
      ...baseParams,
      page: currentPage,
      per_page: perPage,
    }),
    [baseParams, currentPage, perPage],
  )

  // Fetch data
  const query = useRefundsList(fullParams)

  // Transform data for table
  const rows = useMemo<RefundRow[]>(() => {
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
          <RotateCcw className="text-muted-foreground h-7 w-7" />
          <h1 className="text-3xl font-bold">{t('refunds.title')}</h1>
        </div>
        <TableDensityToggle />
      </div>

      <DataTable
        table={table}
        columns={columns}
        isLoading={query.isLoading}
        isError={query.isError}
        onRetry={() => void query.refetch()}
        emptyTitle={t('refunds.emptyTitle')}
        emptyDescription={t('refunds.emptyDesc')}
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
