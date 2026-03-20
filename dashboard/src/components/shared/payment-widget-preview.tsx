import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Loader2, Play } from 'lucide-react'

import { useCreateTestPayment } from '@/hooks/use-test-payment'
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

// ─── Widget Mount ────────────────────────────────────────────

function WidgetMount({
  clientSecret,
  publishableKey,
}: {
  clientSecret: string
  publishableKey: string
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
      const widgets = ps.widgets({ clientSecret })
      const w = widgets.create('payment')
      widget = w
      w.mount(containerRef.current!)
    }
    init().catch(console.error)

    return () => {
      destroyed = true
      widget?.destroy()
    }
  }, [clientSecret, publishableKey])

  return <div ref={containerRef} />
}

// ─── Payment Widget Preview ──────────────────────────────────

interface PaymentWidgetPreviewProps {
  /** Force a specific connector (for connector detail page) */
  connectorName?: string
  /** Title override */
  title?: string
  /** Description override */
  description?: string
}

export function PaymentWidgetPreview({
  connectorName,
  title,
  description,
}: PaymentWidgetPreviewProps) {
  const { t } = useTranslation()
  const merchantKey = useContextStore((s) => s.currentMerchantKey)
  const { data: merchant } = useMerchantDetail(merchantKey ?? '')
  const mutation = useCreateTestPayment()
  const [clientSecret, setClientSecret] = useState<string | null>(null)

  const publishableKey = merchant?.publishable_key ?? ''

  const handleCreatePayment = () => {
    mutation.mutate(
      {
        amount: 10000,
        currency: 'RUB',
        payment_method: 'card',
        card_number: '4242424242424242',
        card_exp_month: '12',
        card_exp_year: '30',
        card_cvc: '123',
        capture_method: 'automatic',
        ...(connectorName ? { connector_name: connectorName } : {}),
      },
      {
        onSuccess: (data) => {
          setClientSecret(data.client_secret ?? null)
        },
      },
    )
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          {title ?? t('widget.preview', 'Payment Widget Preview')}
        </CardTitle>
        {description && <CardDescription>{description}</CardDescription>}
      </CardHeader>
      <CardContent>
        {!clientSecret ? (
          <div className="flex flex-col items-center gap-3 py-4">
            <p className="text-muted-foreground text-sm">
              {t(
                'widget.createTestPayment',
                'Create a test payment to preview the widget',
              )}
            </p>
            <Button
              onClick={handleCreatePayment}
              disabled={mutation.isPending || !publishableKey}
              variant="outline"
            >
              {mutation.isPending ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <Play className="mr-2 h-4 w-4" />
              )}
              {t('widget.launchPreview', 'Launch Preview')}
            </Button>
            {mutation.isError && (
              <p className="text-destructive text-sm">
                {mutation.error?.message ?? 'Error creating payment'}
              </p>
            )}
          </div>
        ) : (
          <WidgetMount
            clientSecret={clientSecret}
            publishableKey={publishableKey}
          />
        )}
      </CardContent>
    </Card>
  )
}
