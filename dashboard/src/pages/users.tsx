import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useReactTable, getCoreRowModel, type ColumnDef } from '@tanstack/react-table'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { UserCog, Plus, MoreHorizontal } from 'lucide-react'

import type {
  DashboardUserAttributes,
  UserRole,
  UserStatus,
} from '@/api/endpoints/dashboard-users'
import {
  useUsersList,
  useInviteUser,
  useUpdateUser,
  useRemoveUser,
} from '@/hooks/use-users'
import { DataTable, TableDensityToggle } from '@/components/data-table'
import { DateFormat } from '@/components/shared/date-format'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { Badge } from '@/components/ui/badge'
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
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
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
import { usePreferencesStore } from '@/stores/preferences'

// ─── Row Type ────────────────────────────────────────────────

type UserRow = DashboardUserAttributes & { id: string }

// ─── Role Badge ─────────────────────────────────────────────

const ROLE_BADGE_CLASS: Record<UserRole, string> = {
  admin: 'bg-purple-100 text-purple-800 dark:bg-purple-950 dark:text-purple-300',
  operator: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
  viewer: 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300',
}

const STATUS_BADGE_CLASS: Record<UserStatus, string> = {
  active: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
  invited: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
  disabled: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
}

type InviteForm = {
  email: string
  role: 'admin' | 'operator' | 'viewer'
}

// ─── Page Component ─────────────────────────────────────────

export function UsersPage() {
  const { t } = useTranslation()
  const density = usePreferencesStore((s) => s.density)
  const [inviteOpen, setInviteOpen] = useState(false)
  const [roleDialogUser, setRoleDialogUser] = useState<UserRow | null>(null)
  const [removeUser, setRemoveUser] = useState<UserRow | null>(null)

  const query = useUsersList()
  const updateMutation = useUpdateUser()
  const removeMutation = useRemoveUser()

  const ROLE_LABELS: Record<UserRole, string> = useMemo(
    () => ({
      admin: t('users.roleAdmin'),
      operator: t('users.roleOperator'),
      viewer: t('users.roleViewer'),
    }),
    [t],
  )

  const STATUS_LABELS: Record<UserStatus, string> = useMemo(
    () => ({
      active: t('users.statusActive'),
      invited: t('users.statusInvited'),
      disabled: t('users.statusDisabled'),
    }),
    [t],
  )

  const rows = useMemo<UserRow[]>(() => {
    return query.data?.items ?? []
  }, [query.data])

  // ─── Columns (defined inside component for action callbacks) ──
  const columns = useMemo<ColumnDef<UserRow, unknown>[]>(
    () => [
      {
        accessorKey: 'name',
        header: t('users.columnName'),
        cell: ({ row }) => <span className="font-medium">{row.original.name}</span>,
        enableSorting: false,
      },
      {
        accessorKey: 'email',
        header: t('users.columnEmail'),
        cell: ({ row }) => (
          <span className="text-muted-foreground text-sm">{row.original.email}</span>
        ),
        enableSorting: false,
      },
      {
        accessorKey: 'role',
        header: t('users.columnRole'),
        size: 120,
        cell: ({ row }) => {
          const role = row.original.role
          return (
            <Badge variant="outline" className={ROLE_BADGE_CLASS[role]}>
              {ROLE_LABELS[role]}
            </Badge>
          )
        },
        enableSorting: false,
      },
      {
        accessorKey: 'two_factor_enabled',
        header: t('users.column2fa'),
        size: 80,
        cell: ({ row }) => (row.original.two_factor_enabled ? '\u2705' : '\u274C'),
        enableSorting: false,
      },
      {
        accessorKey: 'last_login_at',
        header: t('users.columnLastLogin'),
        size: 180,
        cell: ({ row }) =>
          row.original.last_login_at ? (
            <DateFormat date={row.original.last_login_at} variant="relative" />
          ) : (
            <span className="text-muted-foreground text-sm">&mdash;</span>
          ),
        enableSorting: false,
      },
      {
        accessorKey: 'status',
        header: t('users.columnStatus'),
        size: 120,
        cell: ({ row }) => {
          const status = row.original.status
          return (
            <Badge variant="outline" className={STATUS_BADGE_CLASS[status]}>
              {STATUS_LABELS[status]}
            </Badge>
          )
        },
        enableSorting: false,
      },
      {
        id: 'actions',
        size: 60,
        cell: ({ row }) => {
          const user = row.original
          return (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="h-8 w-8">
                  <MoreHorizontal className="h-4 w-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={() => setRoleDialogUser(user)}>
                  {t('users.changeRoleTitle')}
                </DropdownMenuItem>
                <DropdownMenuItem
                  onClick={() =>
                    updateMutation.mutate({
                      id: user.id,
                      data: {
                        status: user.status === 'disabled' ? 'active' : 'disabled',
                      },
                    })
                  }
                >
                  {user.status === 'disabled' ? t('common.enable') : t('common.disable')}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  className="text-destructive"
                  onClick={() => setRemoveUser(user)}
                >
                  {t('common.delete')}
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          )
        },
        enableSorting: false,
      },
    ],
    [t, updateMutation, ROLE_LABELS, STATUS_LABELS],
  )

  const table = useReactTable({
    data: rows,
    columns,
    getCoreRowModel: getCoreRowModel(),
  })

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <UserCog className="text-muted-foreground h-7 w-7" />
          <h1 className="text-3xl font-bold">{t('users.title')}</h1>
        </div>
        <div className="flex items-center gap-2">
          <Button onClick={() => setInviteOpen(true)} size="sm">
            <Plus className="mr-1 h-4 w-4" />
            {t('users.inviteButton')}
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
        emptyTitle={t('users.emptyTitle')}
        emptyDescription={t('users.emptyDesc')}
        density={density}
      />

      {/* Invite Dialog */}
      <InviteDialog open={inviteOpen} onOpenChange={setInviteOpen} />

      {/* Change Role Dialog */}
      {roleDialogUser && (
        <ChangeRoleDialog user={roleDialogUser} onClose={() => setRoleDialogUser(null)} />
      )}

      {/* Remove Confirm */}
      <ConfirmDialog
        open={!!removeUser}
        title={t('users.deleteTitle')}
        description={t('users.deleteDesc', { name: removeUser?.name ?? '' })}
        confirmLabel={t('common.delete')}
        cancelLabel={t('common.cancel')}
        destructive
        onConfirm={() => {
          if (removeUser) {
            removeMutation.mutate(removeUser.id)
          }
          setRemoveUser(null)
        }}
        onCancel={() => setRemoveUser(null)}
      />
    </div>
  )
}

