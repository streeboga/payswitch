# Routing Rules Edit UI Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add edit functionality to routing rules — click a row to open the edit dialog pre-populated with current values, update via PATCH.

**Architecture:** Pure frontend change. Backend PATCH endpoint already works. Need: (1) `useRoutingRule(key)` hook to fetch a single rule, (2) `get(key)` API method, (3) deserializer to convert `rules[]` JSON back into form values, (4) edit dialog in routing-rules page reusing existing PriorityForm/RuleBasedForm/VolumeSplitForm with `defaultValues`.

**Tech Stack:** React 19, TypeScript, TanStack Query, react-hook-form, Zod

---

### Task 1: Add `get(key)` to routing rules API endpoint

**Files:**
- Modify: `dashboard/src/api/endpoints/dashboard-routing-rules.ts`

**Step 1: Add get method**

Add to the `dashboardRoutingRules` object, after `list()`:

```typescript
async get(key: string) {
  const doc = await api
    .get(`dashboard/routing-rules/${key}`)
    .json<{ data: { type: string; id: string; attributes: RoutingRuleAttributes } }>()
  return extractAttributes(doc.data)
},
```

Add `api` to the imports from `'../client'`.

**Step 2: Commit**

```bash
git add dashboard/src/api/endpoints/dashboard-routing-rules.ts
git commit -m "feat: add get() to routing rules API endpoint"
```

---

### Task 2: Add `useRoutingRule(key)` hook

**Files:**
- Modify: `dashboard/src/hooks/use-routing-rules.ts`

**Step 1: Add hook**

After `useRoutingRulesList`, add:

```typescript
export function useRoutingRule(key: string | null) {
  const merchantKey = useContextStore((s) => s.currentMerchantKey)
  const testMode = useContextStore((s) => s.testMode)

  return useQuery({
    queryKey: ['routing-rules', 'detail', key, merchantKey, testMode],
    queryFn: () => dashboardRoutingRules.get(key!),
    staleTime: STALE_TIME,
    enabled: !!merchantKey && !!key,
  })
}
```

**Step 2: Commit**

```bash
git add dashboard/src/hooks/use-routing-rules.ts
git commit -m "feat: add useRoutingRule hook for single rule fetch"
```

---

### Task 3: Create rules deserializer utility

**Files:**
- Create: `dashboard/src/components/routing/deserialize-rules.ts`

**Step 1: Write the deserializer**

This converts the `rules[]` JSON array from the backend back into form-compatible values for each rule type.

```typescript
import type { PriorityFormValues } from './priority-form'
import type { RuleBasedFormValues } from './rule-based-form'
import type { VolumeSplitFormValues } from './volume-split-form'

interface RuleEntry {
  connector_id?: string
  priority?: number
  field?: string
  operator?: string
  value?: string
  default?: boolean
  percentage?: number
}

export function deserializePriorityRules(
  name: string,
  rules: RuleEntry[],
): PriorityFormValues {
  const sorted = [...rules].sort((a, b) => (a.priority ?? 0) - (b.priority ?? 0))
  return {
    name,
    connectors: sorted.map((r) => ({ connector_id: r.connector_id ?? '' })),
  }
}

export function deserializeRuleBasedRules(
  name: string,
  rules: RuleEntry[],
): RuleBasedFormValues {
  const defaultRule = rules.find((r) => r.default === true)
  const conditions = rules.filter((r) => !r.default)

  return {
    name,
    conditions: conditions.map((c) => ({
      field: c.field ?? 'currency',
      operator: c.operator ?? 'equals',
      value: c.value ?? '',
      connector_id: c.connector_id ?? '',
    })),
    default_connector_id: defaultRule?.connector_id ?? '',
  }
}

export function deserializeVolumeSplitRules(
  name: string,
  rules: RuleEntry[],
): VolumeSplitFormValues {
  return {
    name,
    splits: rules.map((r) => ({
      connector_id: r.connector_id ?? '',
      percentage: r.percentage ?? 0,
    })),
  }
}
```

**Step 2: Commit**

```bash
git add dashboard/src/components/routing/deserialize-rules.ts
git commit -m "feat: add routing rules deserializer for edit form population"
```

---

### Task 4: Add edit dialog to routing-rules page

**Files:**
- Modify: `dashboard/src/pages/routing-rules.tsx`
- Modify: `dashboard/src/locales/ru.json`
- Modify: `dashboard/src/locales/en.json`

**Step 1: Add i18n keys**

Add to `routingRules` section in both locale files:

**ru.json:**
```json
"editPriorityTitle": "Редактировать приоритет",
"editPriorityDesc": "Измените порядок приоритета коннекторов",
"editRuleBasedTitle": "Редактировать правило",
"editRuleBasedDesc": "Измените условия маршрутизации платежей",
"editVolumeSplitTitle": "Редактировать распределение",
"editVolumeSplitDesc": "Измените процентное распределение трафика"
```

**en.json:**
```json
"editPriorityTitle": "Edit priority",
"editPriorityDesc": "Change connector priority order",
"editRuleBasedTitle": "Edit rule",
"editRuleBasedDesc": "Change payment routing conditions",
"editVolumeSplitTitle": "Edit distribution",
"editVolumeSplitDesc": "Change traffic distribution percentages"
```

**Step 2: Add edit state and handlers to RoutingRulesPage**

Add imports at top:
```typescript
import { Pencil } from 'lucide-react'
import {
  deserializePriorityRules,
  deserializeRuleBasedRules,
  deserializeVolumeSplitRules,
} from '@/components/routing/deserialize-rules'
```

Add state for editing:
```typescript
const [editTarget, setEditTarget] = useState<RoutingRuleRow | null>(null)
```

