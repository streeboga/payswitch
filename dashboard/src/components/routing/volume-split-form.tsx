import { useForm, useFieldArray } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useTranslation } from 'react-i18next'
import { Plus, Trash2 } from 'lucide-react'

import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'

// ─── Constants ──────────────────────────────────────────────

const CONNECTOR_OPTIONS = [
  { value: 'stripe', label: 'Stripe' },
  { value: 'cloudpayments', label: 'CloudPayments' },
  { value: 'test', label: 'Test' },
]

const CONNECTOR_OPTIONS_MAP = new Map(CONNECTOR_OPTIONS.map((c) => [c.value, c]))

const SEGMENT_COLORS = [
  'bg-blue-500',
  'bg-emerald-500',
  'bg-amber-500',
  'bg-purple-500',
  'bg-rose-500',
  'bg-cyan-500',
]

// ─── Schema ─────────────────────────────────────────────────

const volumeSplitSchema = z.object({
  name: z.string().min(1, 'Введите название'),
  splits: z
    .array(
      z.object({
        connector_id: z.string().min(1, 'Выберите коннектор'),
        percentage: z.number().min(1, 'Минимум 1%').max(100, 'Максимум 100%'),
      }),
    )
    .min(1, 'Добавьте хотя бы один коннектор'),
})

export type VolumeSplitFormValues = z.infer<typeof volumeSplitSchema>

// ─── Distribution Bar ───────────────────────────────────────

function DistributionBar({
  splits,
  totalLabel,
  totalNeededLabel,
}: {
  splits: { connector_id: string; percentage: number }[]
  totalLabel: string
  totalNeededLabel: string
}) {
  const total = splits.reduce((sum, s) => sum + (s.percentage || 0), 0)

  return (
    <div className="space-y-1">
      <div className="flex h-6 w-full overflow-hidden rounded-md border">
        {splits.map((split, index) => {
          const width = split.percentage || 0
          if (width <= 0) return null
          const connector = CONNECTOR_OPTIONS_MAP.get(split.connector_id)
          const color = SEGMENT_COLORS[index % SEGMENT_COLORS.length]
          return (
            <div
              key={`${split.connector_id}-${String(index)}`}
              className={`${color} flex items-center justify-center text-xs font-medium text-white transition-all`}
              style={{ width: `${String(width)}%` }}
              title={`${connector?.label ?? split.connector_id}: ${String(width)}%`}
            >
              {width >= 10 && `${String(width)}%`}
            </div>
          )
        })}
        {total < 100 && (
          <div
            className="bg-muted flex items-center justify-center text-xs"
            style={{ width: `${String(100 - total)}%` }}
          >
            {100 - total >= 10 && `${String(100 - total)}%`}
          </div>
        )}
      </div>
      <p
        className={`text-xs ${total === 100 ? 'text-muted-foreground' : 'text-destructive'}`}
      >
        {totalLabel}{total !== 100 && ` ${totalNeededLabel}`}
      </p>
    </div>
  )
}

// ─── Volume Split Form ──────────────────────────────────────

interface VolumeSplitFormProps {
  defaultValues?: VolumeSplitFormValues
  onSubmit: (values: VolumeSplitFormValues) => void
  isPending?: boolean
}

export function VolumeSplitForm({
  defaultValues,
  onSubmit,
  isPending,
}: VolumeSplitFormProps) {
  const { t } = useTranslation()
  const form = useForm<VolumeSplitFormValues>({
    resolver: zodResolver(volumeSplitSchema),
    defaultValues: defaultValues ?? {
      name: '',
      splits: [{ connector_id: '', percentage: 100 }],
    },
  })

  const { fields, append, remove } = useFieldArray({
    control: form.control,
    name: 'splits',
  })

  const watchedSplits = form.watch('splits')
  const total = watchedSplits.reduce((sum, s) => sum + (s.percentage || 0), 0)

  const handleSubmit = (values: VolumeSplitFormValues) => {
    const total = values.splits.reduce((sum, s) => sum + (s.percentage || 0), 0)
    if (total !== 100) {
      form.setError('splits', {
        type: 'manual',
        message: t('volumeSplitForm.errorTotal'),
      })
      return
    }
    onSubmit(values)
  }

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(handleSubmit)} className="space-y-4">
        <FormField
          control={form.control}
          name="name"
          render={({ field }) => (
            <FormItem>
              <FormLabel>{t('common.name')}</FormLabel>
              <FormControl>
                <Input placeholder={t('volumeSplitForm.namePlaceholder')} {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />

        <div className="space-y-3">
          <FormLabel>{t('volumeSplitForm.distributionLabel')}</FormLabel>

          <DistributionBar
            splits={watchedSplits}
            totalLabel={t('volumeSplitForm.total', { total })}
            totalNeededLabel={t('volumeSplitForm.totalNeeded')}
          />

          {fields.map((field, index) => (
            <div key={field.id} className="flex items-start gap-2">
              <div className="flex items-center gap-1 pt-2">
                <div
                  className={`h-3 w-3 rounded-full ${SEGMENT_COLORS[index % SEGMENT_COLORS.length]}`}
                />
              </div>

              <FormField
                control={form.control}
                name={`splits.${index}.connector_id` as const}
                render={({ field: f }) => (
                  <FormItem className="flex-1">
                    <Select onValueChange={f.onChange} value={f.value}>
                      <FormControl>
                        <SelectTrigger>
                          <SelectValue placeholder={t('common.connector')} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {CONNECTOR_OPTIONS.map((opt) => (
                          <SelectItem key={opt.value} value={opt.value}>
                            {opt.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name={`splits.${index}.percentage` as const}
                render={({ field: f }) => (
                  <FormItem className="w-24">
                    <FormControl>
                      <Input
                        type="number"
                        min={1}
                        max={100}
                        placeholder="%"
                        value={f.value}
                        onChange={(e) => f.onChange(e.target.valueAsNumber || 0)}
                        onBlur={f.onBlur}
                        name={f.name}
                        ref={f.ref}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="mt-0.5 shrink-0"
                onClick={() => remove(index)}
                disabled={fields.length <= 1}
                aria-label={t('volumeSplitForm.deleteConnector')}
              >
                <Trash2 className="h-4 w-4" />
              </Button>
            </div>
          ))}

          {form.formState.errors.splits?.root && (
            <p className="text-destructive text-sm">
              {form.formState.errors.splits.root.message}
            </p>
          )}
          {form.formState.errors.splits?.message && (
            <p className="text-destructive text-sm">
              {form.formState.errors.splits.message}
            </p>
          )}

          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => append({ connector_id: '', percentage: 0 })}
          >
            <Plus className="mr-2 h-4 w-4" />
            {t('volumeSplitForm.addConnector')}
          </Button>
        </div>

        <Button type="submit" disabled={isPending} className="w-full">
          {isPending ? t('common.saving') : t('common.save')}
        </Button>
      </form>
    </Form>
  )
}
