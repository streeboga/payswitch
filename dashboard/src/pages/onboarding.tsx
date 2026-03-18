import { useState, useCallback, useMemo, lazy, Suspense } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from '@tanstack/react-router'
import {
  Building2,
  Store,
  Plug,
  GitBranch,
  FlaskConical,
  Check,
  ArrowLeft,
  ArrowRight,
  Loader2,
} from 'lucide-react'

const ConnectWizard = lazy(() =>
  import('@/components/connectors/connect-wizard').then((m) => ({ default: m.ConnectWizard })),
)
const TestPaymentForm = lazy(() =>
  import('@/pages/test-payment').then((m) => ({ default: m.TestPaymentForm })),
)
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
import { cn } from '@/lib/utils'

// ─── Step Definitions ────────────────────────────────────────

interface StepDef {
  labelKey: string
  icon: typeof Building2
}

const STEPS: StepDef[] = [
  { labelKey: 'onboarding.stepOrganization', icon: Building2 },
  { labelKey: 'onboarding.stepMerchant', icon: Store },
  { labelKey: 'onboarding.stepConnector', icon: Plug },
  { labelKey: 'onboarding.stepRouting', icon: GitBranch },
  { labelKey: 'onboarding.stepTestPayment', icon: FlaskConical },
]

const TOTAL_STEPS = STEPS.length

// ─── Progress Bar ────────────────────────────────────────────

function ProgressBar({ currentStep }: { currentStep: number }) {
  const { t } = useTranslation()

  return (
    <div className="space-y-3">
      <div className="flex justify-between">
        {STEPS.map((step, i) => {
          const stepNum = i + 1
          const isActive = stepNum === currentStep
          const isComplete = stepNum < currentStep
          const Icon = step.icon

          return (
            <div key={step.labelKey} className="flex flex-col items-center gap-1">
              <div
                className={cn(
                  'flex h-10 w-10 items-center justify-center rounded-full text-sm font-medium transition-colors',
                  isActive && 'bg-primary text-primary-foreground',
                  isComplete && 'bg-primary/20 text-primary',
                  !isActive && !isComplete && 'bg-muted text-muted-foreground',
                )}
              >
                {isComplete ? (
                  <Check className="h-5 w-5" />
                ) : (
                  <Icon className="h-5 w-5" />
                )}
              </div>
              <span
                className={cn(
                  'text-xs',
                  isActive ? 'font-medium' : 'text-muted-foreground',
                )}
              >
                {t(step.labelKey)}
              </span>
            </div>
          )
        })}
      </div>
      <div className="bg-muted h-2 overflow-hidden rounded-full">
        <div
          className="bg-primary h-full transition-all duration-300"
          style={{ width: `${((currentStep - 1) / (TOTAL_STEPS - 1)) * 100}%` }}
        />
      </div>
    </div>
  )
}

// ─── Step 1: Create Organization ─────────────────────────────

function StepOrganization({
  name,
  onChange,
}: {
  name: string
  onChange: (name: string) => void
}) {
  const { t } = useTranslation()

  return (
    <div className="space-y-4">
      <p className="text-muted-foreground text-sm">
        {t('onboarding.step1Text')}
      </p>
      <div className="space-y-2">
        <Label htmlFor="org-name">{t('onboarding.orgNameLabel')}</Label>
        <Input
          id="org-name"
          placeholder={t('onboarding.orgNamePlaceholder')}
          value={name}
          onChange={(e) => onChange(e.target.value)}
          autoFocus
        />
      </div>
    </div>
  )
}

// ─── Step 2: Create Merchant ─────────────────────────────────

function StepMerchant({
  name,
  onChange,
}: {
  name: string
  onChange: (name: string) => void
}) {
  const { t } = useTranslation()

  return (
    <div className="space-y-4">
      <p className="text-muted-foreground text-sm">
        {t('onboarding.step2Text')}
      </p>
      <div className="space-y-2">
        <Label htmlFor="merchant-name">{t('onboarding.merchantNameLabel')}</Label>
        <Input
          id="merchant-name"
          placeholder={t('onboarding.merchantNamePlaceholder')}
          value={name}
          onChange={(e) => onChange(e.target.value)}
          autoFocus
        />
      </div>
    </div>
  )
}

// ─── Step 3: Connect Connector ───────────────────────────────

function StepConnector({ onComplete }: { onComplete: () => void }) {
  const { t } = useTranslation()
  const [wizardOpen, setWizardOpen] = useState(true)

  return (
    <div className="space-y-4">
      <p className="text-muted-foreground text-sm">
        {t('onboarding.step3Text')}
      </p>
      {!wizardOpen && (
        <Button onClick={() => setWizardOpen(true)}>{t('onboarding.connectButton')}</Button>
      )}
      <Suspense fallback={<Loader2 className="h-6 w-6 animate-spin" />}>
        <ConnectWizard
          open={wizardOpen}
          onOpenChange={setWizardOpen}
          onSuccess={onComplete}
        />
      </Suspense>
    </div>
  )
}

