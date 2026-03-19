import { lazy, Suspense, useMemo, useState, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { useReactTable, getCoreRowModel, type ColumnDef } from '@tanstack/react-table'
import { GitBranch, Pencil, Plus, Trash2 } from 'lucide-react'

import type { RoutingRuleAttributes, RoutingRuleType } from '@/api/types'
import type { RoutingRuleListParams } from '@/api/endpoints/dashboard-routing-rules'
import {
  useRoutingRulesList,
  useCreateRoutingRule,
  useUpdateRoutingRule,
  useDeleteRoutingRule,
} from '@/hooks/use-routing-rules'
import {
  DataTable,
  ColumnHeader,
  TableDensityToggle,
  useTableSorting,
  useTablePagination,
} from '@/components/data-table'
import { DateFormat } from '@/components/shared/date-format'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { usePreferencesStore } from '@/stores/preferences'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Switch } from '@/components/ui/switch'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '@/components/ui/dialog'
import type { PriorityFormValues } from '@/components/routing/priority-form'
import type { RuleBasedFormValues } from '@/components/routing/rule-based-form'
import type { VolumeSplitFormValues } from '@/components/routing/volume-split-form'
import {
  deserializePriorityRules,
  deserializeRuleBasedRules,
  deserializeVolumeSplitRules,
} from '@/components/routing/deserialize-rules'

const PriorityForm = lazy(() =>
  import('@/components/routing/priority-form').then((m) => ({ default: m.PriorityForm })),
)
const RuleBasedForm = lazy(() =>
  import('@/components/routing/rule-based-form').then((m) => ({
    default: m.RuleBasedForm,
  })),
)
const VolumeSplitForm = lazy(() =>
  import('@/components/routing/volume-split-form').then((m) => ({
    default: m.VolumeSplitForm,
  })),
)

// ─── Row Type ────────────────────────────────────────────────

type RoutingRuleRow = RoutingRuleAttributes & { id: string }

// ─── Type Variants ──────────────────────────────────────────

const TYPE_VARIANTS: Record<RoutingRuleType, string> = {
  priority: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
  rule_based: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
  volume_split: 'bg-purple-100 text-purple-800 dark:bg-purple-950 dark:text-purple-300',
}

// ─── Columns ────────────────────────────────────────────────

function createColumns(
  onToggleActive: (row: RoutingRuleRow) => void,
  onEdit: (row: RoutingRuleRow) => void,
  onDelete: (row: RoutingRuleRow) => void,
  t: (key: string) => string,
): ColumnDef<RoutingRuleRow, unknown>[] {
  const TYPE_LABELS: Record<RoutingRuleType, string> = {
    priority: t('routingRules.typePriority'),
    rule_based: t('routingRules.typeRuleBased'),
    volume_split: t('routingRules.typeVolumeSplit'),
  }

  return [
    {
      accessorKey: 'name',
      header: ({ column }) => (
        <ColumnHeader column={column} title={t('routingRules.columnName')} />
      ),
      cell: ({ row }) => <span className="font-medium">{row.original.name}</span>,
      enableSorting: true,
    },
    {
      accessorKey: 'type',
      header: t('routingRules.columnType'),
      size: 160,
      cell: ({ row }) => {
        const type = row.original.type
        return (
          <Badge variant="outline" className={TYPE_VARIANTS[type]}>
            {TYPE_LABELS[type]}
          </Badge>
        )
      },
      enableSorting: false,
    },
    {
      accessorKey: 'priority',
      header: ({ column }) => (
        <ColumnHeader column={column} title={t('routingRules.columnPriority')} />
      ),
      size: 100,
      cell: ({ row }) => (
        <span className="font-mono text-sm">{row.original.priority}</span>
      ),
      enableSorting: true,
    },
    {
      accessorKey: 'active',
      header: t('routingRules.columnActive'),
      size: 100,
      cell: ({ row }) => (
        <Switch
          checked={row.original.active}
          onCheckedChange={() => onToggleActive(row.original)}
          aria-label={`${t('routingRules.columnActive')} ${row.original.name}`}
        />
      ),
      enableSorting: false,
    },
    {
      accessorKey: 'created_at',
      header: ({ column }) => (
        <ColumnHeader column={column} title={t('routingRules.columnDate')} />
      ),
      cell: ({ row }) => <DateFormat date={row.original.created_at} />,
      enableSorting: true,
    },
    {
      id: 'actions',
      header: '',
      size: 80,
      cell: ({ row }) => (
        <div className="flex items-center gap-1">
          <Button
            variant="ghost"
            size="icon"
            className="text-muted-foreground hover:text-foreground"
            onClick={() => onEdit(row.original)}
            aria-label={`${t('common.edit')} ${row.original.name}`}
          >
            <Pencil className="h-4 w-4" />
          </Button>
          <Button
            variant="ghost"
            size="icon"
            className="text-muted-foreground hover:text-destructive"
            onClick={() => onDelete(row.original)}
            aria-label={`${t('common.delete')} ${row.original.name}`}
          >
            <Trash2 className="h-4 w-4" />
          </Button>
        </div>
      ),
      enableSorting: false,
    },
  ]
}

