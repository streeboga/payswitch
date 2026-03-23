import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Loader2, Play, RotateCcw } from 'lucide-react'

import { useMerchantDetail } from '@/hooks/use-merchants'
import { useContextStore } from '@/stores/context'
import { Button } from '@/components/ui/button'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { api } from '@/api/client'
import type { PaymentIntentAttributes } from '@/api/types'
import { extractAttributes } from '@/api/types'

// ─── Widget Mount ────────────────────────────────────────────

function WidgetMount({
  clientSecret,
  publishableKey,
  locale,
}: {
  clientSecret: string
  publishableKey: string
  locale: string
}) {
  const containerRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!clientSecret || !containerRef.current) return

    let destroyed = false
    let widget: { destroy(): void } | undefined

    const init = async () => {
      const { loadPayswitch } = await import('@payswitch/js')
      if (destroyed) return
      const ps = await loadPayswitch(publishableKey, { customBackendUrl: '' })
      const widgets = ps.widgets({ clientSecret, locale })
      const w = widgets.create('payment')
      widget = w
      w.mount(containerRef.current!)
    }
    init().catch(console.error)

    return () => {
      destroyed = true
      widget?.destroy()
    }
  }, [clientSecret, publishableKey, locale])

  return <div ref={containerRef} />
}

// ─── Payment Widget Preview ──────────────────────────────────

interface PaymentWidgetPreviewProps {
  connectorName?: string
  title?: string
  description?: string
}

export function PaymentWidgetPreview({
  connectorName,
  title,
  description,
}: PaymentWidgetPreviewProps) {
  const { t, i18n } = useTranslation()
  const merchantKey = useContextStore((s) => s.currentMerchantKey)
  const { data: merchant } = useMerchantDetail(merchantKey ?? '')
  const [clientSecret, setClientSecret] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const publishableKey = merchant?.publishable_key ?? ''

  const handleCreatePayment = async () => {
    setCreating(true)
    setError(null)
    try {
      const doc = await api
        .post('dashboard/test-payments/create-only', {
          json: {
            amount: 10000,
            currency: 'RUB',
            ...(connectorName ? { connector_name: connectorName } : {}),
          },
        })
        .json<{
          data: { type: string; id: string; attributes: PaymentIntentAttributes }
        }>()
      const payment = extractAttributes(doc.data)
      setClientSecret(payment.client_secret ?? null)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to create payment')
    } finally {
      setCreating(false)
    }
  }

  const handleReset = () => {
    setClientSecret(null)
    setError(null)
  }

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center justify-between">
          <div>
            <CardTitle>
              {title ?? t('widget.preview', 'Payment Widget Preview')}
            </CardTitle>
            {description && <CardDescription>{description}</CardDescription>}
          </div>
          {clientSecret && (
            <Button onClick={handleReset} variant="ghost" size="sm">
              <RotateCcw className="mr-1 h-4 w-4" />
              {t('widget.reset', 'Reset')}
            </Button>
          )}
        </div>
      </CardHeader>
      <CardContent>
        {!clientSecret ? (
          <div className="flex flex-col items-center gap-3 py-4">
            <p className="text-muted-foreground text-sm">
              {t(
                'widget.createTestPayment',
                'Create a test payment to preview the full checkout widget',
              )}
            </p>
            <Button
              onClick={handleCreatePayment}
              disabled={creating || !publishableKey}
              variant="outline"
            >
              {creating ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <Play className="mr-2 h-4 w-4" />
              )}
              {t('widget.launchPreview', 'Launch Preview')}
            </Button>
            {error && <p className="text-destructive text-sm">{error}</p>}
          </div>
        ) : (
          <WidgetMount
            clientSecret={clientSecret}
            publishableKey={publishableKey}
            locale={i18n.language}
          />
        )}
      </CardContent>
    </Card>
  )
}
