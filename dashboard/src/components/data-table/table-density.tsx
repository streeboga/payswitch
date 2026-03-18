import { AlignJustify, AlignCenter, AlignLeft } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { usePreferencesStore, type Density } from '@/stores/preferences'

const DENSITY_OPTIONS: {
  value: Density
  labelKey: string
  icon: typeof AlignJustify
}[] = [
  { value: 'compact', labelKey: 'table.compact', icon: AlignJustify },
  { value: 'comfortable', labelKey: 'table.comfortable', icon: AlignCenter },
  { value: 'spacious', labelKey: 'table.spacious', icon: AlignLeft },
]

export function TableDensityToggle() {
  const { t } = useTranslation()
  const density = usePreferencesStore((s) => s.density)
  const setDensity = usePreferencesStore((s) => s.setDensity)

  return (
    <ToggleGroup
      type="single"
      value={density}
      onValueChange={(v) => {
        if (v) setDensity(v as Density)
      }}
      size="sm"
    >
      {DENSITY_OPTIONS.map((opt) => (
        <Tooltip key={opt.value}>
          <TooltipTrigger asChild>
            <ToggleGroupItem value={opt.value} aria-label={t(opt.labelKey)} className="h-8 w-8">
              <opt.icon className="h-3.5 w-3.5" />
            </ToggleGroupItem>
          </TooltipTrigger>
          <TooltipContent>{t(opt.labelKey)}</TooltipContent>
        </Tooltip>
      ))}
    </ToggleGroup>
  )
}
