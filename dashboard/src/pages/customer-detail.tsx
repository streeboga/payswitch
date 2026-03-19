import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams, Link, useNavigate } from '@tanstack/react-router'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { ArrowLeft, Users, Pencil, Trash2, CreditCard, ShieldCheck } from 'lucide-react'

import type { PaymentMethodAttributes, PaymentIntentAttributes } from '@/api/types'
import {
  useCustomerDetail,
  useUpdateCustomer,
  useDeleteCustomer,
} from '@/hooks/use-customers'
import { StatusBadge } from '@/components/shared/status-badge'
import { MoneyFormat } from '@/components/shared/money-format'
import { DateFormat } from '@/components/shared/date-format'
import { CopyButton } from '@/components/shared/copy-button'
import { JsonViewer } from '@/components/shared/json-viewer'
import { EmptyState } from '@/components/shared/empty-state'
import { ErrorState } from '@/components/shared/error-state'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
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

// ─── Edit Schema Type ────────────────────────────────────────

type EditCustomerForm = {
  name: string
  email?: string
  phone?: string
  description?: string
}

// ─── Loading Skeleton ───────────────────────────────────────

function CustomerDetailSkeleton() {
  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <Skeleton className="h-8 w-8" />
        <Skeleton className="h-8 w-64" />
      </div>
      <div className="grid gap-6 md:grid-cols-2">
        <Skeleton className="h-48" />
        <Skeleton className="h-48" />
      </div>
      <Skeleton className="h-64" />
    </div>
  )
}

// ─── Page Component ─────────────────────────────────────────

