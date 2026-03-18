import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useReactTable, getCoreRowModel, type ColumnDef } from '@tanstack/react-table'
import { Link } from '@tanstack/react-router'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Building2, Plus } from 'lucide-react'

import type { OrganizationAttributes } from '@/api/types'
import { type OrgListParams } from '@/api/endpoints/dashboard-orgs'
import { useOrganizationsList, useCreateOrganization } from '@/hooks/use-organizations'
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
import { CopyButton } from '@/components/shared/copy-button'
import { usePreferencesStore } from '@/stores/preferences'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog'
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form'
import { Input } from '@/components/ui/input'

// ─── Row Type ────────────────────────────────────────────────

type OrgRow = OrganizationAttributes & { id: string; merchants_count: number }

// ─── Page Component ─────────────────────────────────────────

export function OrganizationsPage() {
  const { t } = useTranslation()
  const density = usePreferencesStore((s) => s.density)
  const [createOpen, setCreateOpen] = useState(false)

  // ─── Filter Definitions ─────────────────────────────────────

  const filterDefs: FilterDef[] = [
    {
      type: 'text',
      key: 'search',
      label: t('organizations.filterSearch'),
      placeholder: t('organizations.filterSearchPlaceholder'),
    },
  ]

  // ─── Columns ────────────────────────────────────────────────

  const columns: ColumnDef<OrgRow, unknown>[] = [
    {
      accessorKey: 'id',
      header: t('common.id'),
      size: 220,
      cell: ({ row }) => {
        const id = row.original.id
        return (
          <div className="flex items-center gap-1">
            <Link
              to="/organizations/$orgKey"
              params={{ orgKey: id }}
              className="text-primary font-mono text-sm hover:underline"
            >
              {id}
            </Link>
            <CopyButton value={id} />
          </div>
        )
      },
      enableSorting: false,
    },
    {
      accessorKey: 'name',
      header: ({ column }) => <ColumnHeader column={column} title={t('organizations.columnName')} />,
      cell: ({ row }) => (
        <Link
          to="/organizations/$orgKey"
          params={{ orgKey: row.original.id }}
          className="text-primary hover:underline"
        >
          {row.original.name}
        </Link>
      ),
      enableSorting: true,
    },
    {
      accessorKey: 'merchants_count',
      header: t('organizations.columnMerchantsCount'),
      size: 120,
      cell: ({ row }) => row.original.merchants_count,
      enableSorting: false,
    },
    {
      accessorKey: 'created_at',
      header: ({ column }) => <ColumnHeader column={column} title={t('organizations.columnDate')} />,
      cell: ({ row }) => <DateFormat date={row.original.created_at} />,
      enableSorting: true,
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

  const baseParams = useMemo<OrgListParams>(() => {
    const params: OrgListParams = {}

    if (filterValues.search) params.search = filterValues.search

    if (sorting.length > 0) {
      params.sort = sorting[0]!.id
      params.direction = sorting[0]!.desc ? 'desc' : 'asc'
    }

    return params
  }, [filterValues, sorting])

  const { pagination, currentPage, perPage, onPageChange, onPerPageChange } =
    useTablePagination(undefined)

  const fullParams = useMemo<OrgListParams>(
    () => ({
      ...baseParams,
      page: currentPage,
      per_page: perPage,
    }),
    [baseParams, currentPage, perPage],
  )

  const query = useOrganizationsList(fullParams)

  const rows = useMemo<OrgRow[]>(() => {
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
          <Building2 className="text-muted-foreground h-7 w-7" />
          <h1 className="text-3xl font-bold">{t('organizations.title')}</h1>
        </div>
        <div className="flex items-center gap-2">
          <Button onClick={() => setCreateOpen(true)} size="sm">
            <Plus className="mr-1 h-4 w-4" />
            {t('organizations.createButton')}
          </Button>
          <TableDensityToggle />
        </div>
      </div>

      <DataTable
        table={table}
        columns={columns}
        isLoading={query.isLoading}
        isError={query.isError}
        onRetry={() => void query.refetch()}
        emptyTitle={t('organizations.emptyTitle')}
        emptyDescription={t('organizations.emptyDesc')}
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

      <CreateOrgDialog open={createOpen} onOpenChange={setCreateOpen} />
    </div>
  )
}

// ─── Create Org Dialog ──────────────────────────────────────

function CreateOrgDialog({
  open,
  onOpenChange,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const { t } = useTranslation()
  const createMutation = useCreateOrganization()

  const createOrgSchema = z.object({
    name: z.string().min(1, t('organizations.nameRequired')),
  })

  type CreateOrgForm = z.infer<typeof createOrgSchema>

  const form = useForm<CreateOrgForm>({
    resolver: zodResolver(createOrgSchema),
    defaultValues: { name: '' },
  })

  function onSubmit(values: CreateOrgForm) {
    createMutation.mutate(
      { name: values.name },
      {
        onSuccess: () => {
          form.reset()
          onOpenChange(false)
        },
      },
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('organizations.createTitle')}</DialogTitle>
          <DialogDescription>{t('organizations.createDesc')}</DialogDescription>
        </DialogHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <FormField
              control={form.control}
              name="name"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('common.name')}</FormLabel>
                  <FormControl>
                    <Input placeholder={t('organizations.namePlaceholder')} {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                {t('common.cancel')}
              </Button>
              <Button type="submit" disabled={createMutation.isPending}>
                {createMutation.isPending ? t('common.creating') : t('common.create')}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  )
}
