import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from '@tanstack/react-router'
import { FlaskConical, Loader2 } from 'lucide-react'

import type { PaymentIntentAttributes } from '@/api/types'
import { useCreateTestPayment } from '@/hooks/use-test-payment'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { StatusBadge } from '@/components/shared/status-badge'
import { MoneyFormat } from '@/components/shared/money-format'

// ─── Card Presets ────────────────────────────────────────────

interface CardPreset {
  labelKey: string
  number: string
}

const CARD_PRESETS: CardPreset[] = [
  { labelKey: 'testPayment.presetSuccessful', number: '4242424242424242' },
  { labelKey: 'testPayment.presetDecline', number: '4000000000000002' },
  { labelKey: 'testPayment.preset3ds', number: '4000000000003220' },
]

// ─── Test Payment Form (reusable) ────────────────────────────

type TestPaymentResult = PaymentIntentAttributes & { id: string }

interface TestPaymentFormProps {
  onSuccess?: (result: TestPaymentResult) => void
}

export function TestPaymentForm({ onSuccess }: TestPaymentFormProps) {
  const { t } = useTranslation()
  const mutation = useCreateTestPayment()

  const [amount, setAmount] = useState('10000')
  const [currency, setCurrency] = useState('RUB')
  const [paymentMethod, setPaymentMethod] = useState('card')
  const [cardNumber, setCardNumber] = useState('4242424242424242')
  const [cardExpMonth, setCardExpMonth] = useState('12')
  const [cardExpYear, setCardExpYear] = useState('30')
  const [cardCvc, setCardCvc] = useState('123')
  const [captureMethod, setCaptureMethod] = useState('automatic')
  const [result, setResult] = useState<TestPaymentResult | null>(null)

  function applyPreset(preset: CardPreset) {
    setCardNumber(preset.number)
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    mutation.mutate(
      {
        amount: Number(amount),
        currency,
        payment_method: paymentMethod,
        card_number: cardNumber,
        card_exp_month: cardExpMonth,
        card_exp_year: cardExpYear,
        card_cvc: cardCvc,
        capture_method: captureMethod,
      },
      {
        onSuccess: (data) => {
          setResult(data)
          onSuccess?.(data)
        },
      },
    )
  }

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle>{t('testPayment.title')}</CardTitle>
          <CardDescription>
            {t('testPayment.formDesc')}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="flex flex-wrap gap-2" data-testid="presets">
              {CARD_PRESETS.map((preset) => (
                <Button
                  key={preset.number}
                  type="button"
                  variant={cardNumber === preset.number ? 'default' : 'outline'}
                  size="sm"
                  onClick={() => applyPreset(preset)}
                >
                  {t(preset.labelKey)}
                </Button>
              ))}
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="amount">{t('testPayment.labelAmount')}</Label>
                <Input
                  id="amount"
                  type="number"
                  value={amount}
                  onChange={(e) => setAmount(e.target.value)}
                  min={1}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="currency">{t('testPayment.labelCurrency')}</Label>
                <Select value={currency} onValueChange={setCurrency}>
                  <SelectTrigger id="currency">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="RUB">RUB</SelectItem>
                    <SelectItem value="USD">USD</SelectItem>
                    <SelectItem value="EUR">EUR</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="payment-method">{t('testPayment.labelPaymentMethod')}</Label>
              <Select value={paymentMethod} onValueChange={setPaymentMethod}>
                <SelectTrigger id="payment-method">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="card">card</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label>{t('testPayment.labelCardData')}</Label>
              <div className="grid grid-cols-3 gap-2">
                <Input
                  placeholder={t('testPayment.cardNumberPlaceholder')}
                  value={cardNumber}
                  onChange={(e) => setCardNumber(e.target.value)}
                  className="col-span-3 font-mono"
                />
                <Input
                  placeholder="MM"
                  value={cardExpMonth}
                  onChange={(e) => setCardExpMonth(e.target.value)}
                  maxLength={2}
                />
                <Input
                  placeholder="YY"
                  value={cardExpYear}
                  onChange={(e) => setCardExpYear(e.target.value)}
                  maxLength={2}
                />
                <Input
                  placeholder="CVC"
                  value={cardCvc}
                  onChange={(e) => setCardCvc(e.target.value)}
                  maxLength={4}
                  type="password"
                />
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="capture-method">{t('testPayment.labelCaptureMethod')}</Label>
              <Select value={captureMethod} onValueChange={setCaptureMethod}>
                <SelectTrigger id="capture-method">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="automatic">automatic</SelectItem>
                  <SelectItem value="manual">manual</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <Button type="submit" disabled={mutation.isPending} className="w-full">
              {mutation.isPending ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  {t('testPayment.sending')}
                </>
              ) : (
                t('testPayment.sendButton')
              )}
            </Button>

            {mutation.isError && (
              <p className="text-destructive text-sm">
                {t('testPayment.errorPrefix')} {mutation.error?.message ?? t('testPayment.errorCreatePayment')}
              </p>
            )}
          </form>
        </CardContent>
      </Card>

      {result && (
        <Card data-testid="result-card">
          <CardHeader>
            <CardTitle>{t('testPayment.resultTitle')}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="flex items-center justify-between">
              <span className="text-muted-foreground text-sm">{t('testPayment.resultStatus')}</span>
              <StatusBadge status={result.status} />
            </div>
            <div className="flex items-center justify-between">
              <span className="text-muted-foreground text-sm">{t('testPayment.resultId')}</span>
              <Link
                to="/payments/$paymentKey"
                params={{ paymentKey: result.id }}
                className="text-primary font-mono text-sm hover:underline"
              >
                {result.id}
              </Link>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-muted-foreground text-sm">{t('testPayment.resultAmount')}</span>
              <MoneyFormat amount={result.amount} currency={result.currency} />
            </div>
            {result.connector && (
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground text-sm">{t('testPayment.resultConnector')}</span>
                <span className="text-sm">{result.connector}</span>
              </div>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  )
}

// ─── Page Component ──────────────────────────────────────────

export function TestPaymentPage() {
  const { t } = useTranslation()
  return (
    <div className="mx-auto max-w-lg space-y-6">
      <div className="flex items-center gap-3">
        <FlaskConical className="text-muted-foreground h-7 w-7" />
        <h1 className="text-3xl font-bold">{t('testPayment.title')}</h1>
      </div>
      <TestPaymentForm />
    </div>
  )
}
