import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useReactTable, getCoreRowModel, type ColumnDef } from '@tanstack/react-table'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Key, Plus, AlertTriangle } from 'lucide-react'

import type { ApiKeyAttributes } from '@/api/types'
import type { ApiKeyType } from '@/api/types'
import { useApiKeysList, useCreateApiKey, useRevokeApiKey } from '@/hooks/use-api-keys'
import {
  DataTable,
  ColumnHeader,
  TableDensityToggle,
  useTableSorting,
  useTablePagination,
} from '@/components/data-table'
import { DateFormat } from '@/components/shared/date-format'
import { CopyButton } from '@/components/shared/copy-button'
import { StatusBadge } from '@/components/shared/status-badge'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { usePreferencesStore } from '@/stores/preferences'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
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
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'

// ─── Row Type ────────────────────────────────────────────────

type ApiKeyRow = ApiKeyAttributes & { id: string }

// ─── Type Labels & Variants ─────────────────────────────────

const TYPE_LABELS: Record<ApiKeyType, string> = {
  admin: 'Admin',
  secret: 'Secret',
  publishable: 'Publishable',
}

const TYPE_VARIANTS: Record<ApiKeyType, string> = {
  admin: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
  secret: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
  publishable: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
}

// ─── Helpers ────────────────────────────────────────────────

function truncatePrefix(prefix: string): string {
  if (prefix.length > 12) {
    return prefix.slice(0, 12) + '...'
  }
  return prefix
}

// ─── Columns ────────────────────────────────────────────────

