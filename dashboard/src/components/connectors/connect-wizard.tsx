import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import {
  CreditCard,
  TestTube,
  Cloud,
  Check,
  Loader2,
  ArrowLeft,
  ArrowRight,
  Copy,
  CheckCheck,
} from 'lucide-react'

import type { ConnectorName } from '@/api/types'
import { useCreateConnector } from '@/hooks/use-connectors'
import { useProfiles } from '@/hooks/use-context-data'
import { useContextStore } from '@/stores/context'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Card, CardContent } from '@/components/ui/card'
import { Separator } from '@/components/ui/separator'
import { cn } from '@/lib/utils'

// ─── Connector Type Definitions ─────────────────────────────

interface ConnectorTypeOption {
  name: ConnectorName
  label: string
  descriptionKey: string
  icon: typeof CreditCard
}

const CONNECTOR_TYPES: ConnectorTypeOption[] = [
  {
    name: 'stripe',
    label: 'Stripe',
    descriptionKey: 'connectWizard.stripeDesc',
    icon: CreditCard,
  },
  {
    name: 'cloudpayments',
    label: 'CloudPayments',
    descriptionKey: 'connectWizard.cloudpaymentsDesc',
    icon: Cloud,
  },
  {
    name: 'test',
    label: 'Test',
    descriptionKey: 'connectWizard.testDesc',
    icon: TestTube,
  },
]

// ─── Credential Fields per Connector ────────────────────────

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

// ─── Payment Method Options ─────────────────────────────────

const PAYMENT_METHOD_KEYS: { value: string; labelKey: string }[] = [
  { value: 'card', labelKey: 'connectorDetail.paymentMethodCard' },
  { value: 'apple_pay', labelKey: 'connectorDetail.paymentMethodApplePay' },
  { value: 'google_pay', labelKey: 'connectorDetail.paymentMethodGooglePay' },
  { value: 'sbp', labelKey: 'connectorDetail.paymentMethodSbp' },
  { value: 'bank_transfer', labelKey: 'connectorDetail.paymentMethodBankTransfer' },
]

// ─── Step Indicator ─────────────────────────────────────────

function StepIndicator({
  currentStep,
  totalSteps,
}: {
  currentStep: number
  totalSteps: number
}) {
  return (
    <div className="flex items-center justify-center gap-2">
      {Array.from({ length: totalSteps }, (_, i) => {
        const step = i + 1
        const isActive = step === currentStep
        const isComplete = step < currentStep

        return (
          <div key={step} className="flex items-center gap-2">
            <div
              className={cn(
                'flex h-8 w-8 items-center justify-center rounded-full text-sm font-medium transition-colors',
                isActive && 'bg-primary text-primary-foreground',
                isComplete && 'bg-primary/20 text-primary',
                !isActive && !isComplete && 'bg-muted text-muted-foreground',
              )}
            >
              {isComplete ? <Check className="h-4 w-4" /> : step}
            </div>
            {step < totalSteps && (
              <div
                className={cn(
                  'h-0.5 w-8 transition-colors',
                  step < currentStep ? 'bg-primary' : 'bg-muted',
                )}
              />
            )}
          </div>
        )
      })}
    </div>
  )
}

// ─── Step 1: Select Connector Type ──────────────────────────

function Step1SelectType({
  selected,
  onSelect,
}: {
  selected: ConnectorName | null
  onSelect: (name: ConnectorName) => void
}) {
  const { t } = useTranslation()

  return (
    <div className="space-y-4">
      <p className="text-muted-foreground text-sm">
        {t('connectWizard.credentialsIntro')}
      </p>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        {CONNECTOR_TYPES.map((ct) => {
          const Icon = ct.icon
          const isSelected = selected === ct.name

          return (
            <Card
              key={ct.name}
              className={cn(
                'cursor-pointer transition-colors',
                isSelected && 'border-primary ring-primary/20 ring-2',
              )}
              onClick={() => onSelect(ct.name)}
              role="button"
              tabIndex={0}
              onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                  e.preventDefault()
                  onSelect(ct.name)
                }
              }}
            >
              <CardContent className="flex flex-col items-center gap-2 p-4 text-center">
                <Icon
                  className={cn(
                    'h-8 w-8',
                    isSelected ? 'text-primary' : 'text-muted-foreground',
                  )}
                />
                <span className="font-medium">{ct.label}</span>
                <span className="text-muted-foreground text-xs">
                  {t(ct.descriptionKey)}
                </span>
              </CardContent>
            </Card>
          )
        })}
      </div>
    </div>
  )
}

