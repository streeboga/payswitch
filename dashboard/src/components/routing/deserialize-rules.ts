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
