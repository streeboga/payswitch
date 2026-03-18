import { lazy, Suspense, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import {
  Cable,
  Plus,
  MoreVertical,
  Pencil,
  Trash2,
  CreditCard,
  Cloud,
  TestTube,
} from 'lucide-react'
import { Link } from '@tanstack/react-router'

import type { ConnectorAttributes, ConnectorName } from '@/api/types'
import {
  useConnectorsList,
  useUpdateConnector,
  useDeleteConnector,
} from '@/hooks/use-connectors'
const ConnectWizard = lazy(() =>
  import('@/components/connectors/connect-wizard').then((m) => ({
    default: m.ConnectWizard,
  })),
)
import { EmptyState } from '@/components/shared/empty-state'
import { ErrorState } from '@/components/shared/error-state'
import { CopyButton } from '@/components/shared/copy-button'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader } from '@/components/ui/card'
import { Switch } from '@/components/ui/switch'
import { Skeleton } from '@/components/ui/skeleton'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'

// ─── Row Type ────────────────────────────────────────────────

type ConnectorRow = ConnectorAttributes & { id: string }

// ─── Connector Icons ─────────────────────────────────────────

const CONNECTOR_ICONS: Record<ConnectorName, typeof CreditCard> = {
  stripe: CreditCard,
  cloudpayments: Cloud,
  test: TestTube,
}

const CONNECTOR_LABELS: Record<ConnectorName, string> = {
  stripe: 'Stripe',
  cloudpayments: 'CloudPayments',
  test: 'Test',
}

// ─── Connector Card ──────────────────────────────────────────

function ConnectorCard({
  connector,
  onToggle,
  onDelete,
  isToggling,
}: {
  connector: ConnectorRow
  onToggle: (connector: ConnectorRow) => void
  onDelete: (connector: ConnectorRow) => void
  isToggling: boolean
}) {
  const { t } = useTranslation()
  const Icon = CONNECTOR_ICONS[connector.connector_name] ?? CreditCard
  const isActive = !connector.disabled

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between space-y-0 pb-3">
        <div className="flex items-center gap-3">
          <div className="bg-muted flex h-10 w-10 items-center justify-center rounded-lg">
            <Icon className="text-muted-foreground h-5 w-5" />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <h3 className="font-semibold">
                {CONNECTOR_LABELS[connector.connector_name] ?? connector.connector_name}
              </h3>
              <span
                className={`inline-block h-2 w-2 rounded-full ${isActive ? 'bg-emerald-500' : 'bg-red-500'}`}
                title={isActive ? t('connectors.activeStatus') : t('connectors.disabledStatus')}
              />
            </div>
            <div className="flex items-center gap-1">
              <span className="text-muted-foreground font-mono text-xs">
                {connector.id}
              </span>
              <CopyButton value={connector.id} />
            </div>
          </div>
        </div>

        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="icon" className="h-8 w-8">
              <MoreVertical className="h-4 w-4" />
              <span className="sr-only">{t('common.actions')}</span>
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem asChild>
              <Link
                to="/connectors/$connectorKey"
                params={{ connectorKey: connector.id }}
              >
                <Pencil className="mr-2 h-4 w-4" />
                {t('connectors.editButton')}
              </Link>
            </DropdownMenuItem>
            <DropdownMenuItem onClick={() => onToggle(connector)} disabled={isToggling}>
              <Switch checked={isActive} className="mr-2" size="sm" tabIndex={-1} />
              {isActive ? t('connectors.disableButton') : t('connectors.enableButton')}
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              onClick={() => onDelete(connector)}
              className="text-destructive focus:text-destructive"
            >
              <Trash2 className="mr-2 h-4 w-4" />
              {t('connectors.deleteButton')}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </CardHeader>

      <CardContent className="space-y-3">
        {/* Payment methods */}
        <div className="flex flex-wrap gap-1">
          {connector.payment_methods_enabled.map((method, i) => (
            <Badge
              key={typeof method === 'string' ? method : (method.payment_method ?? i)}
              variant="secondary"
              className="text-xs"
            >
              {typeof method === 'string' ? method : method.payment_method}
            </Badge>
          ))}
          {connector.payment_methods_enabled.length === 0 && (
            <span className="text-muted-foreground text-xs">{t('connectors.noPaymentMethods')}</span>
          )}
        </div>

        {/* Mode indicator */}
        <div className="flex items-center justify-between">
          <Badge variant="outline" className="text-xs">
            {connector.test_mode ? 'Test' : 'Live'}
          </Badge>
        </div>
      </CardContent>
    </Card>
  )
}