export function CustomerDetailPage() {
  const { t } = useTranslation()
  const { customerKey } = useParams({ strict: false }) as { customerKey: string }
  const navigate = useNavigate()
  const { data, isLoading, isError, error, refetch } = useCustomerDetail(customerKey)

  const [editOpen, setEditOpen] = useState(false)
  const [deleteOpen, setDeleteOpen] = useState(false)

  const deleteMutation = useDeleteCustomer()

  function handleDelete() {
    deleteMutation.mutate(customerKey, {
      onSuccess: () => {
        setDeleteOpen(false)
        void navigate({ to: '/customers' })
      },
    })
  }

  if (isLoading) {
    return (
      <div className="space-y-6">
        <Link
          to="/customers"
          className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('customerDetail.backToCustomers')}
        </Link>
        <CustomerDetailSkeleton />
      </div>
    )
  }

  if (isError) {
    return (
      <div className="space-y-6">
        <Link
          to="/customers"
          className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('customerDetail.backToCustomers')}
        </Link>
        <ErrorState
          status={(error as { status?: number })?.status ?? 500}
          onRetry={() => void refetch()}
        />
      </div>
    )
  }

  if (!data) return null

  const { customer, paymentMethods, payments } = data

  return (
    <div className="space-y-6">
      {/* Back link */}
      <Link
        to="/customers"
        className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
      >
        <ArrowLeft className="h-4 w-4" />
        {t('customerDetail.backToCustomers')}
      </Link>

      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-3">
          <Users className="text-muted-foreground h-7 w-7" />
          <div>
            <div className="flex items-center gap-2">
              <h1 className="font-mono text-2xl font-bold">{customer.id}</h1>
              <CopyButton value={customer.id} />
            </div>
            {customer.name && (
              <p className="text-muted-foreground text-sm">{customer.name}</p>
            )}
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" onClick={() => setEditOpen(true)}>
            <Pencil className="mr-1 h-4 w-4" />
            {t('customerDetail.editButton')}
          </Button>
          <Button variant="outline" size="sm" onClick={() => setDeleteOpen(true)}>
            <Trash2 className="mr-1 h-4 w-4" />
            {t('customerDetail.deleteButton')}
          </Button>
        </div>
      </div>

      {/* Info Card */}
      <div className="grid gap-6 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>{t('customerDetail.cardInfo')}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            <InfoRow label={t('common.email')}>{customer.email ?? '—'}</InfoRow>
            <InfoRow label={t('customers.columnPhone')}>{customer.phone ?? '—'}</InfoRow>
            <InfoRow label={t('common.description')}>
              {customer.description ?? '—'}
            </InfoRow>
            <InfoRow label={t('common.date')}>
              <DateFormat date={customer.created_at} />
            </InfoRow>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{t('customerDetail.cardMetadata')}</CardTitle>
          </CardHeader>
          <CardContent>
            {customer.metadata && Object.keys(customer.metadata).length > 0 ? (
              <JsonViewer data={customer.metadata} defaultExpanded={false} />
            ) : (
              <p className="text-muted-foreground text-sm">
                {t('customerDetail.noMetadata')}
              </p>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Payment Methods */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <CreditCard className="h-5 w-5" />
            {t('customerDetail.cardPaymentMethods')} ({paymentMethods.length})
          </CardTitle>
        </CardHeader>
        <CardContent>
          {paymentMethods.length === 0 ? (
            <EmptyState
              title={t('customerDetail.noPaymentMethods')}
              description={t('customerDetail.noPaymentMethodsDesc')}
              className="py-6"
            />
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('common.id')}</TableHead>
                  <TableHead>{t('customerDetail.columnType')}</TableHead>
                  <TableHead>{t('customerDetail.columnBrand')}</TableHead>
                  <TableHead>{t('customerDetail.columnLast4')}</TableHead>
                  <TableHead>{t('customerDetail.columnExpiry')}</TableHead>
                  <TableHead>{t('customerDetail.columnDefault')}</TableHead>
                  <TableHead>{t('common.connector')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {paymentMethods.map((pm) => (
                  <PaymentMethodRow key={pm.id} pm={pm} />
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {/* Customer Payments */}
      <Card>
        <CardHeader>
          <CardTitle>
            {t('customerDetail.cardPayments')} ({payments.length})
          </CardTitle>
        </CardHeader>
        <CardContent>
          {payments.length === 0 ? (
            <EmptyState
              title={t('customerDetail.noPayments')}
              description={t('customerDetail.noPaymentsDesc')}
              className="py-6"
            />
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('common.id')}</TableHead>
                  <TableHead>{t('common.amount')}</TableHead>
                  <TableHead>{t('common.status')}</TableHead>
                  <TableHead>{t('common.connector')}</TableHead>
                  <TableHead>{t('common.date')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {payments.map((payment) => (
                  <CustomerPaymentRow key={payment.id} payment={payment} />
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {/* Edit Dialog */}
      <EditCustomerDialog
        open={editOpen}
        onOpenChange={setEditOpen}
        customerKey={customerKey}
        defaultValues={{
          name: customer.name,
          email: customer.email ?? '',
          phone: customer.phone ?? '',
          description: customer.description ?? '',
        }}
      />

      {/* Delete Confirm */}
      <ConfirmDialog
        open={deleteOpen}
        onConfirm={handleDelete}
        onCancel={() => setDeleteOpen(false)}
        title={t('customerDetail.deleteTitle')}
        description={t('customerDetail.deleteDesc', { name: customer.id })}
        confirmLabel={t('common.delete')}
        cancelLabel={t('common.cancel')}
        destructive
      />
    </div>
  )
}

// ─── Sub-components ─────────────────────────────────────────

function InfoRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-baseline justify-between gap-4">
      <span className="text-muted-foreground text-sm">{label}</span>
      <div className="text-right text-sm">{children}</div>
    </div>
  )
}

function PaymentMethodRow({ pm }: { pm: PaymentMethodAttributes & { id: string } }) {
  const { t } = useTranslation()
  return (
    <TableRow>
      <TableCell>
        <span className="font-mono text-xs">{pm.id}</span>
      </TableCell>
      <TableCell className="capitalize">{pm.type}</TableCell>
      <TableCell>{pm.card_brand ?? '—'}</TableCell>
      <TableCell>
        {pm.card_last4 ? <span className="font-mono">****{pm.card_last4}</span> : '—'}
      </TableCell>
      <TableCell>
        {pm.card_exp_month && pm.card_exp_year
          ? `${String(pm.card_exp_month).padStart(2, '0')}/${pm.card_exp_year}`
          : '—'}
      </TableCell>
      <TableCell>
        {pm.is_default ? (
          <Badge variant="secondary">
            <ShieldCheck className="mr-1 h-3 w-3" />
            {t('customerDetail.badgeDefault')}
          </Badge>
        ) : (
          '—'
        )}
      </TableCell>
      <TableCell>{pm.connector_name}</TableCell>
    </TableRow>
  )
}

function CustomerPaymentRow({
  payment,
}: {
  payment: PaymentIntentAttributes & { id: string }
}) {
  return (
    <TableRow>
      <TableCell>
        <Link
          to="/payments/$paymentKey"
          params={{ paymentKey: payment.id }}
          className="text-primary font-mono text-sm hover:underline"
        >
          {payment.id}
        </Link>
      </TableCell>
      <TableCell>
        <MoneyFormat amount={payment.amount} currency={payment.currency} />
      </TableCell>
      <TableCell>
        <StatusBadge status={payment.status} />
      </TableCell>
      <TableCell>{payment.connector ?? '—'}</TableCell>
      <TableCell>
        <DateFormat date={payment.created_at} />
      </TableCell>
    </TableRow>
  )
}

// ─── Edit Customer Dialog ───────────────────────────────────

function EditCustomerDialog({
  open,
  onOpenChange,
  customerKey,
  defaultValues,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  customerKey: string
  defaultValues: EditCustomerForm
}) {
  const { t } = useTranslation()
  const updateMutation = useUpdateCustomer()

  const editCustomerSchema = z.object({
    name: z.string().min(1, t('customerDetail.nameRequired')),
    email: z
      .string()
      .email(t('customerDetail.emailInvalid'))
      .or(z.literal(''))
      .optional(),
    phone: z.string().optional(),
    description: z.string().optional(),
  })

  const form = useForm<EditCustomerForm>({
    resolver: zodResolver(editCustomerSchema),
    defaultValues,
  })

  function onSubmit(values: EditCustomerForm) {
    updateMutation.mutate(
      {
        key: customerKey,
        attrs: {
          name: values.name,
          email: values.email || undefined,
          phone: values.phone || undefined,
          description: values.description || undefined,
        },
      },
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
          <DialogTitle>{t('customerDetail.editTitle')}</DialogTitle>
          <DialogDescription>{t('customerDetail.editDesc')}</DialogDescription>
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
                    <Input
                      type="email"
                      placeholder={t('customers.placeholderEmail')}
                      {...field}
                    />
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
                    <Input
                      placeholder={t('customers.placeholderDescription')}
                      {...field}
                    />
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
