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

const CONDITION_FIELDS = [
  { value: 'currency', labelKey: 'ruleBasedForm.fieldCurrency' },
  { value: 'amount', labelKey: 'ruleBasedForm.fieldAmount' },
  { value: 'payment_method', labelKey: 'ruleBasedForm.fieldPaymentMethod' },
  { value: 'country', labelKey: 'ruleBasedForm.fieldCountry' },
] as const

const CONDITION_OPERATORS = [
  { value: 'equals', labelKey: 'ruleBasedForm.operatorEquals' },
  { value: 'not_equals', labelKey: 'ruleBasedForm.operatorNotEquals' },
  { value: 'greater_than', labelKey: 'ruleBasedForm.operatorGreaterThan' },
  { value: 'less_than', labelKey: 'ruleBasedForm.operatorLessThan' },
  { value: 'in', labelKey: 'ruleBasedForm.operatorIn' },
] as const

const CONNECTOR_OPTIONS = [
  { value: 'stripe', label: 'Stripe' },
  { value: 'cloudpayments', label: 'CloudPayments' },
  { value: 'test', label: 'Test' },
]

// ─── Schema ─────────────────────────────────────────────────

const conditionSchema = z.object({
  field: z.string().min(1, 'Выберите поле'),
  operator: z.string().min(1, 'Выберите оператор'),
  value: z.string().min(1, 'Введите значение'),
  connector_id: z.string().min(1, 'Выберите коннектор'),
})

const ruleBasedSchema = z.object({
  name: z.string().min(1, 'Введите название'),
  conditions: z.array(conditionSchema).min(1, 'Добавьте хотя бы одно условие'),
  default_connector_id: z.string().min(1, 'Выберите коннектор по умолчанию'),
})

export type RuleBasedFormValues = z.infer<typeof ruleBasedSchema>

// ─── Rule-Based Form ────────────────────────────────────────

interface RuleBasedFormProps {
  defaultValues?: RuleBasedFormValues
  onSubmit: (values: RuleBasedFormValues) => void
  isPending?: boolean
}

export function RuleBasedForm({
  defaultValues,
  onSubmit,
  isPending,
}: RuleBasedFormProps) {
  const { t } = useTranslation()
  const form = useForm<RuleBasedFormValues>({
    resolver: zodResolver(ruleBasedSchema),
    defaultValues: defaultValues ?? {
      name: '',
      conditions: [{ field: '', operator: '', value: '', connector_id: '' }],
      default_connector_id: '',
    },
  })

  const { fields, append, remove } = useFieldArray({
    control: form.control,
    name: 'conditions',
  })

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
        <FormField
          control={form.control}
          name="name"
          render={({ field }) => (
            <FormItem>
              <FormLabel>{t('common.name')}</FormLabel>
              <FormControl>
                <Input placeholder={t('ruleBasedForm.namePlaceholder')} {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />

        <div className="space-y-3">
          <FormLabel>{t('ruleBasedForm.conditionsLabel')}</FormLabel>

          {fields.map((field, index) => (
            <div
              key={field.id}
              className="flex flex-wrap items-start gap-2 rounded-md border p-3"
            >
              <FormField
                control={form.control}
                name={`conditions.${index}.field` as const}
                render={({ field: f }) => (
                  <FormItem className="min-w-[120px] flex-1">
                    <Select onValueChange={f.onChange} value={f.value}>
                      <FormControl>
                        <SelectTrigger>
                          <SelectValue placeholder={t('ruleBasedForm.fieldPlaceholder')} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {CONDITION_FIELDS.map((opt) => (
                          <SelectItem key={opt.value} value={opt.value}>
                            {t(opt.labelKey)}
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
                name={`conditions.${index}.operator` as const}
                render={({ field: f }) => (
                  <FormItem className="min-w-[120px] flex-1">
                    <Select onValueChange={f.onChange} value={f.value}>
                      <FormControl>
                        <SelectTrigger>
                          <SelectValue placeholder={t('ruleBasedForm.operatorPlaceholder')} />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {CONDITION_OPERATORS.map((opt) => (
                          <SelectItem key={opt.value} value={opt.value}>
                            {t(opt.labelKey)}
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
                name={`conditions.${index}.value` as const}
                render={({ field: f }) => (
                  <FormItem className="min-w-[100px] flex-1">
                    <FormControl>
                      <Input placeholder={t('ruleBasedForm.valuePlaceholder')} {...f} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name={`conditions.${index}.connector_id` as const}
                render={({ field: f }) => (
                  <FormItem className="min-w-[140px] flex-1">
                    <Select onValueChange={f.onChange} value={f.value}>
                      <FormControl>
                        <SelectTrigger>
                          <SelectValue placeholder={t('ruleBasedForm.connectorPlaceholder')} />
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

              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="mt-0.5 shrink-0"
                onClick={() => remove(index)}
                disabled={fields.length <= 1}
                aria-label={t('ruleBasedForm.deleteCondition')}
              >
                <Trash2 className="h-4 w-4" />
              </Button>
            </div>
          ))}

          {form.formState.errors.conditions?.root && (
            <p className="text-destructive text-sm">
              {form.formState.errors.conditions.root.message}
            </p>
          )}
          {form.formState.errors.conditions?.message && (
            <p className="text-destructive text-sm">
              {form.formState.errors.conditions.message}
            </p>
          )}

          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() =>
              append({ field: '', operator: '', value: '', connector_id: '' })
            }
          >
            <Plus className="mr-2 h-4 w-4" />
            {t('ruleBasedForm.addCondition')}
          </Button>
        </div>

        <FormField
          control={form.control}
          name="default_connector_id"
          render={({ field }) => (
            <FormItem>
              <FormLabel>{t('ruleBasedForm.defaultConnector')}</FormLabel>
              <Select onValueChange={field.onChange} value={field.value}>
                <FormControl>
                  <SelectTrigger className="w-full">
                    <SelectValue placeholder={t('ruleBasedForm.selectConnector')} />
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

        <Button type="submit" disabled={isPending} className="w-full">
          {isPending ? t('common.saving') : t('common.save')}
        </Button>
      </form>
    </Form>
  )
}