// ─── Type Selection Step ─────────────────────────────────────

type CreateStep = 'select-type' | 'priority' | 'rule_based' | 'volume_split'

function TypeSelectionStep({ onSelect }: { onSelect: (type: RoutingRuleType) => void }) {
  const { t } = useTranslation()

  const types = useMemo<{ type: RoutingRuleType; label: string; description: string }[]>(
    () => [
      {
        type: 'priority',
        label: t('routingRules.typePriority'),
        description: t('routingRules.typePriorityDesc'),
      },
      {
        type: 'rule_based',
        label: t('routingRules.typeRuleBased'),
        description: t('routingRules.typeRuleBasedDesc'),
      },
      {
        type: 'volume_split',
        label: t('routingRules.typeVolumeSplit'),
        description: t('routingRules.typeVolumeSplitDesc'),
      },
    ],
    [t],
  )

  return (
    <div className="space-y-2">
      {types.map((tp) => (
        <button
          key={tp.type}
          type="button"
          onClick={() => onSelect(tp.type)}
          className="hover:bg-accent w-full rounded-md border p-4 text-left transition-colors"
        >
          <div className="flex items-center gap-2">
            <Badge variant="outline" className={TYPE_VARIANTS[tp.type]}>
              {tp.label}
            </Badge>
          </div>
          <p className="text-muted-foreground mt-1 text-sm">{tp.description}</p>
        </button>
      ))}
    </div>
  )
}

// ─── Page Component ─────────────────────────────────────────

