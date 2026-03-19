import { useTranslation } from 'react-i18next'
import type { AnalyticsPeriod } from '@/api/endpoints/analytics'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'

const PERIOD_OPTIONS: { value: AnalyticsPeriod; labelKey: string }[] = [
  { value: 'today', labelKey: 'analytics.periodToday' },
  { value: '7d', labelKey: 'analytics.period7d' },
  { value: '30d', labelKey: 'analytics.period30d' },
  { value: '90d', labelKey: 'analytics.period90d' },
]

interface PeriodFilterProps {
  value: AnalyticsPeriod
  onChange: (value: AnalyticsPeriod) => void
}

export function PeriodFilter({ value, onChange }: PeriodFilterProps) {
  const { t } = useTranslation()

  return (
    <Select value={value} onValueChange={onChange}>
      <SelectTrigger
        size="sm"
        aria-label={t('analytics.periodLabel')}
        data-testid="period-filter"
      >
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {PERIOD_OPTIONS.map((opt) => (
          <SelectItem key={opt.value} value={opt.value}>
            {t(opt.labelKey)}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}