// ─── Step 2: Credentials Form ───────────────────────────────

function Step2Credentials({
  connectorName,
  credentials,
  onChange,
  errors,
}: {
  connectorName: ConnectorName
  credentials: Record<string, string>
  onChange: (key: string, value: string) => void
  errors: Record<string, string>
}) {
  const { t } = useTranslation()
  const fields = CREDENTIAL_FIELDS[connectorName] ?? []

  return (
    <div className="space-y-4">
      <p className="text-muted-foreground text-sm">
        {t('connectWizard.credentialsIntro')}
      </p>
      {fields.map((field) => (
        <div key={field.key} className="space-y-2">
          <Label htmlFor={`cred-${field.key}`}>{field.label}</Label>
          <Input
            id={`cred-${field.key}`}
            type={field.type ?? 'text'}
            placeholder={field.placeholder}
            value={credentials[field.key] ?? ''}
            onChange={(e) => onChange(field.key, e.target.value)}
          />
          {errors[field.key] && (
            <p className="text-destructive text-sm">{errors[field.key]}</p>
          )}
        </div>
      ))}
    </div>
  )
}

// ─── Step 3: Payment Methods + Test Mode ────────────────────

function Step3PaymentMethods({
  selectedMethods,
  onToggleMethod,
  testMode,
  onTestModeChange,
}: {
  selectedMethods: string[]
  onToggleMethod: (method: string) => void
  testMode: boolean
  onTestModeChange: (value: boolean) => void
}) {
  const { t } = useTranslation()

  return (
    <div className="space-y-6">
      <div className="space-y-4">
        <p className="text-muted-foreground text-sm">
          {t('connectWizard.paymentMethodsIntro')}
        </p>
        <div className="space-y-3">
          {PAYMENT_METHOD_KEYS.map((pm) => {
            const isChecked = selectedMethods.includes(pm.value)
            return (
              <label key={pm.value} className="flex cursor-pointer items-center gap-3">
                <Checkbox
                  checked={isChecked}
                  onCheckedChange={() => onToggleMethod(pm.value)}
                />
                <span className="text-sm">{t(pm.labelKey)}</span>
              </label>
            )
          })}
        </div>
      </div>

      <Separator />

      <div className="flex items-center justify-between">
        <div>
          <Label>{t('connectWizard.testModeLabel')}</Label>
          <p className="text-muted-foreground text-xs">
            {t('connectWizard.testModeHint')}
          </p>
        </div>
        <Switch checked={testMode} onCheckedChange={onTestModeChange} />
      </div>
    </div>
  )
}

// ─── Step 4: Profile Binding ────────────────────────────────