export function RoutingRulesPage() {
  const { t } = useTranslation()
  const density = usePreferencesStore((s) => s.density)
  const [createOpen, setCreateOpen] = useState(false)
  const [createStep, setCreateStep] = useState<CreateStep>('select-type')
  const [deleteTarget, setDeleteTarget] = useState<RoutingRuleRow | null>(null)
  const [editTarget, setEditTarget] = useState<RoutingRuleRow | null>(null)

  const createMutation = useCreateRoutingRule()
  const updateMutation = useUpdateRoutingRule()
  const deleteMutation = useDeleteRoutingRule()

  // URL-synced sorting, pagination
  const { sorting, onSortingChange } = useTableSorting({
    defaultSort: 'priority',
    defaultOrder: 'asc',
  })

  // Build API params from URL state
  const baseParams = useMemo<RoutingRuleListParams>(() => {
    const params: RoutingRuleListParams = {}
    if (sorting.length > 0) {
      params.sort = sorting[0]!.id
      params.direction = sorting[0]!.desc ? 'desc' : 'asc'
    }
    return params
  }, [sorting])

  const { pagination, currentPage, perPage, onPageChange, onPerPageChange } =
    useTablePagination(undefined)

  const fullParams = useMemo<RoutingRuleListParams>(
    () => ({
      ...baseParams,
      page: currentPage,
      per_page: perPage,
    }),
    [baseParams, currentPage, perPage],
  )

  // Fetch data
  const query = useRoutingRulesList(fullParams)

  // Transform data for table
  const rows = useMemo<RoutingRuleRow[]>(() => {
    return query.data?.items ?? []
  }, [query.data])

  // Handlers
  const handleToggleActive = useCallback(
    (row: RoutingRuleRow) => {
      updateMutation.mutate({
        key: row.id,
        attrs: { active: !row.active },
      })
    },
    // updateMutation.mutate is stable, but updateMutation object is not —
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [updateMutation.mutate],
  )

  const handleEdit = useCallback((row: RoutingRuleRow) => {
    setEditTarget(row)
  }, [])

  const handleEditSubmit = useCallback(
    (values: PriorityFormValues | RuleBasedFormValues | VolumeSplitFormValues) => {
      if (!editTarget) return

      let rules: unknown[]
      const type = editTarget.type

      if (type === 'priority') {
        const v = values as PriorityFormValues
        rules = v.connectors.map((c, i) => ({
          connector_id: c.connector_id,
          priority: i + 1,
        }))
      } else if (type === 'rule_based') {
        const v = values as RuleBasedFormValues
        rules = [
          ...v.conditions.map((c) => ({
            field: c.field,
            operator: c.operator,
            value: c.value,
            connector_id: c.connector_id,
          })),
          { default: true, connector_id: v.default_connector_id },
        ]
      } else {
        const v = values as VolumeSplitFormValues
        rules = v.splits.map((s) => ({
          connector_id: s.connector_id,
          percentage: s.percentage,
        }))
      }

      updateMutation.mutate(
        {
          key: editTarget.id,
          attrs: { name: values.name, rules },
        },
        {
          onSuccess: () => setEditTarget(null),
        },
      )
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [editTarget, updateMutation.mutate],
  )

  const handleDelete = useCallback((row: RoutingRuleRow) => {
    setDeleteTarget(row)
  }, [])

  const handleConfirmDelete = useCallback(() => {
    if (deleteTarget) {
      deleteMutation.mutate(deleteTarget.id, {
        onSuccess: () => setDeleteTarget(null),
      })
    }
  }, [deleteTarget, deleteMutation])

  const handleOpenCreate = useCallback(() => {
    setCreateStep('select-type')
    setCreateOpen(true)
  }, [])

  const handleCreateSubmit = useCallback(
    (
      type: RoutingRuleType,
      values: PriorityFormValues | RuleBasedFormValues | VolumeSplitFormValues,
    ) => {
      let rules: unknown[]

      if (type === 'priority') {
        const v = values as PriorityFormValues
        rules = v.connectors.map((c, i) => ({
          connector_id: c.connector_id,
          priority: i + 1,
        }))
      } else if (type === 'rule_based') {
        const v = values as RuleBasedFormValues
        rules = [
          ...v.conditions.map((c) => ({
            field: c.field,
            operator: c.operator,
            value: c.value,
            connector_id: c.connector_id,
          })),
          { default: true, connector_id: v.default_connector_id },
        ]
      } else {
        const v = values as VolumeSplitFormValues
        rules = v.splits.map((s) => ({
          connector_id: s.connector_id,
          percentage: s.percentage,
        }))
      }

      createMutation.mutate(
        {
          type,
          name: values.name,
          rules,
          active: true,
        },
        {
          onSuccess: () => {
            setCreateOpen(false)
            setCreateStep('select-type')
          },
        },
      )
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [createMutation.mutate],
  )

  const columns = useMemo(
    () => createColumns(handleToggleActive, handleEdit, handleDelete, t),
    [handleToggleActive, handleEdit, handleDelete, t],
  )

  const table = useReactTable({
    data: rows,
    columns,
    getCoreRowModel: getCoreRowModel(),
    manualSorting: true,
    manualPagination: true,
    state: { sorting },
    onSortingChange,
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

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <GitBranch className="text-muted-foreground h-7 w-7" />
          <h1 className="text-3xl font-bold">{t('routingRules.title')}</h1>
        </div>
        <div className="flex items-center gap-2">
          <TableDensityToggle />
          <Button onClick={handleOpenCreate}>
            <Plus className="mr-2 h-4 w-4" />
            {t('routingRules.createButton')}
          </Button>
        </div>
      </div>

      <DataTable
        table={table}
        columns={columns}
        isLoading={query.isLoading}
        isError={query.isError}
        onRetry={() => void query.refetch()}
        emptyTitle={t('routingRules.emptyTitle')}
        emptyDescription={t('routingRules.emptyDesc')}
        pagination={paginationState}
        onPageChange={onPageChange}
        onPerPageChange={onPerPageChange}
        density={density}
      />

      {/* Create Rule Dialog */}
      <Dialog open={createOpen} onOpenChange={setCreateOpen}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>
              {createStep === 'select-type' && t('routingRules.createSelectTitle')}
              {createStep === 'priority' && t('routingRules.createPriorityTitle')}
              {createStep === 'rule_based' && t('routingRules.createRuleBasedTitle')}
              {createStep === 'volume_split' && t('routingRules.createVolumeSplitTitle')}
            </DialogTitle>
            <DialogDescription>
              {createStep === 'select-type' && t('routingRules.createSelectDesc')}
              {createStep === 'priority' && t('routingRules.createPriorityDesc')}
              {createStep === 'rule_based' && t('routingRules.createRuleBasedDesc')}
              {createStep === 'volume_split' && t('routingRules.createVolumeSplitDesc')}
            </DialogDescription>
          </DialogHeader>

          {createStep === 'select-type' && (
            <TypeSelectionStep onSelect={(type) => setCreateStep(type)} />
          )}

          <Suspense fallback={null}>
            {createStep === 'priority' && (
              <PriorityForm
                onSubmit={(values) => handleCreateSubmit('priority', values)}
                isPending={createMutation.isPending}
              />
            )}

            {createStep === 'rule_based' && (
              <RuleBasedForm
                onSubmit={(values) => handleCreateSubmit('rule_based', values)}
                isPending={createMutation.isPending}
              />
            )}

            {createStep === 'volume_split' && (
              <VolumeSplitForm
                onSubmit={(values) => handleCreateSubmit('volume_split', values)}
                isPending={createMutation.isPending}
              />
            )}
          </Suspense>
        </DialogContent>
      </Dialog>

      {/* Edit Rule Dialog */}
      <Dialog
        open={!!editTarget}
        onOpenChange={(open) => {
          if (!open) setEditTarget(null)
        }}
      >
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>
              {editTarget?.type === 'priority' && t('routingRules.editPriorityTitle')}
              {editTarget?.type === 'rule_based' && t('routingRules.editRuleBasedTitle')}
              {editTarget?.type === 'volume_split' &&
                t('routingRules.editVolumeSplitTitle')}
            </DialogTitle>
            <DialogDescription>
              {editTarget?.type === 'priority' && t('routingRules.editPriorityDesc')}
              {editTarget?.type === 'rule_based' && t('routingRules.editRuleBasedDesc')}
              {editTarget?.type === 'volume_split' &&
                t('routingRules.editVolumeSplitDesc')}
            </DialogDescription>
          </DialogHeader>

          <Suspense fallback={null}>
            {editTarget?.type === 'priority' && (
              <PriorityForm
                key={editTarget.id}
                defaultValues={deserializePriorityRules(
                  editTarget.name,
                  editTarget.rules as { connector_id?: string; priority?: number }[],
                )}
                onSubmit={handleEditSubmit}
                isPending={updateMutation.isPending}
              />
            )}
            {editTarget?.type === 'rule_based' && (
              <RuleBasedForm
                key={editTarget.id}
                defaultValues={deserializeRuleBasedRules(
                  editTarget.name,
                  editTarget.rules as {
                    field?: string
                    operator?: string
                    value?: string
                    connector_id?: string
                    default?: boolean
                  }[],
                )}
                onSubmit={handleEditSubmit}
                isPending={updateMutation.isPending}
              />
            )}
            {editTarget?.type === 'volume_split' && (
              <VolumeSplitForm
                key={editTarget.id}
                defaultValues={deserializeVolumeSplitRules(
                  editTarget.name,
                  editTarget.rules as { connector_id?: string; percentage?: number }[],
                )}
                onSubmit={handleEditSubmit}
                isPending={updateMutation.isPending}
              />
            )}
          </Suspense>
        </DialogContent>
      </Dialog>

      {/* Delete Confirmation */}
      <ConfirmDialog
        open={!!deleteTarget}
        onCancel={() => setDeleteTarget(null)}
        title={t('routingRules.deleteTitle')}
        description={t('routingRules.deleteConfirm')}
        confirmLabel={t('common.delete')}
        destructive
        onConfirm={handleConfirmDelete}
      />
    </div>
  )
}