Add edit handler:
```typescript
const handleEdit = useCallback((row: RoutingRuleRow) => {
  setEditTarget(row)
}, [])

const handleEditSubmit = useCallback(
  (values: PriorityFormValues | RuleBasedFormValues | VolumeSplitFormValues) => {
    if (!editTarget) return

    let rules: unknown[]
    const type = editTarget.type

    if (type === 'priority') {
      const v = values as PriorityFormValues
      rules = v.connectors.map((c, i) => ({
        connector_id: c.connector_id,
        priority: i + 1,
      }))
    } else if (type === 'rule_based') {
      const v = values as RuleBasedFormValues
      rules = [
        ...v.conditions.map((c) => ({
          field: c.field,
          operator: c.operator,
          value: c.value,
          connector_id: c.connector_id,
        })),
        { default: true, connector_id: v.default_connector_id },
      ]
    } else {
      const v = values as VolumeSplitFormValues
      rules = v.splits.map((s) => ({
        connector_id: s.connector_id,
        percentage: s.percentage,
      }))
    }

    updateMutation.mutate(
      {
        key: editTarget.id,
        attrs: { name: values.name, rules },
      },
      {
        onSuccess: () => setEditTarget(null),
      },
    )
  },
  [editTarget, updateMutation],
)
```

**Step 3: Add Edit button to table columns**

In `createColumns`, add `onEdit` parameter:
```typescript
function createColumns(
  onToggleActive: (row: RoutingRuleRow) => void,
  onEdit: (row: RoutingRuleRow) => void,
  onDelete: (row: RoutingRuleRow) => void,
  t: (key: string) => string,
): ColumnDef<RoutingRuleRow, unknown>[]
```

Change the `actions` column cell to include both edit and delete buttons:
```tsx
{
  id: 'actions',
  header: '',
  size: 80,
  cell: ({ row }) => (
    <div className="flex items-center gap-1">
      <Button
        variant="ghost"
        size="icon"
        className="text-muted-foreground hover:text-foreground"
        onClick={() => onEdit(row.original)}
        aria-label={`${t('common.edit')} ${row.original.name}`}
      >
        <Pencil className="h-4 w-4" />
      </Button>
      <Button
        variant="ghost"
        size="icon"
        className="text-muted-foreground hover:text-destructive"
        onClick={() => onDelete(row.original)}
        aria-label={`${t('common.delete')} ${row.original.name}`}
      >
        <Trash2 className="h-4 w-4" />
      </Button>
    </div>
  ),
  enableSorting: false,
},
```

Update the `columns` useMemo:
```typescript
const columns = useMemo(
  () => createColumns(handleToggleActive, handleEdit, handleDelete, t),
  [handleToggleActive, handleEdit, handleDelete, t],
)
```

**Step 4: Add Edit Dialog**

After the Create dialog, add:

```tsx
{/* Edit Rule Dialog */}
<Dialog open={!!editTarget} onOpenChange={(open) => { if (!open) setEditTarget(null) }}>
  <DialogContent className="sm:max-w-lg">
    <DialogHeader>
      <DialogTitle>
        {editTarget?.type === 'priority' && t('routingRules.editPriorityTitle')}
        {editTarget?.type === 'rule_based' && t('routingRules.editRuleBasedTitle')}
        {editTarget?.type === 'volume_split' && t('routingRules.editVolumeSplitTitle')}
      </DialogTitle>
      <DialogDescription>
        {editTarget?.type === 'priority' && t('routingRules.editPriorityDesc')}
        {editTarget?.type === 'rule_based' && t('routingRules.editRuleBasedDesc')}
        {editTarget?.type === 'volume_split' && t('routingRules.editVolumeSplitDesc')}
      </DialogDescription>
    </DialogHeader>

    <Suspense fallback={null}>
      {editTarget?.type === 'priority' && (
        <PriorityForm
          key={editTarget.id}
          defaultValues={deserializePriorityRules(editTarget.name, editTarget.rules as any[])}
          onSubmit={handleEditSubmit}
          isPending={updateMutation.isPending}
        />
      )}
      {editTarget?.type === 'rule_based' && (
        <RuleBasedForm
          key={editTarget.id}
          defaultValues={deserializeRuleBasedRules(editTarget.name, editTarget.rules as any[])}
          onSubmit={handleEditSubmit}
          isPending={updateMutation.isPending}
        />
      )}
      {editTarget?.type === 'volume_split' && (
        <VolumeSplitForm
          key={editTarget.id}
          defaultValues={deserializeVolumeSplitRules(editTarget.name, editTarget.rules as any[])}
          onSubmit={handleEditSubmit}
          isPending={updateMutation.isPending}
        />
      )}
    </Suspense>
  </DialogContent>
</Dialog>
```

Note: `key={editTarget.id}` forces react-hook-form to reinitialize with new defaultValues when switching between rules.

**Step 5: Run checks**

```bash
cd dashboard && npm run types:check && npm run lint:check
```

**Step 6: Commit**

```bash
git add dashboard/src/pages/routing-rules.tsx dashboard/src/locales/ru.json dashboard/src/locales/en.json
git commit -m "feat: add edit dialog for routing rules with form pre-population"
```

---

### Task 5: Verify in browser

**Step 1: Start dev server**

```bash
composer dev
```

**Step 2: Test the flow**

1. Navigate to `/connectors` — verify connectors load
2. Navigate to `/routing` — verify rules list loads
3. Click edit (pencil icon) on a rule — verify dialog opens with pre-populated values
4. Change a value and save — verify it updates
5. Verify the list refreshes with new values

**Step 3: Run full checks**

```bash
cd dashboard && npm run types:check && npm run lint && npm run format
./vendor/bin/pest
```

**Step 4: Final commit if needed**

```bash
git add -A
git commit -m "fix: cleanup after routing rules edit verification"
```