// ─── Invite Dialog ──────────────────────────────────────────

function InviteDialog({
  open,
  onOpenChange,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const { t } = useTranslation()
  const inviteMutation = useInviteUser()

  const inviteSchema = useMemo(
    () =>
      z.object({
        email: z.string().email(t('users.emailInvalid')),
        role: z.enum(['admin', 'operator', 'viewer']),
      }),
    [t],
  )

  const form = useForm<InviteForm>({
    resolver: zodResolver(inviteSchema),
    defaultValues: { email: '', role: 'viewer' },
  })

  function onSubmit(values: InviteForm) {
    inviteMutation.mutate(values, {
      onSuccess: () => {
        form.reset()
        onOpenChange(false)
      },
    })
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('users.inviteTitle')}</DialogTitle>
          <DialogDescription>
            {t('users.inviteDesc')}
          </DialogDescription>
        </DialogHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <FormField
              control={form.control}
              name="email"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('users.emailLabel')}</FormLabel>
                  <FormControl>
                    <Input placeholder={t('users.emailPlaceholder')} {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="role"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('users.roleLabel')}</FormLabel>
                  <Select onValueChange={field.onChange} defaultValue={field.value}>
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue placeholder={t('users.rolePlaceholder')} />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      <SelectItem value="admin">{t('users.roleAdmin')}</SelectItem>
                      <SelectItem value="operator">{t('users.roleOperator')}</SelectItem>
                      <SelectItem value="viewer">{t('users.roleViewer')}</SelectItem>
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
              <Button type="submit" disabled={inviteMutation.isPending}>
                {inviteMutation.isPending ? t('users.inviting') : t('users.inviteButton')}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  )
}

// ─── Change Role Dialog ─────────────────────────────────────

function ChangeRoleDialog({ user, onClose }: { user: UserRow; onClose: () => void }) {
  const { t } = useTranslation()
  const updateMutation = useUpdateUser()
  const [role, setRole] = useState<UserRole>(user.role)

  function handleSave() {
    updateMutation.mutate({ id: user.id, data: { role } }, { onSuccess: onClose })
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('users.changeRoleTitle')}</DialogTitle>
          <DialogDescription>{t('users.changeRoleDesc', { name: user.name })}</DialogDescription>
        </DialogHeader>

        <Select value={role} onValueChange={(v) => setRole(v as UserRole)}>
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="admin">{t('users.roleAdmin')}</SelectItem>
            <SelectItem value="operator">{t('users.roleOperator')}</SelectItem>
            <SelectItem value="viewer">{t('users.roleViewer')}</SelectItem>
          </SelectContent>
        </Select>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button type="button" onClick={handleSave} disabled={updateMutation.isPending}>
            {updateMutation.isPending ? t('common.saving') : t('common.save')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
