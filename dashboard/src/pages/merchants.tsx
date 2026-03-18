import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useReactTable, getCoreRowModel, type ColumnDef } from '@tanstack/react-table'
import { Link } from '@tanstack/react-router'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Store, Plus, MoreHorizontal, Pencil, Trash2 } from 'lucide-react'

import type { MerchantAccountAttributes } from '@/api/types'
import { type OrgListParams } from '@/api/endpoints/dashboard-orgs'
import { useMerchantsList } from '@/hooks/use-merchants'
import {
  useOrganizationsList,
  useCreateMerchant,
  useUpdateMerchant,
  useDeleteMerchant,
} from '@/hooks/use-organizations'
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'

// ─── Row Type ────────────────────────────────────────────────

type MerchantRow = MerchantAccountAttributes & {
  id: string
  profiles_count: number
  connectors_count: number
}

// ─── Page Component ─────────────────────────────────────────

export function MerchantsPage() {
  const { t } = useTranslation()
  const density = usePreferencesStore((s) => s.density)
  const [createOpen, setCreateOpen] = useState(false)
  const [editMerchant, setEditMerchant] = useState<MerchantRow | null>(null)
  const [editOpen, setEditOpen] = useState(false)
  const [deleteMerchant, setDeleteMerchant] = useState<MerchantRow | null>(null)
  const [deleteOpen, setDeleteOpen] = useState(false)

  // ─── Filter Definitions ─────────────────────────────────────

  const filterDefs: FilterDef[] = [
    {
      type: 'text',
      key: 'search',
      label: t('merchants.filterSearch'),
      placeholder: t('merchants.filterSearchPlaceholder'),
    },
  ]

  // ─── Columns ────────────────────────────────────────────────

  const columns: ColumnDef<MerchantRow, unknown>[] = [
    {
      accessorKey: 'id',
      header: t('common.id'),
      size: 220,
      cell: ({ row }) => {
        const id = row.original.id
        return (
          <div className="flex items-center gap-1">
            <Link
              to="/merchants/$merchantKey"
              params={{ merchantKey: id }}
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
      header: ({ column }) => <ColumnHeader column={column} title={t('merchants.columnName')} />,
      cell: ({ row }) => (
        <Link
          to="/merchants/$merchantKey"
          params={{ merchantKey: row.original.id }}
          className="text-primary hover:underline"
        >
          {row.original.name}
        </Link>
      ),
      enableSorting: true,
    },
    {
      accessorKey: 'publishable_key',
      header: t('merchants.columnPublishableKey'),
      cell: ({ row }) => (
        <div className="flex items-center gap-1">
          <span className="font-mono text-xs">{row.original.publishable_key}</span>
          <CopyButton value={row.original.publishable_key} />
        </div>
      ),
      enableSorting: false,
    },
    {
      accessorKey: 'profiles_count',
      header: t('merchants.columnProfilesCount'),
      size: 100,
      cell: ({ row }) => row.original.profiles_count,
      enableSorting: false,
      meta: { hiddenOnMobile: true },
    },
    {
      accessorKey: 'connectors_count',
      header: t('merchants.columnConnectorsCount'),
      size: 120,
      cell: ({ row }) => row.original.connectors_count,
      enableSorting: false,
      meta: { hiddenOnMobile: true },
    },
    {
      accessorKey: 'created_at',
      header: ({ column }) => <ColumnHeader column={column} title={t('merchants.columnDate')} />,
      cell: ({ row }) => <DateFormat date={row.original.created_at} />,
      enableSorting: true,
    },
    {
      id: 'actions',
      size: 50,
      cell: ({ row }) => (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="icon" className="h-8 w-8">
              <MoreHorizontal className="h-4 w-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem
              onClick={() => {
                setEditMerchant(row.original)
                setEditOpen(true)
              }}
            >
              <Pencil className="mr-2 h-4 w-4" />
              {t('common.edit')}
            </DropdownMenuItem>
            <DropdownMenuItem
              className="text-destructive"
              onClick={() => {
                setDeleteMerchant(row.original)
                setDeleteOpen(true)
              }}
            >
              <Trash2 className="mr-2 h-4 w-4" />
              {t('common.delete')}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      ),
      enableSorting: false,
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

  const query = useMerchantsList(fullParams)

  const rows = useMemo<MerchantRow[]>(() => {
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
          <Store className="text-muted-foreground h-7 w-7" />
          <h1 className="text-3xl font-bold">{t('merchants.title')}</h1>
        </div>
        <div className="flex items-center gap-2">
          <Button onClick={() => setCreateOpen(true)} size="sm">
            <Plus className="mr-1 h-4 w-4" />
            {t('merchants.createButton')}
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
        emptyTitle={t('merchants.emptyTitle')}
        emptyDescription={t('merchants.emptyDesc')}
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

      <CreateMerchantDialog open={createOpen} onOpenChange={setCreateOpen} />

      {editMerchant && (
        <EditMerchantDialog
          open={editOpen}
          onOpenChange={setEditOpen}
          merchant={editMerchant}
        />
      )}

      {deleteMerchant && (
        <DeleteMerchantDialog
          open={deleteOpen}
          onOpenChange={setDeleteOpen}
          merchant={deleteMerchant}
        />
      )}
    </div>
  )
}

// ─── Create Merchant Dialog ─────────────────────────────────

function CreateMerchantDialog({
  open,
  onOpenChange,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const { t } = useTranslation()
  const createMutation = useCreateMerchant()
  const orgsQuery = useOrganizationsList()

  const createMerchantSchema = z.object({
    name: z.string().min(1, t('merchants.nameRequired')),
    organization_id: z.string().min(1, t('merchants.orgRequired')),
  })

  type CreateMerchantForm = z.infer<typeof createMerchantSchema>

  const orgs = useMemo(() => {
    return (orgsQuery.data?.items ?? []).map((r) => ({ id: r.id, name: r.name }))
  }, [orgsQuery.data])

  const form = useForm<CreateMerchantForm>({
    resolver: zodResolver(createMerchantSchema),
    defaultValues: { name: '', organization_id: '' },
  })

  function onSubmit(values: CreateMerchantForm) {
    createMutation.mutate(
      { name: values.name, organization_id: values.organization_id },
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
          <DialogTitle>{t('merchants.createTitle')}</DialogTitle>
          <DialogDescription>{t('merchants.createDesc')}</DialogDescription>
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
                    <Input placeholder={t('merchants.namePlaceholder')} {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="organization_id"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('merchants.orgLabel')}</FormLabel>
                  <Select onValueChange={field.onChange} defaultValue={field.value}>
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue placeholder={t('merchants.orgPlaceholder')} />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      {orgs.map((org) => (
                        <SelectItem key={org.id} value={org.id}>
                          {org.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
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

// ─── Edit Merchant Dialog ───────────────────────────────────

function EditMerchantDialog({
  open,
  onOpenChange,
  merchant,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  merchant: MerchantRow
}) {
  const { t } = useTranslation()
  const updateMutation = useUpdateMerchant()

  const editSchema = z.object({
    name: z.string().min(1, t('merchants.nameRequired')),
  })

  type EditForm = z.infer<typeof editSchema>

  const form = useForm<EditForm>({
    resolver: zodResolver(editSchema),
    defaultValues: { name: merchant.name },
  })

  function onSubmit(values: EditForm) {
    updateMutation.mutate(
      { merchantKey: merchant.id, data: { name: values.name } },
      {
        onSuccess: () => {
          onOpenChange(false)
        },
      },
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('merchants.editTitle')}</DialogTitle>
          <DialogDescription>{t('merchants.editDesc')}</DialogDescription>
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
                    <Input placeholder={t('merchants.namePlaceholder')} {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                {t('common.cancel')}
              </Button>
              <Button type="submit" disabled={updateMutation.isPending}>
                {updateMutation.isPending ? t('common.saving') : t('common.save')}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  )
}

// ─── Delete Merchant Dialog ─────────────────────────────────

function DeleteMerchantDialog({
  open,
  onOpenChange,
  merchant,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  merchant: MerchantRow
}) {
  const { t } = useTranslation()
  const deleteMutation = useDeleteMerchant()

  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>{t('merchants.deleteTitle')}</AlertDialogTitle>
          <AlertDialogDescription>
            {t('merchants.deleteDesc')}
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>{t('common.cancel')}</AlertDialogCancel>
          <AlertDialogAction
            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            disabled={deleteMutation.isPending}
            onClick={(e) => {
              e.preventDefault()
              deleteMutation.mutate(merchant.id, {
                onSuccess: () => onOpenChange(false),
              })
            }}
          >
            {deleteMutation.isPending ? t('common.deleting') : t('common.delete')}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
