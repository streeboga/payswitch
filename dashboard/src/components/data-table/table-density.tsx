import { AlignJustify, AlignCenter, AlignLeft, Rows3 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/button'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { usePreferencesStore, type Density } from '@/stores/preferences'
import { cn } from '@/lib/utils'

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
    <Popover>
      <Tooltip>
        <TooltipTrigger asChild>
          <PopoverTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className="size-8"
              aria-label={t('table.density')}
            >
              <Rows3 className="size-4" />
            </Button>
          </PopoverTrigger>
        </TooltipTrigger>
        <TooltipContent>{t('table.density')}</TooltipContent>
      </Tooltip>

      <PopoverContent align="end" className="w-36 p-1">
        {DENSITY_OPTIONS.map((opt) => {
          const Icon = opt.icon
          return (
            <button
              key={opt.value}
              onClick={() => setDensity(opt.value)}
              className={cn(
                'flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-xs transition-colors',
                density === opt.value
                  ? 'bg-accent text-accent-foreground'
                  : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
              )}
            >
              <Icon className="size-3.5" />
              {t(opt.labelKey)}
            </button>
          )
        })}
      </PopoverContent>
    </Popover>
  )
}
