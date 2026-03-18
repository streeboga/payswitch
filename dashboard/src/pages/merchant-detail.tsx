import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams, Link, useNavigate } from '@tanstack/react-router'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { ArrowLeft, Store, Key, Plug, Route, LayoutDashboard, Pencil, Trash2 } from 'lucide-react'

import { useMerchantDetail, useUpdateMerchant, useDeleteMerchant } from '@/hooks/use-organizations'
import { useProfilesList } from '@/hooks/use-profiles'
import { useApiKeysList } from '@/hooks/use-api-keys'
import { useConnectorsList } from '@/hooks/use-connectors'
import { useRoutingRulesList } from '@/hooks/use-routing-rules'
import { DateFormat } from '@/components/shared/date-format'
import { CopyButton } from '@/components/shared/copy-button'
import { ErrorState } from '@/components/shared/error-state'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
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
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { Badge } from '@/components/ui/badge'

// ─── Loading Skeleton ───────────────────────────────────────

function MerchantDetailSkeleton() {
  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <Skeleton className="h-8 w-8" />
        <Skeleton className="h-8 w-64" />
      </div>
      <Skeleton className="h-32" />
      <Skeleton className="h-64" />
    </div>
  )
}

// ─── Page Component ─────────────────────────────────────────

export function MerchantDetailPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { merchantKey } = useParams({ strict: false }) as { merchantKey: string }
  const { data, isLoading, isError, error, refetch } = useMerchantDetail(merchantKey)
  const [editOpen, setEditOpen] = useState(false)
  const [deleteOpen, setDeleteOpen] = useState(false)

  if (isLoading) {
    return (
      <div className="space-y-6">
        <Link
          to="/merchants"
          className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('merchantDetail.backToMerchants')}
        </Link>
        <MerchantDetailSkeleton />
      </div>
    )
  }

  if (isError) {
    return (
      <div className="space-y-6">
        <Link
          to="/merchants"
          className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('merchantDetail.backToMerchants')}
        </Link>
        <ErrorState
          status={(error as { status?: number })?.status ?? 500}
          onRetry={() => void refetch()}
        />
      </div>
    )
  }

  if (!data) return null

  const merchant = data

  return (
    <div className="space-y-6">
      <Link
        to="/merchants"
        className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
      >
        <ArrowLeft className="h-4 w-4" />
        {t('merchantDetail.backToMerchants')}
      </Link>

      <div className="flex items-center gap-3">
        <Store className="text-muted-foreground h-7 w-7" />
        <div className="flex-1">
          <div className="flex items-center gap-2">
            <h1 className="text-2xl font-bold">{merchant.name}</h1>
            <Button
              variant="ghost"
              size="icon"
              className="h-7 w-7"
              onClick={() => setEditOpen(true)}
            >
              <Pencil className="h-4 w-4" />
            </Button>
            <Button
              variant="ghost"
              size="icon"
              className="text-destructive h-7 w-7"
              onClick={() => setDeleteOpen(true)}
            >
              <Trash2 className="h-4 w-4" />
            </Button>
          </div>
          <div className="flex items-center gap-2">
            <span className="text-muted-foreground font-mono text-sm">{merchant.id}</span>
            <CopyButton value={merchant.id} />
          </div>
        </div>
      </div>

      <Tabs defaultValue="overview">
        <TabsList>
          <TabsTrigger value="overview" className="gap-1.5">
            <LayoutDashboard className="h-4 w-4" />
            {t('merchantDetail.tabOverview')}
          </TabsTrigger>
          <TabsTrigger value="profiles" className="gap-1.5">
            <Store className="h-4 w-4" />
            {t('merchantDetail.tabProfiles')}
          </TabsTrigger>
          <TabsTrigger value="keys" className="gap-1.5">
            <Key className="h-4 w-4" />
            {t('merchantDetail.tabKeys')}
          </TabsTrigger>
          <TabsTrigger value="connectors" className="gap-1.5">
            <Plug className="h-4 w-4" />
            {t('merchantDetail.tabConnectors')}
          </TabsTrigger>
          <TabsTrigger value="routing" className="gap-1.5">
            <Route className="h-4 w-4" />
            {t('merchantDetail.tabRouting')}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="overview" className="mt-6">
          <div className="grid gap-6 md:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle>{t('merchantDetail.cardInfo')}</CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <InfoRow label={t('merchantDetail.labelName')}>{merchant.name}</InfoRow>
                <InfoRow label={t('merchantDetail.labelPublishableKey')}>
                  <div className="flex items-center gap-1">
                    <span className="font-mono text-xs">{merchant.publishable_key}</span>
                    <CopyButton value={merchant.publishable_key} />
                  </div>
                </InfoRow>
                <InfoRow label={t('merchantDetail.labelOrganization')}>
                  <span className="font-mono text-xs">{merchant.organization_id}</span>
                </InfoRow>
                <InfoRow label={t('merchantDetail.labelCreated')}>
                  <DateFormat date={merchant.created_at} />
                </InfoRow>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>{t('merchantDetail.cardStats')}</CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <InfoRow label={t('merchantDetail.labelProfilesCount')}>{merchant.profiles_count}</InfoRow>
                <InfoRow label={t('merchantDetail.labelConnectorsCount')}>{merchant.connectors_count}</InfoRow>
              </CardContent>
            </Card>
          </div>
        </TabsContent>

        <TabsContent value="profiles" className="mt-6 space-y-4">
          <ProfilesTab />
          <div className="flex justify-end">
            <Button asChild size="sm" variant="outline">
              <Link to="/profiles">{t('merchantDetail.profilesLink')}</Link>
            </Button>
          </div>
        </TabsContent>

        <TabsContent value="keys" className="mt-6 space-y-4">
          <ApiKeysTab />
          <div className="flex justify-end">
            <Button asChild size="sm" variant="outline">
              <Link to="/api-keys">{t('merchantDetail.keysLink')}</Link>
            </Button>
          </div>
        </TabsContent>

        <TabsContent value="connectors" className="mt-6 space-y-4">
          <ConnectorsTab />
          <div className="flex justify-end">
            <Button asChild size="sm" variant="outline">
              <Link to="/connectors">{t('merchantDetail.connectorsLink')}</Link>
            </Button>
          </div>
        </TabsContent>

        <TabsContent value="routing" className="mt-6 space-y-4">
          <RoutingTab />
          <div className="flex justify-end">
            <Button asChild size="sm" variant="outline">
              <Link to="/routing">{t('merchantDetail.routingLink')}</Link>
            </Button>
          </div>
        </TabsContent>
      </Tabs>

      <EditMerchantDialog
        open={editOpen}
        onOpenChange={setEditOpen}
        merchantKey={merchant.id}
        merchantName={merchant.name}
      />

      <DeleteMerchantDialog
        open={deleteOpen}
        onOpenChange={setDeleteOpen}
        merchantKey={merchant.id}
        onDeleted={() => void navigate({ to: '/merchants' })}
      />
    </div>
  )
}

function InfoRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-baseline justify-between gap-4">
      <span className="text-muted-foreground text-sm">{label}</span>
      <div className="text-right text-sm">{children}</div>
    </div>
  )
}

// ─── Profiles Tab ───────────────────────────────────────────

function ProfilesTab() {
  const { t } = useTranslation()
  const { data, isLoading } = useProfilesList()

  if (isLoading) return <Skeleton className="h-32" />

  const items = data?.items ?? []

  if (items.length === 0) {
    return (
      <Card>
        <CardContent className="py-8 text-center">
          <p className="text-muted-foreground text-sm">{t('merchantDetail.profilesEmpty')}</p>
        </CardContent>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('merchantDetail.profilesSection')}</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b">
                <th className="text-muted-foreground px-3 py-2 text-left font-medium">{t('common.id')}</th>
                <th className="text-muted-foreground px-3 py-2 text-left font-medium">{t('merchantDetail.profilesColumnWebhook')}</th>
                <th className="text-muted-foreground px-3 py-2 text-left font-medium">{t('merchantDetail.profilesColumnConnectors')}</th>
                <th className="text-muted-foreground px-3 py-2 text-left font-medium">{t('merchantDetail.profilesColumnRules')}</th>
                <th className="text-muted-foreground px-3 py-2 text-left font-medium">{t('merchantDetail.profilesColumnDate')}</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.id} className="border-b last:border-0">
                  <td className="px-3 py-2">
                    <Link
                      to="/profiles/$profileKey"
                      params={{ profileKey: item.id }}
                      className="text-primary font-mono text-xs hover:underline"
                    >
                      {item.id}
                    </Link>
                  </td>
                  <td className="px-3 py-2">
                    {item.webhook_url ? (
                      <span className="font-mono text-xs">{item.webhook_url.length > 40 ? item.webhook_url.slice(0, 40) + '...' : item.webhook_url}</span>
                    ) : (
                      <span className="text-muted-foreground">—</span>
                    )}
                  </td>
                  <td className="px-3 py-2">{item.connectors_count}</td>
                  <td className="px-3 py-2">{item.routing_rules_count}</td>
                  <td className="px-3 py-2"><DateFormat date={item.created_at} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </CardContent>
    </Card>
  )
}