// ─── Step 4: Routing ─────────────────────────────────────────

function StepRouting() {
  const { t } = useTranslation()

  return (
    <div className="space-y-4">
      <p className="text-muted-foreground text-sm">
        {t('onboarding.step4Text')}
      </p>
      <div className="bg-muted/50 rounded-lg p-6 text-center">
        <GitBranch className="text-muted-foreground mx-auto mb-2 h-10 w-10" />
        <p className="text-muted-foreground text-sm">
          {t('onboarding.step4DefaultText')}
        </p>
      </div>
    </div>
  )
}

// ─── Step 5: Test Payment ────────────────────────────────────

function StepTestPayment({ onComplete }: { onComplete: () => void }) {
  const { t } = useTranslation()

  return (
    <div className="space-y-4">
      <p className="text-muted-foreground text-sm">
        {t('onboarding.step5Text')}
      </p>
      <Suspense fallback={<Loader2 className="h-6 w-6 animate-spin" />}>
        <TestPaymentForm onSuccess={onComplete} />
      </Suspense>
    </div>
  )
}

// ─── Page Component ──────────────────────────────────────────

export function OnboardingPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [step, setStep] = useState(1)
  const [isSubmitting, setIsSubmitting] = useState(false)

  // Step 1 state
  const [orgName, setOrgName] = useState('')

  // Step 2 state
  const [merchantName, setMerchantName] = useState('')

  // Step 3 state
  const [connectorDone, setConnectorDone] = useState(false)

  // Step 5 state
  const [testPaymentDone, setTestPaymentDone] = useState(false)

  const STEP_TITLES = useMemo<Record<number, { title: string; description: string }>>(
    () => ({
      1: {
        title: t('onboarding.step1Title'),
        description: t('onboarding.step1Desc'),
      },
      2: {
        title: t('onboarding.step2Title'),
        description: t('onboarding.step2Desc'),
      },
      3: {
        title: t('onboarding.step3Title'),
        description: t('onboarding.step3Desc'),
      },
      4: {
        title: t('onboarding.step4Title'),
        description: t('onboarding.step4Desc'),
      },
      5: {
        title: t('onboarding.step5Title'),
        description: t('onboarding.step5Desc'),
      },
    }),
    [t],
  )

  const handleNext = useCallback(() => {
    setStep((s) => (s < TOTAL_STEPS ? s + 1 : s))
  }, [])

  const handleBack = useCallback(() => {
    setStep((s) => (s > 1 ? s - 1 : s))
  }, [])

  const handleFinish = useCallback(() => {
    setIsSubmitting(true)
    // Navigate to overview after onboarding
    void navigate({ to: '/overview' })
  }, [navigate])

  const canProceed =
    (step === 1 && orgName.trim().length > 0) ||
    (step === 2 && merchantName.trim().length > 0) ||
    (step === 3 && connectorDone) ||
    step === 4 ||
    (step === 5 && testPaymentDone)

  const stepInfo = STEP_TITLES[step]!

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <h1 className="text-3xl font-bold">{t('onboarding.title')}</h1>

      <ProgressBar currentStep={step} />

      <Card>
        <CardHeader>
          <CardTitle>{stepInfo.title}</CardTitle>
          <CardDescription>{stepInfo.description}</CardDescription>
        </CardHeader>
        <CardContent className="min-h-[200px]">
          {step === 1 && <StepOrganization name={orgName} onChange={setOrgName} />}
          {step === 2 && <StepMerchant name={merchantName} onChange={setMerchantName} />}
          {step === 3 && <StepConnector onComplete={() => setConnectorDone(true)} />}
          {step === 4 && <StepRouting />}
          {step === 5 && <StepTestPayment onComplete={() => setTestPaymentDone(true)} />}
        </CardContent>
      </Card>

      <div className="flex justify-between">
        <div>
          {step > 1 && (
            <Button type="button" variant="outline" onClick={handleBack}>
              <ArrowLeft className="mr-1 h-4 w-4" />
              {t('common.back')}
            </Button>
          )}
        </div>
        <div className="flex gap-2">
          {(step === 4 ||
            (step === 3 && !connectorDone) ||
            (step === 5 && !testPaymentDone)) && (
            <Button type="button" variant="ghost" onClick={handleNext}>
              {t('common.skip')}
            </Button>
          )}
          {step < TOTAL_STEPS ? (
            <Button type="button" onClick={handleNext} disabled={!canProceed}>
              {t('common.next')}
              <ArrowRight className="ml-1 h-4 w-4" />
            </Button>
          ) : (
            <Button type="button" onClick={handleFinish} disabled={isSubmitting}>
              {isSubmitting ? (
                <>
                  <Loader2 className="mr-1 h-4 w-4 animate-spin" />
                  {t('onboarding.finishing')}
                </>
              ) : (
                t('onboarding.finishButton')
              )}
            </Button>
          )}
        </div>
      </div>
    </div>
  )
}
