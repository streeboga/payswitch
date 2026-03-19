import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams, useNavigate } from '@tanstack/react-router'
import { ArrowLeft, Save, Loader2, CreditCard, Cloud, TestTube, Wifi } from 'lucide-react'

import type { ConnectorName, ConnectorAttributes } from '@/api/types'
import {
  useConnector,
  useUpdateConnector,
  useTestConnection,
} from '@/hooks/use-connectors'
import { ErrorState } from '@/components/shared/error-state'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
} from '@/components/ui/card'
import { Separator } from '@/components/ui/separator'
import { Skeleton } from '@/components/ui/skeleton'

// ─── Constants ───────────────────────────────────────────────

const CONNECTOR_LABELS: Record<ConnectorName, string> = {
  stripe: 'Stripe',
  cloudpayments: 'CloudPayments',
  test: 'Test',
}

const CONNECTOR_ICONS: Record<ConnectorName, typeof CreditCard> = {
  stripe: CreditCard,
  cloudpayments: Cloud,
  test: TestTube,
}

interface CredentialField {
  key: string
  label: string
  placeholder: string
  type?: string
}

const CREDENTIAL_FIELDS: Record<ConnectorName, CredentialField[]> = {
  stripe: [
    { key: 'api_key', label: 'API Key', placeholder: 'sk_live_...' },
    {
      key: 'api_secret',
      label: 'API Secret',
      placeholder: 'whsec_...',
      type: 'password',
    },
  ],
  cloudpayments: [
    { key: 'public_id', label: 'Public ID', placeholder: 'pk_...' },
    {
      key: 'api_secret',
      label: 'API Secret',
      placeholder: 'Секретный ключ',
      type: 'password',
    },
  ],
  test: [{ key: 'api_key', label: 'API Key', placeholder: 'test_key_123' }],
}

// ─── Loading Skeleton ────────────────────────────────────────

function DetailSkeleton() {
  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <Skeleton className="h-7 w-7" />
        <Skeleton className="h-8 w-48" />
      </div>
      <Card>
        <CardHeader>
          <Skeleton className="h-5 w-32" />
        </CardHeader>
        <CardContent className="space-y-4">
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <Skeleton className="h-5 w-40" />
        </CardHeader>
        <CardContent className="space-y-3">
          <Skeleton className="h-5 w-24" />
          <Skeleton className="h-5 w-24" />
          <Skeleton className="h-5 w-24" />
        </CardContent>
      </Card>
    </div>
  )
}

// ─── Detail Form (rendered after data loads) ────────────────

