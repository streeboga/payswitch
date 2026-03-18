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
import { ClipboardList, Download, ChevronRight, ChevronDown } from 'lucide-react'

import {
  type AuditLogAttributes,
  type AuditLogListParams,
} from '@/api/endpoints/dashboard-audit-log'
import { useAuditLogList, useExportAuditLogCsv } from '@/hooks/use-audit-log'
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

type AuditLogRow = AuditLogAttributes & { id: string }

// ─── Resource Link Helper ────────────────────────────────────

function ResourceLink({
  resourceType,
  resourceId,
}: {
  resourceType: string
  resourceId: string
}) {
  if (resourceType === 'payment' && resourceId.startsWith('pay_')) {
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

  if (resourceType === 'customer' && resourceId.startsWith('cus_')) {
    return (
      <Link
        to="/customers/$customerKey"
        params={{ customerKey: resourceId }}
        className="text-primary font-mono text-xs hover:underline"
      >
        {resourceId}
      </Link>
    )
  }

  return <span className="font-mono text-xs">{resourceId}</span>
}

// ─── JSON Diff Viewer ────────────────────────────────────────

function JsonDiff({
  oldValues,
  newValues,
}: {
  oldValues: Record<string, unknown> | null
  newValues: Record<string, unknown> | null
}) {
  const { t } = useTranslation()
  if (!oldValues && !newValues) {
    return <span className="text-muted-foreground text-sm">{t('auditLog.noDiffData')}</span>
  }

  const allKeys = new Set([
    ...Object.keys(oldValues ?? {}),
    ...Object.keys(newValues ?? {}),
  ])

  return (
    <div className="space-y-1 rounded border p-3 text-xs">
      {[...allKeys].map((key) => {
        const oldVal = oldValues?.[key]
        const newVal = newValues?.[key]
        const changed = JSON.stringify(oldVal) !== JSON.stringify(newVal)

        return (
          <div key={key} className="flex gap-2">
            <span className="text-muted-foreground min-w-[120px] font-medium">
              {key}:
            </span>
            {changed ? (
              <div className="flex gap-2">
                {oldVal !== undefined && (
                  <span className="bg-destructive/10 text-destructive rounded px-1">
                    {JSON.stringify(oldVal)}
                  </span>
                )}
                {newVal !== undefined && (
                  <span className="rounded bg-green-500/10 px-1 text-green-700 dark:text-green-400">
                    {JSON.stringify(newVal)}
                  </span>
                )}
              </div>
            ) : (
              <span>{JSON.stringify(newVal)}</span>
            )}
          </div>
        )
      })}
    </div>
  )
}

// ─── Page Component ─────────────────────────────────────────

export function AuditLogPage() {
  const { t } = useTranslation()
  const density = usePreferencesStore((s) => s.density)
  const [expanded, setExpanded] = useState<ExpandedState>({})

  // ─── Filter & Option Definitions (i18n) ──────────────────
  const filterDefs = useMemo<FilterDef[]>(() => {
    const actionOptions = [
      { value: 'created', label: t('auditLog.actionCreated') },
      { value: 'updated', label: t('auditLog.actionUpdated') },
      { value: 'deleted', label: t('auditLog.actionDeleted') },
    ]

    const resourceTypeOptions = [
      { value: 'payment', label: t('auditLog.resourcePayments') },
      { value: 'refund', label: t('auditLog.resourceRefunds') },
      { value: 'customer', label: t('auditLog.resourceCustomers') },
      { value: 'connector', label: t('auditLog.resourceConnectors') },
      { value: 'merchant', label: t('auditLog.resourceMerchants') },
      { value: 'organization', label: t('auditLog.resourceOrganizations') },
      { value: 'profile', label: t('auditLog.resourceProfiles') },
      { value: 'api_key', label: t('auditLog.resourceApiKeys') },
      { value: 'webhook', label: t('auditLog.resourceWebhooks') },
      { value: 'routing_rule', label: t('auditLog.resourceRoutingRules') },
    ]

    return [
      {
        type: 'text',
        key: 'user',
        label: t('auditLog.filterUser'),
        placeholder: t('auditLog.filterUserPlaceholder'),
      },
      {
        type: 'select',
        key: 'action',
        label: t('auditLog.filterAction'),
        options: actionOptions,
      },
      {
        type: 'select',
        key: 'resource_type',
        label: t('auditLog.filterResource'),
        options: resourceTypeOptions,
      },
      {
        type: 'date-range',
        key: 'date',
        label: t('auditLog.filterDate'),
      },
    ]
  }, [t])

  // ─── Columns (i18n) ──────────────────────────────────────
  const columns = useMemo<ColumnDef<AuditLogRow, unknown>[]>(
    () => [
      {
        id: 'expander',
        header: '',
        size: 40,
        cell: ({ row }) => {
          if (!row.original.old_values && !row.original.new_values) return null
          return (
            <button
              onClick={row.getToggleExpandedHandler()}
              className="text-muted-foreground hover:text-foreground cursor-pointer"
            >
              {row.getIsExpanded() ? (
                <ChevronDown className="h-4 w-4" />
              ) : (
                <ChevronRight className="h-4 w-4" />
              )}
            </button>
          )
        },
        enableSorting: false,
      },
      {
        accessorKey: 'created_at',
        header: ({ column }) => <ColumnHeader column={column} title={t('auditLog.columnTime')} />,
        cell: ({ row }) => <DateFormat date={row.original.created_at} />,
        enableSorting: true,
      },
      {
        accessorKey: 'user_name',
        header: t('auditLog.columnUser'),
        cell: ({ row }) => (
          <div>
            <div className="text-sm font-medium">{row.original.user_name}</div>
            <div className="text-muted-foreground text-xs">{row.original.user_email}</div>
          </div>
        ),
        enableSorting: false,
      },
      {
        accessorKey: 'action',
        header: t('auditLog.columnAction'),
        cell: ({ row }) => {
          const action = row.original.action
          const variant =
            action === 'deleted'
              ? 'destructive'
              : action === 'created'
                ? 'default'
                : 'secondary'
          return (
            <Badge variant={variant as 'default' | 'destructive' | 'secondary'}>
              {action}
            </Badge>
          )
        },
        enableSorting: false,
      },
      {
        accessorKey: 'resource_id',
        header: t('auditLog.columnResource'),
        cell: ({ row }) => (
          <div>
            <div className="text-muted-foreground text-xs capitalize">
              {row.original.resource_type}
            </div>
            <ResourceLink
              resourceType={row.original.resource_type}
              resourceId={row.original.resource_id}
            />
          </div>
        ),
        enableSorting: false,
      },
      {
        accessorKey: 'ip_address',
        header: t('auditLog.columnIp'),
        cell: ({ row }) => (
          <span className="font-mono text-xs">{row.original.ip_address ?? '—'}</span>
        ),
        enableSorting: false,
        meta: { hiddenOnMobile: true },
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

  const baseParams = useMemo<AuditLogListParams>(() => {
    const params: AuditLogListParams = {}

    if (filterValues.user) params.user = filterValues.user
    if (filterValues.action) params.action = filterValues.action
    if (filterValues.resource_type) params.resource_type = filterValues.resource_type

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

  const fullParams = useMemo<AuditLogListParams>(
    () => ({
      ...baseParams,
      page: currentPage,
      per_page: perPage,
    }),
    [baseParams, currentPage, perPage],
  )

  const query = useAuditLogList(fullParams)
  const exportMutation = useExportAuditLogCsv()

  const rows = useMemo<AuditLogRow[]>(() => {
    return query.data?.items ?? []
  }, [query.data])

  const table = useReactTable({
    data: rows,
    columns,
    getCoreRowModel: getCoreRowModel(),
    getExpandedRowModel: getExpandedRowModel(),
    manualSorting: true,
    manualPagination: true,
    state: { sorting, expanded },
    onSortingChange,
    onExpandedChange: setExpanded,
    getRowCanExpand: (row) => !!(row.original.old_values || row.original.new_values),
  })

  const handleRetry = useCallback(() => {
    void query.refetch()
  }, [query])

  const handleExport = useCallback(() => {
    exportMutation.mutate(baseParams)
  }, [exportMutation, baseParams])

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
          <ClipboardList className="text-muted-foreground h-7 w-7" />
          <h1 className="text-3xl font-bold">{t('auditLog.title')}</h1>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={handleExport}
            disabled={exportMutation.isPending}
          >
            <Download className="mr-1 h-4 w-4" />
            {exportMutation.isPending ? t('auditLog.exporting') : t('auditLog.exportButton')}
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
        emptyTitle={t('auditLog.emptyTitle')}
        emptyDescription={t('auditLog.emptyDesc')}
        pagination={paginationState}
        onPageChange={onPageChange}
        onPerPageChange={onPerPageChange}
        density={density}
        renderExpandedRow={(row) => (
          <div className="px-4 py-3">
            <h4 className="mb-2 text-sm font-medium">{t('auditLog.expandedChanges')}</h4>
            <JsonDiff
              oldValues={row.original.old_values}
              newValues={row.original.new_values}
            />
          </div>
        )}
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
