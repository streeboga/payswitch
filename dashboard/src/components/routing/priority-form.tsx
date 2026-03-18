import { useCallback } from 'react'
import { useForm, useFieldArray } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useTranslation } from 'react-i18next'
import {
  DndContext,
  type DragEndEvent,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  closestCenter,
} from '@dnd-kit/core'
import {
  SortableContext,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable'
import { GripVertical, X, Plus } from 'lucide-react'

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
import { Select, SelectContent, SelectItem, SelectTrigger } from '@/components/ui/select'

// ─── Schema ─────────────────────────────────────────────────

const prioritySchema = z.object({
  name: z.string().min(1, 'Введите название'),
  connectors: z
    .array(
      z.object({
        connector_id: z.string().min(1, 'Выберите коннектор'),
      }),
    )
    .min(1, 'Добавьте хотя бы один коннектор'),
})

export type PriorityFormValues = z.infer<typeof prioritySchema>

// ─── Available connectors (placeholder list) ────────────────

const AVAILABLE_CONNECTORS = [
  { id: 'stripe', label: 'Stripe' },
  { id: 'cloudpayments', label: 'CloudPayments' },
  { id: 'test', label: 'Test' },
]

const AVAILABLE_CONNECTORS_MAP = new Map(AVAILABLE_CONNECTORS.map((c) => [c.id, c]))

// ─── Sortable Item ──────────────────────────────────────────

function SortableItem({
  id,
  label,
  onRemove,
}: {
  id: string
  label: string
  onRemove: () => void
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
    useSortable({ id })

  const style = {
    transform: transform
      ? `translate3d(${String(transform.x)}px, ${String(transform.y)}px, 0)`
      : undefined,
    transition,
    opacity: isDragging ? 0.5 : 1,
  }

  return (
    <div
      ref={setNodeRef}
      style={style}
      className="flex items-center gap-2 rounded-md border bg-white px-3 py-2 dark:bg-zinc-900"
    >
      <button
        type="button"
        className="cursor-grab touch-none text-zinc-400 hover:text-zinc-600"
        {...attributes}
        {...listeners}
      >
        <GripVertical className="h-4 w-4" />
      </button>
      <span className="flex-1 text-sm font-medium">{label}</span>
      <button
        type="button"
        className="text-zinc-400 hover:text-red-500"
        onClick={onRemove}
        aria-label={`${label}`}
      >
        <X className="h-4 w-4" />
      </button>
    </div>
  )
}

// ─── Priority Form ──────────────────────────────────────────

interface PriorityFormProps {
  defaultValues?: PriorityFormValues
  onSubmit: (values: PriorityFormValues) => void
  isPending?: boolean
}

export function PriorityForm({ defaultValues, onSubmit, isPending }: PriorityFormProps) {
  const { t } = useTranslation()
  const form = useForm<PriorityFormValues>({
    resolver: zodResolver(prioritySchema),
    defaultValues: defaultValues ?? {
      name: '',
      connectors: [],
    },
  })

  const { fields, append, remove, move } = useFieldArray({
    control: form.control,
    name: 'connectors',
  })

  const sensors = useSensors(useSensor(PointerSensor), useSensor(KeyboardSensor))

  const handleDragEnd = useCallback(
    (event: DragEndEvent) => {
      const { active, over } = event
      if (over && active.id !== over.id) {
        const oldIndex = fields.findIndex((f) => f.id === active.id)
        const newIndex = fields.findIndex((f) => f.id === over.id)
        if (oldIndex !== -1 && newIndex !== -1) {
          move(oldIndex, newIndex)
        }
      }
    },
    [fields, move],
  )

  const usedConnectorIds = form.watch('connectors').map((c) => c.connector_id)
  const availableToAdd = AVAILABLE_CONNECTORS.filter(
    (c) => !usedConnectorIds.includes(c.id),
  )

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
                <Input placeholder={t('priorityForm.namePlaceholder')} {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />

        <div className="space-y-2">
          <FormLabel>{t('priorityForm.connectorsLabel')}</FormLabel>

          <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            onDragEnd={handleDragEnd}
          >
            <SortableContext
              items={fields.map((f) => f.id)}
              strategy={verticalListSortingStrategy}
            >
              <div className="space-y-1">
                {fields.map((field, index) => {
                  const connector = AVAILABLE_CONNECTORS_MAP.get(field.connector_id)
                  return (
                    <SortableItem
                      key={field.id}
                      id={field.id}
                      label={connector?.label ?? field.connector_id}
                      onRemove={() => remove(index)}
                    />
                  )
                })}
              </div>
            </SortableContext>
          </DndContext>

          {form.formState.errors.connectors?.root && (
            <p className="text-destructive text-sm">
              {form.formState.errors.connectors.root.message}
            </p>
          )}
          {form.formState.errors.connectors?.message && (
            <p className="text-destructive text-sm">
              {form.formState.errors.connectors.message}
            </p>
          )}

          {availableToAdd.length > 0 && (
            <Select
              onValueChange={(value) => {
                append({ connector_id: value })
              }}
              value=""
            >
              <SelectTrigger className="w-full">
                <div className="flex items-center gap-2">
                  <Plus className="h-4 w-4" />
                  <span>{t('priorityForm.addConnector')}</span>
                </div>
              </SelectTrigger>
              <SelectContent>
                {availableToAdd.map((c) => (
                  <SelectItem key={c.id} value={c.id}>
                    {c.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </div>

        <Button type="submit" disabled={isPending} className="w-full">
          {isPending ? t('common.saving') : t('common.save')}
        </Button>
      </form>
    </Form>
  )
}