function Step4Profile({
  profileId,
  onProfileChange,
}: {
  profileId: string
  onProfileChange: (value: string) => void
}) {
  const { t } = useTranslation()
  const merchantKey = useContextStore((s) => s.currentMerchantKey)
  const { data: profiles = [] } = useProfiles(merchantKey)

  return (
    <div className="space-y-4">
      <p className="text-muted-foreground text-sm">{t('connectWizard.profileIntro')}</p>
      <div className="space-y-2">
        <Label>{t('connectWizard.profileLabel')}</Label>
        <Select value={profileId} onValueChange={onProfileChange}>
          <SelectTrigger className="w-full">
            <SelectValue placeholder={t('connectWizard.profileNotSelected')} />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="default">{t('connectWizard.profileDefault')}</SelectItem>
            {profiles.map((p) => (
              <SelectItem key={p.key} value={p.key}>
                {p.name || p.key}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <p className="text-muted-foreground text-xs">{t('connectWizard.profileHint')}</p>
      </div>
    </div>
  )
}

// ─── Step 5: Success ─────────────────────────────────────────

function Step5Success({
  webhookUrl,
  onGoToConnector,
}: {
  webhookUrl: string
  onGoToConnector: () => void
}) {
  const { t } = useTranslation()
  const [copied, setCopied] = useState(false)

  return (
    <div className="space-y-4 text-center">
      <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-950">
        <Check className="h-6 w-6 text-emerald-600" />
      </div>
      <div>
        <h3 className="font-semibold">{t('connectWizard.successTitle')}</h3>
        <p className="text-muted-foreground text-sm">{t('connectWizard.successDesc')}</p>
      </div>
      <div className="space-y-2 text-left">
        <Label>{t('connectWizard.webhookUrlLabel')}</Label>
        <div className="flex items-center gap-2">
          <Input readOnly value={webhookUrl} className="font-mono text-xs" />
          <Button
            type="button"
            variant="outline"
            size="icon"
            onClick={() => {
              void navigator.clipboard.writeText(webhookUrl)
              setCopied(true)
              setTimeout(() => setCopied(false), 2000)
            }}
          >
            {copied ? (
              <CheckCheck className="h-4 w-4 text-emerald-600" />
            ) : (
              <Copy className="h-4 w-4" />
            )}
          </Button>
        </div>
      </div>
      <Button type="button" className="w-full" onClick={onGoToConnector}>
        {t('connectWizard.goToConnector')}
      </Button>
    </div>
  )
}

// ─── Wizard Component ───────────────────────────────────────

const TOTAL_STEPS = 5

const STEP_TITLE_KEYS: Record<number, string> = {
  1: 'connectWizard.step1',
  2: 'connectWizard.step2',
  3: 'connectWizard.step3',
  4: 'connectWizard.step4',
  5: 'connectWizard.step5',
}

interface ConnectWizardProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess?: () => void
  onNavigateToConnector?: (connectorKey: string) => void
}

export function ConnectWizard({
  open,
  onOpenChange,
  onSuccess,
  onNavigateToConnector,
}: ConnectWizardProps) {
  const { t } = useTranslation()
  const [step, setStep] = useState(1)
  const createMutation = useCreateConnector()

  // Step 1
  const [connectorName, setConnectorName] = useState<ConnectorName | null>(null)

  // Step 2
  const [credentials, setCredentials] = useState<Record<string, string>>({})
  const [credErrors, setCredErrors] = useState<Record<string, string>>({})

  // Step 3
  const [paymentMethods, setPaymentMethods] = useState<string[]>(['card'])
  const [testMode, setTestMode] = useState(true)

  // Step 4
  const [profileId, setProfileId] = useState('')

  // Step 5
  const [createdConnector, setCreatedConnector] = useState<{
    id: string
    webhook_url: string
  } | null>(null)

  function reset() {
    setStep(1)
    setConnectorName(null)
    setCredentials({})
    setCredErrors({})
    setPaymentMethods(['card'])
    setTestMode(true)
    setProfileId('')
    setCreatedConnector(null)
  }

  function handleClose(value: boolean) {
    if (!value) reset()
    onOpenChange(value)
  }

  function validateStep2(): boolean {
    if (!connectorName) return false
    const fields = CREDENTIAL_FIELDS[connectorName] ?? []
    const errors: Record<string, string> = {}

    for (const field of fields) {
      if (!credentials[field.key]?.trim()) {
        errors[field.key] = t('common.requiredField')
      }
    }

    setCredErrors(errors)
    return Object.keys(errors).length === 0
  }

  function handleNext() {
    if (step === 1 && !connectorName) return
    if (step === 2 && !validateStep2()) return
    if (step === 3 && paymentMethods.length === 0) return

    if (step < TOTAL_STEPS - 1) {
      setStep(step + 1)
    }
  }

  function handleBack() {
    if (step > 1) {
      setStep(step - 1)
    }
  }

  function handleFinish() {
    if (!connectorName) return

    createMutation.mutate(
      {
        connector_name: connectorName,
        connector_type: 'fiz_operations',
        connector_account_details: credentials,
        payment_methods_enabled: paymentMethods,
        test_mode: testMode,
        ...(profileId && profileId !== 'default' ? { profile_id: profileId } : {}),
      },
      {
        onSuccess: (data) => {
          setCreatedConnector({ id: data.id, webhook_url: data.webhook_url })
          setStep(5)
          onSuccess?.()
        },
      },
    )
  }

  function togglePaymentMethod(method: string) {
    setPaymentMethods((prev) =>
      prev.includes(method) ? prev.filter((m) => m !== method) : [...prev, method],
    )
  }

  const canProceed =
    (step === 1 && connectorName !== null) ||
    step === 2 ||
    (step === 3 && paymentMethods.length > 0) ||
    step === 4

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{t('connectWizard.title')}</DialogTitle>
          <DialogDescription>{t(STEP_TITLE_KEYS[step]!)}</DialogDescription>
        </DialogHeader>

        <StepIndicator currentStep={step} totalSteps={TOTAL_STEPS} />

        <div className="min-h-[200px] py-2">
          {step === 1 && (
            <Step1SelectType selected={connectorName} onSelect={setConnectorName} />
          )}
          {step === 2 && connectorName && (
            <Step2Credentials
              connectorName={connectorName}
              credentials={credentials}
              onChange={(key, value) =>
                setCredentials((prev) => ({ ...prev, [key]: value }))
              }
              errors={credErrors}
            />
          )}
          {step === 3 && (
            <Step3PaymentMethods
              selectedMethods={paymentMethods}
              onToggleMethod={togglePaymentMethod}
              testMode={testMode}
              onTestModeChange={setTestMode}
            />
          )}
          {step === 4 && (
            <Step4Profile profileId={profileId} onProfileChange={setProfileId} />
          )}
          {step === 5 && createdConnector && (
            <Step5Success
              webhookUrl={createdConnector.webhook_url}
              onGoToConnector={() => {
                handleClose(false)
                onNavigateToConnector?.(createdConnector.id)
              }}
            />
          )}
        </div>

        {step < 5 && (
          <DialogFooter className="flex justify-between gap-2 sm:justify-between">
            <div>
              {step > 1 && (
                <Button type="button" variant="outline" onClick={handleBack}>
                  <ArrowLeft className="mr-1 h-4 w-4" />
                  {t('common.back')}
                </Button>
              )}
            </div>
            <div className="flex gap-2">
              <Button type="button" variant="ghost" onClick={() => handleClose(false)}>
                {t('common.cancel')}
              </Button>
              {step < TOTAL_STEPS - 1 ? (
                <Button type="button" onClick={handleNext} disabled={!canProceed}>
                  {t('common.next')}
                  <ArrowRight className="ml-1 h-4 w-4" />
                </Button>
              ) : (
                <Button
                  type="button"
                  onClick={handleFinish}
                  disabled={createMutation.isPending}
                >
                  {createMutation.isPending ? (
                    <>
                      <Loader2 className="mr-1 h-4 w-4 animate-spin" />
                      {t('connectWizard.connecting')}
                    </>
                  ) : (
                    t('connectWizard.connectButton')
                  )}
                </Button>
              )}
            </div>
          </DialogFooter>
        )}
        {step === 5 && (
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => handleClose(false)}>
              {t('common.close')}
            </Button>
          </DialogFooter>
        )}
      </DialogContent>
    </Dialog>
  )
}
