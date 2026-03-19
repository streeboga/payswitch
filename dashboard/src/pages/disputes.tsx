import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from '@tanstack/react-router'
import { useReactTable, getCoreRowModel, type ColumnDef } from '@tanstack/react-table'
import { ShieldAlert } from 'lucide-react'
import { isBefore, addDays } from 'date-fns'

import type {
  DisputeAttributes,
  DisputeListParams,
} from '@/api/endpoints/dashboard-disputes'
import { useDisputesList } from '@/hooks/use-disputes'
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
import { Badge } from '@/components/ui/badge'
import { usePreferencesStore } from '@/stores/preferences'
import { cn } from '@/lib/utils'

// ─── Row Type ────────────────────────────────────────────────

type DisputeRow = DisputeAttributes & { id: string }

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

const TYPE_OPTIONS = Object.entries(DISPUTE_TYPE_LABELS).map(([value, label]) => ({
  value,
  label,
}))

const STATUS_OPTIONS = Object.entries(DISPUTE_STATUS_LABELS).map(([value, label]) => ({
  value,
  label,
}))

// ─── Filter Definitions (moved inside component for i18n) ───

// ─── Helpers ─────────────────────────────────────────────────

function isDeadlineUrgent(deadline: string): boolean {
  const deadlineDate = new Date(deadline)
  const threeDaysFromNow = addDays(new Date(), 3)
  return isBefore(deadlineDate, threeDaysFromNow)
}

// ─── Page Component ─────────────────────────────────────────

export function DisputesPage() {
  const { t } = useTranslation()
  const density = usePreferencesStore((s) => s.density)
  const navigate = useNavigate()

  // ─── Filter Definitions (i18n) ──────────────────────────
  const filterDefs = useMemo<FilterDef[]>(
    () => [
      {
        type: 'select',
        key: 'type',
        label: t('disputes.filterType'),
        options: TYPE_OPTIONS,
      },
      {
        type: 'select',
        key: 'status',
        label: t('disputes.filterStatus'),
        options: STATUS_OPTIONS,
      },
      {
        type: 'date-range',
        key: 'date',
        label: t('disputes.filterDate'),
      },
    ],
    [t],
  )

  // ─── Columns (i18n) ──────────────────────────────────────
  const columns = useMemo<ColumnDef<DisputeRow, unknown>[]>(
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
        header: t('disputes.columnPayment'),
        size: 200,
        cell: ({ row }) => (
          <span className="font-mono text-sm text-blue-600 dark:text-blue-400">
            {row.original.payment_id}
          </span>
        ),
        enableSorting: false,
      },
      {
        accessorKey: 'amount',
        header: ({ column }) => (
          <ColumnHeader column={column} title={t('disputes.columnAmount')} />
        ),
        cell: ({ row }) => (
          <MoneyFormat amount={row.original.amount} currency={row.original.currency} />
        ),
        enableSorting: true,
      },
      {
        accessorKey: 'type',
        header: t('disputes.columnType'),
        cell: ({ row }) => {
          const type = row.original.type
          const variant =
            type === 'chargeback'
              ? 'destructive'
              : type === 'pre_arbitration'
                ? 'secondary'
                : 'outline'
          return <Badge variant={variant}>{DISPUTE_TYPE_LABELS[type] ?? type}</Badge>
        },
        enableSorting: false,
      },
      {
        accessorKey: 'status',
        header: ({ column }) => (
          <ColumnHeader column={column} title={t('disputes.columnStatus')} />
        ),
        cell: ({ row }) => (
          <StatusBadge
            status={row.original.status}
            label={DISPUTE_STATUS_LABELS[row.original.status]}
          />
        ),
        enableSorting: true,
      },
      {
        accessorKey: 'reason',
        header: t('disputes.columnReason'),
        cell: ({ row }) => (
          <span className="text-muted-foreground text-sm">{row.original.reason}</span>
        ),
        enableSorting: false,
        meta: { hiddenOnMobile: true },
      },
      {
        accessorKey: 'deadline',
        header: ({ column }) => (
          <ColumnHeader column={column} title={t('disputes.columnDeadline')} />
        ),
        cell: ({ row }) => {
          const urgent = isDeadlineUrgent(row.original.deadline)
          return (
            <DateFormat
              date={row.original.deadline}
              className={cn(urgent && 'font-semibold text-red-600 dark:text-red-400')}
            />
          )
        },
        enableSorting: true,
      },
      {
        accessorKey: 'connector',
        header: t('disputes.columnConnector'),
        cell: ({ row }) => row.original.connector ?? '—',
        enableSorting: false,
        meta: { hiddenOnMobile: true },
      },
      {
        accessorKey: 'created_at',
        header: ({ column }) => (
          <ColumnHeader column={column} title={t('disputes.columnDate')} />
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
  const baseParams = useMemo<DisputeListParams>(() => {
    const params: DisputeListParams = {}

    if (filterValues.type) params.type = filterValues.type
    if (filterValues.status) params.status = filterValues.status

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
  const fullParams = useMemo<DisputeListParams>(
    () => ({
      ...baseParams,
      page: currentPage,
      per_page: perPage,
    }),
    [baseParams, currentPage, perPage],
  )

  // Fetch data
  const query = useDisputesList(fullParams)

  // Transform data for table
  const rows = useMemo<DisputeRow[]>(() => {
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
          <ShieldAlert className="text-muted-foreground h-7 w-7" />
          <h1 className="text-3xl font-bold">{t('disputes.title')}</h1>
        </div>
        <TableDensityToggle />
      </div>

      <DataTable
        table={table}
        columns={columns}
        isLoading={query.isLoading}
        isError={query.isError}
        onRetry={() => void query.refetch()}
        emptyTitle={t('disputes.emptyTitle')}
        emptyDescription={t('disputes.emptyDesc')}
        pagination={paginationState}
        onPageChange={onPageChange}
        onPerPageChange={onPerPageChange}
        density={density}
        onRowClick={(row) =>
          void navigate({ to: '/disputes/$disputeKey', params: { disputeKey: row.id } })
        }
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