function createColumns(
  onRevoke: (row: ApiKeyRow) => void,
  t: (key: string) => string,
): ColumnDef<ApiKeyRow, unknown>[] {
  return [
    {
      accessorKey: 'name',
      header: ({ column }) => (
        <ColumnHeader column={column} title={t('apiKeys.columnName')} />
      ),
      cell: ({ row }) => <span className="font-medium">{row.original.name}</span>,
      enableSorting: true,
    },
    {
      accessorKey: 'key_prefix',
      header: t('apiKeys.columnPrefix'),
      cell: ({ row }) => {
        const prefix = row.original.key_prefix
        return (
          <div className="flex items-center gap-1">
            <code className="text-muted-foreground font-mono text-sm">
              {truncatePrefix(prefix)}
            </code>
            <CopyButton value={prefix} />
          </div>
        )
      },
      enableSorting: false,
    },
    {
      accessorKey: 'type',
      header: t('apiKeys.columnType'),
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
      accessorKey: 'expires_at',
      header: t('apiKeys.columnExpires'),
      cell: ({ row }) =>
        row.original.expires_at ? (
          <DateFormat date={row.original.expires_at} />
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
      enableSorting: false,
      meta: { hiddenOnMobile: true },
    },
    {
      id: 'status',
      header: t('apiKeys.columnStatus'),
      cell: ({ row }) =>
        row.original.revoked_at ? (
          <StatusBadge status="revoked" label={t('apiKeys.statusRevoked')} />
        ) : (
          <StatusBadge status="active" label={t('apiKeys.statusActive')} />
        ),
      enableSorting: false,
    },
    {
      accessorKey: 'created_at',
      header: ({ column }) => (
        <ColumnHeader column={column} title={t('apiKeys.columnCreated')} />
      ),
      cell: ({ row }) => <DateFormat date={row.original.created_at} />,
      enableSorting: true,
    },
    {
      id: 'actions',
      header: '',
      size: 100,
      cell: ({ row }) => {
        if (row.original.revoked_at) return null
        return (
          <Button
            variant="ghost"
            size="sm"
            className="text-destructive hover:text-destructive"
            onClick={() => onRevoke(row.original)}
          >
            {t('apiKeys.revokeButton')}
          </Button>
        )
      },
      enableSorting: false,
    },
  ]
}

// ─── Page Component ─────────────────────────────────────────

export function ApiKeysPage() {
  const { t } = useTranslation()
  const density = usePreferencesStore((s) => s.density)
  const [createOpen, setCreateOpen] = useState(false)
  const [showOnceKey, setShowOnceKey] = useState<string | null>(null)
  const [revokeTarget, setRevokeTarget] = useState<ApiKeyRow | null>(null)

  const revokeMutation = useRevokeApiKey()

  const { sorting, onSortingChange } = useTableSorting({
    defaultSort: 'created_at',
    defaultOrder: 'desc',
  })

  const { pagination, onPageChange, onPerPageChange } = useTablePagination(undefined)

  const query = useApiKeysList()

  const rows = useMemo<ApiKeyRow[]>(() => {
    return query.data?.items ?? []
  }, [query.data])

  const columns = useMemo(() => createColumns((row) => setRevokeTarget(row), t), [t])

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

  function handleCreated(fullKey: string) {
    setCreateOpen(false)
    setShowOnceKey(fullKey)
  }

  function handleRevokeConfirm() {
    if (!revokeTarget) return
    revokeMutation.mutate(revokeTarget.id, {
      onSuccess: () => setRevokeTarget(null),
    })
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Key className="text-muted-foreground h-7 w-7" />
          <h1 className="text-3xl font-bold">{t('apiKeys.title')}</h1>
        </div>
        <div className="flex items-center gap-2">
          <Button onClick={() => setCreateOpen(true)} size="sm">
            <Plus className="mr-1 h-4 w-4" />
            {t('apiKeys.createButton')}
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
        emptyTitle={t('apiKeys.emptyTitle')}
        emptyDescription={t('apiKeys.emptyDesc')}
        pagination={paginationState}
        onPageChange={onPageChange}
        onPerPageChange={onPerPageChange}
        density={density}
      />

      <CreateApiKeyDialog
        open={createOpen}
        onOpenChange={setCreateOpen}
        onCreated={handleCreated}
      />

      <ShowOnceKeyDialog fullKey={showOnceKey} onClose={() => setShowOnceKey(null)} />

      <ConfirmDialog
        open={revokeTarget !== null}
        onConfirm={handleRevokeConfirm}
        onCancel={() => setRevokeTarget(null)}
        title={t('apiKeys.revokeTitle')}
        description={t('apiKeys.revokeConfirm')}
        confirmLabel={t('apiKeys.revokeButton')}
        cancelLabel={t('common.cancel')}
        destructive
      />
    </div>
  )
}

// ─── Create API Key Dialog ──────────────────────────────────

function CreateApiKeyDialog({
  open,
  onOpenChange,
  onCreated,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCreated: (fullKey: string) => void
}) {
  const { t } = useTranslation()

  const createApiKeySchema = z.object({
    name: z.string().min(1, t('apiKeys.nameRequired')),
    type: z.enum(['admin', 'secret', 'publishable'], {
      message: t('apiKeys.typeRequired'),
    }),
  })

  type CreateApiKeyForm = z.infer<typeof createApiKeySchema>

  const createMutation = useCreateApiKey()

  const form = useForm<CreateApiKeyForm>({
    resolver: zodResolver(createApiKeySchema),
    defaultValues: {
      name: '',
      type: 'secret',
    },
  })

  function onSubmit(values: CreateApiKeyForm) {
    createMutation.mutate(values, {
      onSuccess: (response) => {
        const apiKey = response.data.attributes.api_key
        form.reset()
        if (apiKey) {
          onCreated(apiKey)
        } else {
          onOpenChange(false)
        }
      },
    })
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('apiKeys.createTitle')}</DialogTitle>
          <DialogDescription>{t('apiKeys.createDesc')}</DialogDescription>
        </DialogHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <FormField
              control={form.control}
              name="name"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('apiKeys.columnName')}</FormLabel>
                  <FormControl>
                    <Input placeholder={t('apiKeys.namePlaceholder')} {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="type"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('apiKeys.columnType')}</FormLabel>
                  <Select onValueChange={field.onChange} defaultValue={field.value}>
                    <FormControl>
                      <SelectTrigger className="w-full">
                        <SelectValue placeholder={t('apiKeys.typeRequired')} />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      <SelectItem value="admin">Admin</SelectItem>
                      <SelectItem value="secret">Secret</SelectItem>
                      <SelectItem value="publishable">Publishable</SelectItem>
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

// ─── Show-Once Key Dialog ───────────────────────────────────

function ShowOnceKeyDialog({
  fullKey,
  onClose,
}: {
  fullKey: string | null
  onClose: () => void
}) {
  const { t } = useTranslation()

  return (
    <Dialog open={fullKey !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('apiKeys.createdTitle')}</DialogTitle>
          <DialogDescription>{t('apiKeys.createdDesc')}</DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="bg-muted flex items-center gap-2 rounded-md p-3">
            <code className="flex-1 font-mono text-sm break-all">{fullKey}</code>
            {fullKey && <CopyButton value={fullKey} />}
          </div>

          <Alert variant="destructive">
            <AlertTriangle className="h-4 w-4" />
            <AlertTitle>{t('apiKeys.alertTitle')}</AlertTitle>
            <AlertDescription>{t('apiKeys.alertDesc')}</AlertDescription>
          </Alert>
        </div>

        <DialogFooter>
          <Button onClick={onClose}>{t('common.done')}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
