import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useReactTable, getCoreRowModel, type ColumnDef } from '@tanstack/react-table'
import { Link } from '@tanstack/react-router'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Users, Plus } from 'lucide-react'

import type { CustomerAttributes } from '@/api/types'
import { type CustomerListParams } from '@/api/endpoints/dashboard-customers'
import { useCustomersList, useCreateCustomer } from '@/hooks/use-customers'
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

type CustomerRow = CustomerAttributes & { id: string }

// ─── Page Component ─────────────────────────────────────────

export function CustomersPage() {
  const { t } = useTranslation()
  const density = usePreferencesStore((s) => s.density)
  const [createOpen, setCreateOpen] = useState(false)

  // ─── Filter Definitions ─────────────────────────────────────

  const filterDefs: FilterDef[] = useMemo(
    () => [
      {
        type: 'text',
        key: 'search',
        label: t('common.search'),
        placeholder: 'Имя, email или cus_...',
      },
    ],
    [t],
  )

  // ─── Columns ────────────────────────────────────────────────

  const columns: ColumnDef<CustomerRow, unknown>[] = useMemo(
    () => [
      {
        accessorKey: 'id',
        header: t('common.id'),
        size: 220,
        cell: ({ row }) => {
          const id = row.original.id
          return (
            <div className="flex items-center gap-1">
              <Link
                to="/customers/$customerKey"
                params={{ customerKey: id }}
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
        header: ({ column }) => <ColumnHeader column={column} title={t('customers.columnName')} />,
        cell: ({ row }) => (
          <Link
            to="/customers/$customerKey"
            params={{ customerKey: row.original.id }}
            className="text-primary hover:underline"
          >
            {row.original.name}
          </Link>
        ),
        enableSorting: true,
      },
      {
        accessorKey: 'email',
        header: t('customers.columnEmail'),
        cell: ({ row }) => row.original.email ?? '—',
        enableSorting: false,
      },
      {
        accessorKey: 'phone',
        header: t('customers.columnPhone'),
        cell: ({ row }) => row.original.phone ?? '—',
        enableSorting: false,
        meta: { hiddenOnMobile: true },
      },
      {
        accessorKey: 'default_payment_method_id',
        header: t('customers.columnPaymentMethods'),
        size: 140,
        cell: ({ row }) => (row.original.default_payment_method_id ? '1+' : '0'),
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
  const baseParams = useMemo<CustomerListParams>(() => {
    const params: CustomerListParams = {}

    if (filterValues.search) params.search = filterValues.search

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
  const fullParams = useMemo<CustomerListParams>(
    () => ({
      ...baseParams,
      page: currentPage,
      per_page: perPage,
    }),
    [baseParams, currentPage, perPage],
  )

  // Fetch data
  const query = useCustomersList(fullParams)

  // Transform data for table
  const rows = useMemo<CustomerRow[]>(() => {
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
          <Users className="text-muted-foreground h-7 w-7" />
          <h1 className="text-3xl font-bold">{t('customers.title')}</h1>
        </div>
        <div className="flex items-center gap-2">
          <Button onClick={() => setCreateOpen(true)} size="sm">
            <Plus className="mr-1 h-4 w-4" />
            {t('customers.createButton')}
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
        emptyTitle={t('customers.emptyTitle')}
        emptyDescription={t('customers.emptyDesc')}
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

      <CreateCustomerDialog open={createOpen} onOpenChange={setCreateOpen} />
    </div>
  )
}

// ─── Create Customer Dialog ─────────────────────────────────

function CreateCustomerDialog({
  open,
  onOpenChange,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const { t } = useTranslation()
  const createMutation = useCreateCustomer()

  const createCustomerSchema = z.object({
    name: z.string().min(1, t('customers.nameRequired')),
    email: z.string().email(t('customers.emailInvalid')).or(z.literal('')).optional(),
    phone: z.string().optional(),
    description: z.string().optional(),
  })

  type CreateCustomerForm = z.infer<typeof createCustomerSchema>

  const form = useForm<CreateCustomerForm>({
    resolver: zodResolver(createCustomerSchema),
    defaultValues: {
      name: '',
      email: '',
      phone: '',
      description: '',
    },
  })

  function onSubmit(values: CreateCustomerForm) {
    createMutation.mutate(
      {
        name: values.name,
        email: values.email || undefined,
        phone: values.phone || undefined,
        description: values.description || undefined,
      },
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
          <DialogTitle>{t('customers.createTitle')}</DialogTitle>
          <DialogDescription>{t('customers.createDesc')}</DialogDescription>
        </DialogHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <FormField
              control={form.control}
              name="name"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('customers.columnName')}</FormLabel>
                  <FormControl>
                    <Input placeholder={t('customers.placeholderName')} {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="email"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('customers.columnEmail')}</FormLabel>
                  <FormControl>
                    <Input type="email" placeholder={t('customers.placeholderEmail')} {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="phone"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('customers.columnPhone')}</FormLabel>
                  <FormControl>
                    <Input placeholder={t('customers.placeholderPhone')} {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="description"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('common.description')}</FormLabel>
                  <FormControl>
                    <Input placeholder={t('customers.placeholderDescription')} {...field} />
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