function ConnectorDetailForm({
  connector,
  connectorKey,
  onNavigateBack,
}: {
  connector: ConnectorAttributes & { id: string }
  connectorKey: string
  onNavigateBack: () => void
}) {
  const { t } = useTranslation()
  const updateMutation = useUpdateConnector()
  const testMutation = useTestConnection()

  const [credentials, setCredentials] = useState<Record<string, string>>({})
  const [paymentMethods, setPaymentMethods] = useState<string[]>(
    (connector.payment_methods_enabled ?? []).map((m) =>
      typeof m === 'string' ? m : m.payment_method,
    ),
  )
  const [disabled, setDisabled] = useState(connector.disabled)

  const connectorName = connector.connector_name
  const Icon = CONNECTOR_ICONS[connectorName] ?? CreditCard
  const fields = CREDENTIAL_FIELDS[connectorName] ?? []

  const PAYMENT_METHODS = useMemo(
    () => [
      { value: 'card', label: t('connectorDetail.paymentMethodCard') },
      { value: 'apple_pay', label: t('connectorDetail.paymentMethodApplePay') },
      { value: 'google_pay', label: t('connectorDetail.paymentMethodGooglePay') },
      { value: 'sbp', label: t('connectorDetail.paymentMethodSbp') },
      { value: 'bank_transfer', label: t('connectorDetail.paymentMethodBankTransfer') },
    ],
    [t],
  )

  function togglePaymentMethod(method: string) {
    setPaymentMethods((prev) =>
      prev.includes(method) ? prev.filter((m) => m !== method) : [...prev, method],
    )
  }

  function handleSave() {
    updateMutation.mutate(
      {
        key: connectorKey,
        attrs: {
          ...(Object.keys(credentials).length > 0
            ? { connector_account_details: credentials }
            : {}),
          payment_methods_enabled: paymentMethods,
          disabled,
        },
      },
      { onSuccess: onNavigateBack },
    )
  }

  function handleTestConnection() {
    testMutation.mutate(connectorKey)
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Button variant="ghost" size="icon" onClick={onNavigateBack}>
            <ArrowLeft className="h-5 w-5" />
          </Button>
          <Icon className="text-muted-foreground h-7 w-7" />
          <div>
            <h1 className="text-3xl font-bold">
              {CONNECTOR_LABELS[connectorName] ?? connectorName}
            </h1>
            <span className="text-muted-foreground font-mono text-sm">
              {connector.id}
            </span>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Badge variant="outline">{connector.test_mode ? 'Test' : 'Live'}</Badge>
          <Badge
            variant="outline"
            className={
              !connector.disabled
                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                : 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300'
            }
          >
            {!connector.disabled ? t('connectors.activeStatus') : t('connectors.disabledStatus')}
          </Badge>
        </div>
      </div>

      {/* Credentials Section */}
      <Card>
        <CardHeader>
          <CardTitle>{t('connectorDetail.cardCredentials')}</CardTitle>
          <CardDescription>
            {t('connectorDetail.cardCredentialsDesc')}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {fields.map((field) => (
            <div key={field.key} className="space-y-2">
              <Label htmlFor={`cred-${field.key}`}>{field.label}</Label>
              <Input
                id={`cred-${field.key}`}
                type={field.type ?? 'text'}
                placeholder={field.placeholder}
                value={credentials[field.key] ?? ''}
                onChange={(e) =>
                  setCredentials((prev) => ({ ...prev, [field.key]: e.target.value }))
                }
              />
            </div>
          ))}

          <div className="flex items-center gap-2 pt-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={handleTestConnection}
              disabled={testMutation.isPending}
            >
              {testMutation.isPending ? (
                <Loader2 className="mr-1 h-4 w-4 animate-spin" />
              ) : (
                <Wifi className="mr-1 h-4 w-4" />
              )}
              {t('connectorDetail.testConnection')}
            </Button>
            {testMutation.isSuccess && (
              <span className="text-sm text-emerald-600">{t('connectorDetail.testSuccess')}</span>
            )}
            {testMutation.isError && (
              <span className="text-destructive text-sm">{t('connectorDetail.testError')}</span>
            )}
          </div>
        </CardContent>
      </Card>

      {/* Payment Methods Section */}
      <Card>
        <CardHeader>
          <CardTitle>{t('connectorDetail.cardPaymentMethods')}</CardTitle>
          <CardDescription>
            {t('connectorDetail.cardPaymentMethodsDesc')}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="space-y-3">
            {PAYMENT_METHODS.map((pm) => {
              const isChecked = paymentMethods.includes(pm.value)
              return (
                <label key={pm.value} className="flex cursor-pointer items-center gap-3">
                  <Checkbox
                    checked={isChecked}
                    onCheckedChange={() => togglePaymentMethod(pm.value)}
                  />
                  <span className="text-sm">{pm.label}</span>
                </label>
              )
            })}
          </div>
        </CardContent>
      </Card>

      {/* Status Section */}
      <Card>
        <CardHeader>
          <CardTitle>{t('connectorDetail.cardStatus')}</CardTitle>
          <CardDescription>{t('connectorDetail.cardStatusDesc')}</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex items-center justify-between">
            <div>
              <Label>{t('connectorDetail.statusLabel')}</Label>
              <p className="text-muted-foreground text-xs">
                {t('connectorDetail.statusDesc')}
              </p>
            </div>
            <Switch
              checked={!disabled}
              onCheckedChange={(checked) => setDisabled(!checked)}
            />
          </div>
        </CardContent>
      </Card>

      <Separator />

      {/* Actions */}
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onNavigateBack}>
          {t('common.cancel')}
        </Button>
        <Button type="button" onClick={handleSave} disabled={updateMutation.isPending}>
          {updateMutation.isPending ? (
            <>
              <Loader2 className="mr-1 h-4 w-4 animate-spin" />
              {t('common.saving')}
            </>
          ) : (
            <>
              <Save className="mr-1 h-4 w-4" />
              {t('common.save')}
            </>
          )}
        </Button>
      </div>
    </div>
  )
}

// ─── Page Component ──────────────────────────────────────────

export function ConnectorDetailPage() {
  const { connectorKey } = useParams({ strict: false }) as { connectorKey: string }
  const navigate = useNavigate()

  const query = useConnector(connectorKey)
  const connector = query.data

  if (query.isLoading) return <DetailSkeleton />

  if (query.isError) {
    return <ErrorState status={500} onRetry={() => void query.refetch()} />
  }

  if (!connector) {
    return <ErrorState status={404} />
  }

  return (
    <ConnectorDetailForm
      connector={connector}
      connectorKey={connectorKey}
      onNavigateBack={() => void navigate({ to: '/connectors' })}
    />
  )
}
