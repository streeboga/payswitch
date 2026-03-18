import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useReactTable, getCoreRowModel, type ColumnDef } from '@tanstack/react-table'
import { Link } from '@tanstack/react-router'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { FolderOpen, MoreHorizontal, Plus, Trash2 } from 'lucide-react'

import {
  type ProfileListItem,
  type ProfileListParams,
} from '@/api/endpoints/dashboard-profiles'
import { useProfilesList, useCreateProfile, useDeleteProfile } from '@/hooks/use-profiles'
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

type ProfileRow = ProfileListItem & { id: string }

// ─── Page Component ─────────────────────────────────────────

export function ProfilesPage() {
  const { t } = useTranslation()
  const density = usePreferencesStore((s) => s.density)
  const [createOpen, setCreateOpen] = useState(false)
  const [deleteProfile, setDeleteProfile] = useState<ProfileRow | null>(null)
  const [deleteOpen, setDeleteOpen] = useState(false)

  // ─── Filter Definitions ─────────────────────────────────────

  const filterDefs: FilterDef[] = [
    {
      type: 'text',
      key: 'search',
      label: t('profiles.filterSearch'),
      placeholder: t('profiles.filterSearchPlaceholder'),
    },
  ]

  // ─── Columns ────────────────────────────────────────────────

  const columns: ColumnDef<ProfileRow, unknown>[] = [
    {
      accessorKey: 'id',
      header: t('common.id'),
      size: 220,
      cell: ({ row }) => {
        const id = row.original.id
        return (
          <div className="flex items-center gap-1">
            <Link
              to="/profiles/$profileKey"
              params={{ profileKey: id }}
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
      accessorKey: 'webhook_url',
      header: t('profiles.columnWebhookUrl'),
      cell: ({ row }) => {
        const url = row.original.webhook_url
        if (!url) return <span className="text-muted-foreground">—</span>
        const truncated = url.length > 50 ? url.slice(0, 50) + '...' : url
        return (
          <span className="font-mono text-xs" title={url}>
            {truncated}
          </span>
        )
      },
      enableSorting: false,
    },
    {
      accessorKey: 'connectors_count',
      header: t('profiles.columnConnectorsCount'),
      size: 120,
      cell: ({ row }) => row.original.connectors_count,
      enableSorting: false,
      meta: { hiddenOnMobile: true },
    },
    {
      accessorKey: 'routing_rules_count',
      header: t('profiles.columnRulesCount'),
      size: 100,
      cell: ({ row }) => row.original.routing_rules_count,
      enableSorting: false,
      meta: { hiddenOnMobile: true },
    },
    {
      accessorKey: 'created_at',
      header: ({ column }) => <ColumnHeader column={column} title={t('profiles.columnDate')} />,
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
              className="text-destructive"
              onClick={() => {
                setDeleteProfile(row.original)
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

  const baseParams = useMemo<ProfileListParams>(() => {
    const params: ProfileListParams = {}

    if (filterValues.search) params.search = filterValues.search

    if (sorting.length > 0) {
      params.sort = sorting[0]!.id
      params.direction = sorting[0]!.desc ? 'desc' : 'asc'
    }

    return params
  }, [filterValues, sorting])

  const { pagination, currentPage, perPage, onPageChange, onPerPageChange } =
    useTablePagination(undefined)

  const fullParams = useMemo<ProfileListParams>(
    () => ({
      ...baseParams,
      page: currentPage,
      per_page: perPage,
    }),
    [baseParams, currentPage, perPage],
  )

  const query = useProfilesList(fullParams)

  const rows = useMemo<ProfileRow[]>(() => {
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
          <FolderOpen className="text-muted-foreground h-7 w-7" />
          <h1 className="text-3xl font-bold">{t('profiles.title')}</h1>
        </div>
        <div className="flex items-center gap-2">
          <Button onClick={() => setCreateOpen(true)} size="sm">
            <Plus className="mr-1 h-4 w-4" />
            {t('profiles.createButton')}
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
        emptyTitle={t('profiles.emptyTitle')}
        emptyDescription={t('profiles.emptyDesc')}
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

      <CreateProfileDialog open={createOpen} onOpenChange={setCreateOpen} />

      {deleteProfile && (
        <DeleteProfileDialog
          open={deleteOpen}
          onOpenChange={setDeleteOpen}
          profile={deleteProfile}
        />
      )}
    </div>
  )
}

// ─── Create Profile Dialog ──────────────────────────────────

function CreateProfileDialog({
  open,
  onOpenChange,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const { t } = useTranslation()
  const createMutation = useCreateProfile()

  const createProfileSchema = z.object({
    webhook_url: z.string().url(t('profiles.webhookUrlInvalid')).or(z.literal('')).optional(),
  })

  type CreateProfileForm = z.infer<typeof createProfileSchema>

  const form = useForm<CreateProfileForm>({
    resolver: zodResolver(createProfileSchema),
    defaultValues: { webhook_url: '' },
  })

  function onSubmit(values: CreateProfileForm) {
    createMutation.mutate(
      { webhook_url: values.webhook_url || undefined },
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
          <DialogTitle>{t('profiles.createTitle')}</DialogTitle>
          <DialogDescription>{t('profiles.createDesc')}</DialogDescription>
        </DialogHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <FormField
              control={form.control}
              name="webhook_url"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('profiles.webhookUrlLabel')}</FormLabel>
                  <FormControl>
                    <Input placeholder={t('profiles.webhookUrlPlaceholder')} {...field} />
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

// ─── Delete Profile Dialog ──────────────────────────────────

function DeleteProfileDialog({
  open,
  onOpenChange,
  profile,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  profile: ProfileRow
}) {
  const { t } = useTranslation()
  const deleteMutation = useDeleteProfile()

  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>{t('profiles.deleteTitle')}</AlertDialogTitle>
          <AlertDialogDescription>
            {t('profiles.deleteDesc')}
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>{t('common.cancel')}</AlertDialogCancel>
          <AlertDialogAction
            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            disabled={deleteMutation.isPending}
            onClick={(e) => {
              e.preventDefault()
              deleteMutation.mutate(profile.id, {
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
