import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from '@tanstack/react-router'
import { Code, Key, Webhook, Play, Shield, Zap, ExternalLink } from 'lucide-react'
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
} from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { CopyButton } from '@/components/shared/copy-button'
import { PaymentWidgetPreview } from '@/components/shared/payment-widget-preview'
import { useApiKeysList } from '@/hooks/use-api-keys'
import { useProfilesList } from '@/hooks/use-profiles'

// ─── Code Block ──────────────────────────────────────────────

function CodeBlock({ code }: { code: string }) {
  return (
    <div className="bg-muted relative rounded-lg p-4">
      <CopyButton value={code} className="absolute top-2 right-2" />
      <pre className="overflow-x-auto text-sm">
        <code className="font-mono">{code}</code>
      </pre>
    </div>
  )
}

// ─── Integration Page ────────────────────────────────────────

export function IntegrationPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { data: apiKeysData } = useApiKeysList()
  const { data: profilesData } = useProfilesList()

  const publishableKey = useMemo(() => {
    if (!apiKeysData?.items) return null
    return apiKeysData.items.find((k) => k.type === 'publishable' && !k.revoked_at)
  }, [apiKeysData])

  const secretKey = useMemo(() => {
    if (!apiKeysData?.items) return null
    return apiKeysData.items.find((k) => k.type === 'secret' && !k.revoked_at)
  }, [apiKeysData])

  const firstProfile = useMemo(() => {
    if (!profilesData?.items) return null
    return profilesData.items[0] ?? null
  }, [profilesData])

  const pkDisplay = publishableKey?.key_prefix ?? 'pk_xxx'
  const skDisplay = secretKey?.key_prefix ?? 'sk_xxx'

  const createPaymentCode = `POST /api/v1/payments
Authorization: Bearer ${skDisplay}
Content-Type: application/vnd.api+json

{
  "amount": 10000,
  "currency": "RUB",
  "description": "Заказ #123",
  "return_url": "https://example.com/success"
}`

  const createPaymentResponse = `{
  "data": {
    "type": "payment_intent",
    "id": "pi_abc123",
    "attributes": {
      "amount": 10000,
      "currency": "RUB",
      "status": "requires_payment_method",
      "client_secret": "pi_abc123_secret_xyz"
    }
  }
}`

  const widgetCode = `import { loadPayswitch } from '@payswitch/js';

const ps = await loadPayswitch('${pkDisplay}', {
  customBackendUrl: 'https://psapi.gnzs.pro'
});

const widgets = ps.widgets({
  clientSecret: 'CLIENT_SECRET_FROM_STEP_1',
  locale: 'ru'
});

widgets.create('payment').mount('#payment-widget');`

  const webhookPayload = `{
  "event": "payment.succeeded",
  "payment_id": "pi_abc123",
  "amount": 10000,
  "currency": "RUB",
  "status": "succeeded",
  "metadata": {}
}`

  const signatureCode = `signature = HMAC-SHA512(request_body, signing_key)
valid = signature == request.headers['x-webhook-signature-512']`

  const eventTypes = [
    { event: 'payment.succeeded', label: t('integration.eventPaymentSucceeded') },
    { event: 'payment.failed', label: t('integration.eventPaymentFailed') },
    { event: 'payment.cancelled', label: t('integration.eventPaymentCancelled') },
    { event: 'payment.authorized', label: t('integration.eventPaymentAuthorized') },
    { event: 'payment.captured', label: t('integration.eventPaymentCaptured') },
    { event: 'refund.succeeded', label: t('integration.eventRefundSucceeded') },
    { event: 'refund.failed', label: t('integration.eventRefundFailed') },
  ]

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="space-y-1">
        <h1 className="text-3xl font-bold">{t('integration.title')}</h1>
        <p className="text-muted-foreground">{t('integration.subtitle')}</p>
      </div>

      {/* Section 1: Keys */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Key className="h-5 w-5" />
            {t('integration.keysTitle')}
          </CardTitle>
          <CardDescription>{t('integration.keysDesc')}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {publishableKey || secretKey ? (
            <>
              {publishableKey && (
                <div className="space-y-1">
                  <p className="text-sm font-medium">{t('integration.publishableKey')}</p>
                  <div className="flex items-center gap-2">
                    <code className="bg-muted rounded px-2 py-1 text-sm">
                      {publishableKey.key_prefix}...
                    </code>
                    <CopyButton value={publishableKey.key_prefix} />
                  </div>
                  <p className="text-muted-foreground text-xs">
                    {t('integration.publishableKeyHint')}
                  </p>
                </div>
              )}
              {secretKey && (
                <div className="space-y-1">
                  <p className="text-sm font-medium">{t('integration.secretKey')}</p>
                  <div className="flex items-center gap-2">
                    <code className="bg-muted rounded px-2 py-1 text-sm">
                      {secretKey.key_prefix}...
                    </code>
                    <CopyButton value={secretKey.key_prefix} />
                  </div>
                  <p className="text-muted-foreground text-xs">
                    {t('integration.secretKeyHint')}
                  </p>
                </div>
              )}
            </>
          ) : (
            <p className="text-muted-foreground text-sm">
              {t('integration.noKeys')}{' '}
              <Button
                variant="link"
                className="h-auto p-0"
                onClick={() => navigate({ to: '/api-keys' })}
              >
                {t('integration.goToApiKeys')}
                <ExternalLink className="ml-1 h-3 w-3" />
              </Button>
            </p>
          )}
        </CardContent>
      </Card>

      {/* Section 2: Webhook */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Webhook className="h-5 w-5" />
            {t('integration.webhookTitle')}
          </CardTitle>
          <CardDescription>{t('integration.webhookDesc')}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {firstProfile?.webhook_url ? (
            <>
              <div className="space-y-1">
                <p className="text-sm font-medium">{t('integration.webhookUrl')}</p>
                <div className="flex items-center gap-2">
                  <code className="bg-muted rounded px-2 py-1 text-sm">
                    {firstProfile.webhook_url}
                  </code>
                  <CopyButton value={firstProfile.webhook_url} />
                </div>
              </div>
              {firstProfile.payment_response_hash_key && (
                <div className="space-y-1">
                  <p className="text-sm font-medium">{t('integration.signingKey')}</p>
                  <div className="flex items-center gap-2">
                    <code className="bg-muted rounded px-2 py-1 text-sm">
                      {firstProfile.payment_response_hash_key.slice(0, 8)}...
                    </code>
                    <CopyButton value={firstProfile.payment_response_hash_key} />
                  </div>
                  <p className="text-muted-foreground text-xs">
                    {t('integration.signingKeyHint')}
                  </p>
                </div>
              )}
            </>
          ) : (
            <p className="text-muted-foreground text-sm">
              {t('integration.noWebhookUrl')}{' '}
              <Button
                variant="link"
                className="h-auto p-0"
                onClick={() => navigate({ to: '/profiles' })}
              >
                {t('integration.goToProfile')}
                <ExternalLink className="ml-1 h-3 w-3" />
              </Button>
            </p>
          )}
        </CardContent>
      </Card>

      {/* Section 3: Create Payment */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Code className="h-5 w-5" />
            {t('integration.createPaymentTitle')}
          </CardTitle>
          <CardDescription>{t('integration.createPaymentDesc')}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <CodeBlock code={createPaymentCode} />
          <CodeBlock code={createPaymentResponse} />
          <Button variant="outline" onClick={() => navigate({ to: '/test-payment' })}>
            <Play className="mr-2 h-4 w-4" />
            {t('integration.tryIt')}
          </Button>
        </CardContent>
      </Card>

      {/* Section 4: Widget */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Zap className="h-5 w-5" />
            {t('integration.widgetTitle')}
          </CardTitle>
          <CardDescription>{t('integration.widgetDesc')}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          <CodeBlock code={widgetCode} />
          <div>
            <p className="mb-3 text-sm font-medium">
              {t('integration.widgetPreviewTitle')}
            </p>
            <PaymentWidgetPreview />
          </div>
        </CardContent>
      </Card>

      {/* Section 5: Webhooks */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Shield className="h-5 w-5" />
            {t('integration.webhooksTitle')}
          </CardTitle>
          <CardDescription>{t('integration.webhooksDesc')}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          <div>
            <p className="mb-2 text-sm font-medium">{t('integration.payloadExample')}</p>
            <CodeBlock code={webhookPayload} />
          </div>

          <div>
            <p className="mb-2 text-sm font-medium">
              {t('integration.signatureVerification')}
            </p>
            <CodeBlock code={signatureCode} />
          </div>

          <div>
            <p className="mb-2 text-sm font-medium">{t('integration.eventTypes')}</p>
            <div className="flex flex-wrap gap-2">
              {eventTypes.map((e) => (
                <Badge key={e.event} variant="secondary">
                  {e.event}
                  <span className="text-muted-foreground ml-1.5">— {e.label}</span>
                </Badge>
              ))}
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
