import { useMemo } from 'react'
import { Link } from '@tanstack/react-router'
import { CheckCircle2, Circle, ArrowRight } from 'lucide-react'
import { useTranslation } from 'react-i18next'

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

// ─── Checklist Item ──────────────────────────────────────────

interface ChecklistItem {
  label: string
  complete: boolean
}

interface OnboardingChecklistProps {
  hasOrganization: boolean
  hasMerchant: boolean
  hasConnector: boolean
  hasTestPayment: boolean
  className?: string
}

export function OnboardingChecklist({
  hasOrganization,
  hasMerchant,
  hasConnector,
  hasTestPayment,
  className,
}: OnboardingChecklistProps) {
  const { t } = useTranslation()

  const items: ChecklistItem[] = useMemo(
    () => [
      { label: t('onboardingChecklist.organization'), complete: hasOrganization },
      { label: t('onboardingChecklist.merchant'), complete: hasMerchant },
      { label: t('onboardingChecklist.connector'), complete: hasConnector },
      { label: t('onboardingChecklist.testPayment'), complete: hasTestPayment },
    ],
    [t, hasOrganization, hasMerchant, hasConnector, hasTestPayment],
  )

  const allComplete = items.every((item) => item.complete)

  if (allComplete) return null

  return (
    <Card className={cn(className)}>
      <CardHeader className="pb-3">
        <CardTitle className="text-base">{t('onboardingChecklist.title')}</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        <ul className="space-y-2">
          {items.map((item) => (
            <li key={item.label} className="flex items-center gap-2 text-sm">
              {item.complete ? (
                <CheckCircle2 className="h-4 w-4 text-emerald-500" />
              ) : (
                <Circle className="text-muted-foreground h-4 w-4" />
              )}
              <span className={cn(item.complete && 'text-muted-foreground line-through')}>
                {item.label}
              </span>
            </li>
          ))}
        </ul>

        <Button asChild variant="outline" size="sm" className="w-full">
          <Link to="/onboarding">
            {t('onboardingChecklist.continueButton')}
            <ArrowRight className="ml-1 h-4 w-4" />
          </Link>
        </Button>
      </CardContent>
    </Card>
  )
}