// ─── Loading Skeleton ────────────────────────────────────────

function ConnectorsSkeleton() {
  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
      {Array.from({ length: 3 }, (_, i) => (
        <Card key={i}>
          <CardHeader className="flex flex-row items-start gap-3 space-y-0 pb-3">
            <Skeleton className="h-10 w-10 rounded-lg" />
            <div className="space-y-2">
              <Skeleton className="h-4 w-24" />
              <Skeleton className="h-3 w-32" />
            </div>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="flex gap-1">
              <Skeleton className="h-5 w-12" />
              <Skeleton className="h-5 w-16" />
            </div>
            <Skeleton className="h-5 w-10" />
          </CardContent>
        </Card>
      ))}
    </div>
  )
}

// ─── Page Component ──────────────────────────────────────────

export function ConnectorsPage() {
  const { t } = useTranslation()
  const [wizardOpen, setWizardOpen] = useState(false)
  const [deleteTarget, setDeleteTarget] = useState<ConnectorRow | null>(null)

  const query = useConnectorsList()
  const updateMutation = useUpdateConnector()
  const deleteMutation = useDeleteConnector()

  const connectors = useMemo<ConnectorRow[]>(() => {
    return query.data?.items ?? []
  }, [query.data])

  function handleToggle(connector: ConnectorRow) {
    updateMutation.mutate({
      key: connector.id,
      attrs: { disabled: !connector.disabled },
    })
  }

  function handleDeleteConfirm() {
    if (!deleteTarget) return
    deleteMutation.mutate(deleteTarget.id, {
      onSuccess: () => setDeleteTarget(null),
    })
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Cable className="text-muted-foreground h-7 w-7" />
          <h1 className="text-3xl font-bold">{t('connectors.title')}</h1>
        </div>
        <Button onClick={() => setWizardOpen(true)} size="sm">
          <Plus className="mr-1 h-4 w-4" />
          {t('connectors.connectButton')}
        </Button>
      </div>

      {query.isLoading && <ConnectorsSkeleton />}

      {query.isError && <ErrorState status={500} onRetry={() => void query.refetch()} />}

      {!query.isLoading && !query.isError && connectors.length === 0 && (
        <EmptyState
          title={t('connectors.emptyTitle')}
          description={t('connectors.emptyDesc')}
          icon={Cable}
          action={{
            label: t('connectors.connectButton'),
            onClick: () => setWizardOpen(true),
          }}
        />
      )}

      {!query.isLoading && !query.isError && connectors.length > 0 && (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
          {connectors.map((connector) => (
            <ConnectorCard
              key={connector.id}
              connector={connector}
              onToggle={handleToggle}
              onDelete={setDeleteTarget}
              isToggling={updateMutation.isPending}
            />
          ))}
        </div>
      )}

      <Suspense fallback={null}>
        <ConnectWizard open={wizardOpen} onOpenChange={setWizardOpen} />
      </Suspense>

      <ConfirmDialog
        open={deleteTarget !== null}
        onConfirm={handleDeleteConfirm}
        onCancel={() => setDeleteTarget(null)}
        title={t('connectors.deleteTitle')}
        description={t('connectors.deleteConfirm')}
        confirmLabel={t('common.delete')}
        cancelLabel={t('common.cancel')}
        destructive
      />
    </div>
  )
}