// ─── API Keys Tab ───────────────────────────────────────────

function ApiKeysTab() {
  const { t } = useTranslation()
  const { data, isLoading } = useApiKeysList()

  if (isLoading) return <Skeleton className="h-32" />

  const items = data?.items ?? []

  if (items.length === 0) {
    return (
      <Card>
        <CardContent className="py-8 text-center">
          <p className="text-muted-foreground text-sm">{t('merchantDetail.keysEmpty')}</p>
        </CardContent>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('merchantDetail.keysSection')}</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b">
                <th className="text-muted-foreground px-3 py-2 text-left font-medium">{t('merchantDetail.keysColumnName')}</th>
                <th className="text-muted-foreground px-3 py-2 text-left font-medium">{t('merchantDetail.keysColumnPrefix')}</th>
                <th className="text-muted-foreground px-3 py-2 text-left font-medium">{t('merchantDetail.keysColumnType')}</th>
                <th className="text-muted-foreground px-3 py-2 text-left font-medium">{t('merchantDetail.keysColumnStatus')}</th>
                <th className="text-muted-foreground px-3 py-2 text-left font-medium">{t('merchantDetail.keysColumnDate')}</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.id} className="border-b last:border-0">
                  <td className="px-3 py-2">{item.name}</td>
                  <td className="px-3 py-2"><span className="font-mono text-xs">{item.key_prefix}</span></td>
                  <td className="px-3 py-2">{item.type}</td>
                  <td className="px-3 py-2">
                    {item.revoked_at ? (
                      <Badge variant="destructive">{t('apiKeys.statusRevoked')}</Badge>
                    ) : (
                      <Badge variant="secondary">{t('apiKeys.statusActive')}</Badge>
                    )}
                  </td>
                  <td className="px-3 py-2"><DateFormat date={item.created_at} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </CardContent>
    </Card>
  )
}

// ─── Connectors Tab ─────────────────────────────────────────

