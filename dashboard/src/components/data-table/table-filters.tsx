import { type ReactNode, useState } from 'react'
import { Filter, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'

// ─── Filter Definitions ──────────────────────────────────────

export interface SelectFilterDef {
  type: 'select'
  key: string
  label: string
  options: { label: string; value: string }[]
}

export interface TextFilterDef {
  type: 'text'
  key: string
  label: string
  placeholder?: string
}

export interface DateRangeFilterDef {
  type: 'date-range'
  key: string
  label: string
}

export interface NumberRangeFilterDef {
  type: 'number-range'
  key: string
  label: string
  min?: number
  max?: number
}

export type FilterDef =
  | SelectFilterDef
  | TextFilterDef
  | DateRangeFilterDef
  | NumberRangeFilterDef

export type FilterValues = Record<string, string | undefined>

// ─── Component ───────────────────────────────────────────────

interface TableFiltersProps {
  filters: FilterDef[]
  values: FilterValues
  onChange: (key: string, value: string | undefined) => void
  onReset: () => void
  className?: string
  extra?: ReactNode
}

export function TableFilters({
  filters,
  values,
  onChange,
  onReset,
  className,
  extra,
}: TableFiltersProps) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)

  const activeCount = Object.values(values).filter(
    (v) => v !== undefined && v !== '',
  ).length

  return (
    <Collapsible open={open} onOpenChange={setOpen} className={className}>
      <div className="flex items-center gap-2">
        <CollapsibleTrigger asChild>
          <Button variant="outline" size="sm">
            <Filter className="mr-1.5 h-3.5 w-3.5" />
            {t('table.filtersButton')}
            {activeCount > 0 && (
              <Badge variant="secondary" className="ml-1.5">
                {activeCount}
              </Badge>
            )}
          </Button>
        </CollapsibleTrigger>

        {activeCount > 0 && (
          <Button variant="ghost" size="xs" onClick={onReset}>
            <X className="mr-1 h-3 w-3" />
            {t('table.resetFilters')}
          </Button>
        )}

        {extra}
      </div>

      <CollapsibleContent>
        <div
          className={cn(
            'bg-muted/50 mt-3 grid grid-cols-1 gap-4 rounded-md border p-4 sm:grid-cols-2 lg:grid-cols-4',
          )}
        >
          {filters.map((filter) => (
            <FilterField
              key={filter.key}
              filter={filter}
              value={values[filter.key]}
              onChange={(value) => onChange(filter.key, value)}
            />
          ))}
        </div>
      </CollapsibleContent>
    </Collapsible>
  )
}

// ─── Filter Field ────────────────────────────────────────────

interface FilterFieldProps {
  filter: FilterDef
  value: string | undefined
  onChange: (value: string | undefined) => void
}

function FilterField({ filter, value, onChange }: FilterFieldProps) {
  const { t } = useTranslation()

  switch (filter.type) {
    case 'select':
      return (
        <div className="space-y-1.5">
          <Label className="text-xs">{filter.label}</Label>
          <Select
            value={value ?? ''}
            onValueChange={(v) => onChange(v === '' ? undefined : v)}
          >
            <SelectTrigger size="sm">
              <SelectValue placeholder={t('table.filterAll')} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="">{t('table.filterAll')}</SelectItem>
              {filter.options.map((opt) => (
                <SelectItem key={opt.value} value={opt.value}>
                  {opt.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )

    case 'text':
      return (
        <div className="space-y-1.5">
          <Label className="text-xs">{filter.label}</Label>
          <Input
            placeholder={filter.placeholder ?? t('table.searchFilter', { label: filter.label })}
            value={value ?? ''}
            onChange={(e) => onChange(e.target.value === '' ? undefined : e.target.value)}
            className="h-8"
          />
        </div>
      )

    case 'date-range':
      return (
        <div className="space-y-1.5">
          <Label className="text-xs">{filter.label}</Label>
          <div className="flex gap-2">
            <Input
              type="date"
              value={value?.split(',')[0] ?? ''}
              onChange={(e) => {
                const end = value?.split(',')[1] ?? ''
                const v = e.target.value || end ? `${e.target.value},${end}` : undefined
                onChange(v)
              }}
              className="h-8"
            />
            <Input
              type="date"
              value={value?.split(',')[1] ?? ''}
              onChange={(e) => {
                const start = value?.split(',')[0] ?? ''
                const v =
                  start || e.target.value ? `${start},${e.target.value}` : undefined
                onChange(v)
              }}
              className="h-8"
            />
          </div>
        </div>
      )

    case 'number-range':
      return (
        <div className="space-y-1.5">
          <Label className="text-xs">{filter.label}</Label>
          <div className="flex gap-2">
            <Input
              type="number"
              placeholder={t('table.minPlaceholder')}
              min={filter.min}
              max={filter.max}
              value={value?.split(',')[0] ?? ''}
              onChange={(e) => {
                const end = value?.split(',')[1] ?? ''
                const v = e.target.value || end ? `${e.target.value},${end}` : undefined
                onChange(v)
              }}
              className="h-8"
            />
            <Input
              type="number"
              placeholder={t('table.maxPlaceholder')}
              min={filter.min}
              max={filter.max}
              value={value?.split(',')[1] ?? ''}
              onChange={(e) => {
                const start = value?.split(',')[0] ?? ''
                const v =
                  start || e.target.value ? `${start},${e.target.value}` : undefined
                onChange(v)
              }}
              className="h-8"
            />
          </div>
        </div>
      )
  }
}
