import { useMemo, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { useReactTable, getCoreRowModel, type ColumnDef } from '@tanstack/react-table'
import { useNavigate } from '@tanstack/react-router'
import {
  Bell,
  CheckCheck,
  Trash2,
  Info,
  AlertTriangle,
  CheckCircle,
  XCircle,
} from 'lucide-react'

import {
  type NotificationAttributes,
  type NotificationListParams,
} from '@/api/endpoints/dashboard-notifications'
import {
  useNotificationsList,
  useMarkAllNotificationsRead,
  useDeleteReadNotifications,
} from '@/hooks/use-notifications'
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
import { Button } from '@/components/ui/button'
import { usePreferencesStore } from '@/stores/preferences'

// ─── Row Type ────────────────────────────────────────────────

type NotificationRow = NotificationAttributes & { id: string }

// ─── Type Icons ──────────────────────────────────────────────

function TypeIcon({ type }: { type: string }) {
  switch (type) {
    case 'error':
    case 'payment_failed':
    case 'refund_failed':
      return <XCircle className="h-4 w-4 text-red-500" />
    case 'warning':
    case 'alert':
      return <AlertTriangle className="h-4 w-4 text-yellow-500" />
    case 'success':
    case 'payment_succeeded':
    case 'refund_succeeded':
      return <CheckCircle className="h-4 w-4 text-green-500" />
    default:
      return <Info className="text-muted-foreground h-4 w-4" />
  }
}

// ─── Filter Definitions (moved inside component for i18n) ───

// ─── Resource Navigation ─────────────────────────────────────

function getResourcePath(
  resourceType: string | null,
  resourceId: string | null,
): string | null {
  if (!resourceType || !resourceId) return null

  if (resourceType === 'payment') return `/payments/${resourceId}`
  if (resourceType === 'customer') return `/customers/${resourceId}`
  if (resourceType === 'connector') return `/connectors/${resourceId}`
  if (resourceType === 'refund') return '/refunds'

  return null
}

// ─── Page Component ─────────────────────────────────────────

export function NotificationsPage() {
  const { t } = useTranslation()
  const density = usePreferencesStore((s) => s.density)
  const navigate = useNavigate()
  const markAllRead = useMarkAllNotificationsRead()
  const deleteRead = useDeleteReadNotifications()

  // ─── Filter & Option Definitions (i18n) ──────────────────
  const filterDefs = useMemo<FilterDef[]>(
    () => [
      {
        type: 'select',
        key: 'type',
        label: t('notifications.columns.type'),
        options: [
          { value: 'payment', label: t('notifications.types.payments') },
          { value: 'refund', label: t('notifications.types.refunds') },
          { value: 'connector', label: t('notifications.types.connectors') },
          { value: 'webhook', label: t('notifications.types.webhooks') },
          { value: 'system', label: t('notifications.types.system') },
        ],
      },
      {
        type: 'select',
        key: 'read',
        label: t('notifications.columns.status'),
        options: [
          { value: 'true', label: t('notifications.readStatus.read') },
          { value: 'false', label: t('notifications.readStatus.unread') },
        ],
      },
      {
        type: 'date-range',
        key: 'date',
        label: t('common.date'),
      },
    ],
    [t],
  )

  // ─── Columns (i18n) ──────────────────────────────────────
  const columns = useMemo<ColumnDef<NotificationRow, unknown>[]>(
    () => [
      {
        accessorKey: 'type',
        header: t('notifications.columns.type'),
        size: 160,
        cell: ({ row }) => (
          <div className="flex items-center gap-2">
            <TypeIcon type={row.original.type} />
            <Badge variant="outline" className="text-xs">
              {row.original.type}
            </Badge>
          </div>
        ),
        enableSorting: false,
      },
      {
        accessorKey: 'title',
        header: t('notifications.columns.text'),
        cell: ({ row }) => (
          <div>
            <div className="text-sm font-medium">{row.original.title}</div>
            <div className="text-muted-foreground text-xs">{row.original.body}</div>
          </div>
        ),
        enableSorting: false,
      },
      {
        accessorKey: 'resource_id',
        header: t('notifications.columns.resource'),
        cell: ({ row }) => {
          const { resource_type, resource_id } = row.original
          if (!resource_id) return <span className="text-muted-foreground">—</span>
          return (
            <span className="font-mono text-xs">
              {resource_type && (
                <span className="text-muted-foreground capitalize">{resource_type}: </span>
              )}
              {resource_id}
            </span>
          )
        },
        enableSorting: false,
        meta: { hiddenOnMobile: true },
      },
      {
        accessorKey: 'created_at',
        header: ({ column }) => <ColumnHeader column={column} title={t('notifications.columns.time')} />,
        cell: ({ row }) => <DateFormat date={row.original.created_at} />,
        enableSorting: true,
      },
      {
        accessorKey: 'read_at',
        header: t('notifications.columns.status'),
        size: 80,
        cell: ({ row }) =>
          row.original.read_at ? (
            <span className="text-muted-foreground text-xs">{t('notifications.columns.statusRead')}</span>
          ) : (
            <span className="bg-primary inline-block h-2.5 w-2.5 rounded-full" />
          ),
        enableSorting: false,
      },
    ],
    [t],
  )

  const { sorting, onSortingChange } = useTableSorting({
    defaultSort: 'created_at',
    defaultOrder: 'desc',
  })
  const {
    values: filterValues,
    onChange: onFilterChange,
    onReset: onFilterReset,
  } = useTableFilters(filterDefs)

  const baseParams = useMemo<NotificationListParams>(() => {
    const params: NotificationListParams = {}

    if (filterValues.type) params.type = filterValues.type
    if (filterValues.read) params.read = filterValues.read

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

  const fullParams = useMemo<NotificationListParams>(
    () => ({
      ...baseParams,
      page: currentPage,
      per_page: perPage,
    }),
    [baseParams, currentPage, perPage],
  )

  const query = useNotificationsList(fullParams)

  const rows = useMemo<NotificationRow[]>(() => {
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

  const handleRowClick = useCallback(
    (row: NotificationRow) => {
      const path = getResourcePath(row.resource_type, row.resource_id)
      if (path) {
        void navigate({ to: path })
      }
    },
    [navigate],
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
          <Bell className="text-muted-foreground h-7 w-7" />
          <h1 className="text-3xl font-bold">{t('notifications.title')}</h1>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={() => markAllRead.mutate()}
            disabled={markAllRead.isPending}
          >
            <CheckCheck className="mr-1 h-4 w-4" />
            {t('notifications.markAllRead')}
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={() => deleteRead.mutate()}
            disabled={deleteRead.isPending}
          >
            <Trash2 className="mr-1 h-4 w-4" />
            {t('notifications.deleteRead')}
          </Button>
          <TableDensityToggle />
        </div>
      </div>

      <DataTable
        table={table}
        columns={columns}
        isLoading={query.isLoading}
        isError={query.isError}
        onRetry={handleRetry}
        emptyTitle={t('notifications.emptyTitle')}
        emptyDescription={t('notifications.emptyDesc')}
        pagination={paginationState}
        onPageChange={onPageChange}
        onPerPageChange={onPerPageChange}
        density={density}
        onRowClick={handleRowClick}
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