function ConnectorsTab() {
  const { t } = useTranslation()
  const { data, isLoading } = useConnectorsList()

  if (isLoading) return <Skeleton className="h-32" />

  const items = data?.items ?? []

  if (items.length === 0) {
    return (
      <Card>
        <CardContent className="py-8 text-center">
          <p className="text-muted-foreground text-sm">{t('merchantDetail.connectorsEmpty')}</p>
        </CardContent>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('merchantDetail.connectorsSection')}</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b">
                <th className="text-muted-foreground px-3 py-2 text-left font-medium">{t('merchantDetail.connectorsColumnName')}</th>
                <th className="text-muted-foreground px-3 py-2 text-left font-medium">{t('merchantDetail.connectorsColumnType')}</th>
                <th className="text-muted-foreground px-3 py-2 text-left font-medium">{t('merchantDetail.connectorsColumnTestMode')}</th>
                <th className="text-muted-foreground px-3 py-2 text-left font-medium">{t('merchantDetail.connectorsColumnDisabled')}</th>
                <th className="text-muted-foreground px-3 py-2 text-left font-medium">{t('merchantDetail.connectorsColumnDate')}</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.id} className="border-b last:border-0">
                  <td className="px-3 py-2">{item.connector_name}</td>
                  <td className="px-3 py-2">{item.connector_type}</td>
                  <td className="px-3 py-2">
                    {item.test_mode ? (
                      <Badge variant="outline">{t('common.yes')}</Badge>
                    ) : (
                      <span className="text-muted-foreground">{t('common.no')}</span>
                    )}
                  </td>
                  <td className="px-3 py-2">
                    {item.disabled ? (
                      <Badge variant="destructive">{t('common.yes')}</Badge>
                    ) : (
                      <span className="text-muted-foreground">{t('common.no')}</span>
                    )}
                  </td>
                  <td className="px-3 py-2"><DateFormat date={item.created_at} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </CardContent>
    </Card>
  )
}

// ─── Routing Tab ────────────────────────────────────────────

function RoutingTab() {
  const { t } = useTranslation()
  const { data, isLoading } = useRoutingRulesList()

  if (isLoading) return <Skeleton className="h-32" />

  const items = data?.items ?? []

  if (items.length === 0) {
    return (
      <Card>
        <CardContent className="py-8 text-center">
          <p className="text-muted-foreground text-sm">{t('merchantDetail.routingEmpty')}</p>
        </CardContent>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('merchantDetail.routingSection')}</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b">
                <th className="text-muted-foreground px-3 py-2 text-left font-medium">{t('merchantDetail.routingColumnName')}</th>
                <th className="text-muted-foreground px-3 py-2 text-left font-medium">{t('merchantDetail.routingColumnType')}</th>
                <th className="text-muted-foreground px-3 py-2 text-left font-medium">{t('merchantDetail.routingColumnActive')}</th>
                <th className="text-muted-foreground px-3 py-2 text-left font-medium">{t('merchantDetail.routingColumnPriority')}</th>
                <th className="text-muted-foreground px-3 py-2 text-left font-medium">{t('merchantDetail.routingColumnDate')}</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.id} className="border-b last:border-0">
                  <td className="px-3 py-2">{item.name}</td>
                  <td className="px-3 py-2">{item.type}</td>
                  <td className="px-3 py-2">
                    {item.active ? (
                      <Badge variant="secondary">{t('common.yes')}</Badge>
                    ) : (
                      <span className="text-muted-foreground">{t('common.no')}</span>
                    )}
                  </td>
                  <td className="px-3 py-2">{item.priority}</td>
                  <td className="px-3 py-2"><DateFormat date={item.created_at} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </CardContent>
    </Card>
  )
}

// ─── Edit Merchant Dialog ───────────────────────────────────

function EditMerchantDialog({
  open,
  onOpenChange,
  merchantKey,
  merchantName,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  merchantKey: string
  merchantName: string
}) {
  const { t } = useTranslation()
  const updateMutation = useUpdateMerchant()

  const editSchema = z.object({
    name: z.string().min(1, t('merchants.nameRequired')),
  })

  type EditForm = z.infer<typeof editSchema>

  const form = useForm<EditForm>({
    resolver: zodResolver(editSchema),
    defaultValues: { name: merchantName },
  })

  function onSubmit(values: EditForm) {
    updateMutation.mutate(
      { merchantKey, data: { name: values.name } },
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
  merchantKey,
  onDeleted,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  merchantKey: string
  onDeleted: () => void
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
              deleteMutation.mutate(merchantKey, {
                onSuccess: () => {
                  onOpenChange(false)
                  onDeleted()
                },
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
