import {
  createResource,
  getCollection,
  getResource,
  updateResource,
  deleteResource,
} from '../client'
import type { RoutingRuleAttributes } from '../types'
import type { ListParams } from './params'
import { buildSearchParams } from './params'

export const routingRules = {
  list(merchantKey: string, params?: ListParams) {
    return getCollection<RoutingRuleAttributes>(
      `merchants/${merchantKey}/routing-rules`,
      { searchParams: buildSearchParams(params) },
    )
  },

  get(merchantKey: string, ruleKey: string) {
    return getResource<RoutingRuleAttributes>(
      `merchants/${merchantKey}/routing-rules/${ruleKey}`,
    )
  },

  create(
    merchantKey: string,
    attrs: {
      type: string
      name: string
      business_profile_id: string
      rules: unknown[]
      active?: boolean
      priority?: number
    },
  ) {
    return createResource<RoutingRuleAttributes>(
      `merchants/${merchantKey}/routing-rules`,
      attrs,
    )
  },

  update(
    merchantKey: string,
    ruleKey: string,
    attrs: Partial<{
      name: string
      rules: unknown[]
      active: boolean
      priority: number
    }>,
  ) {
    return updateResource<RoutingRuleAttributes>(
      `merchants/${merchantKey}/routing-rules/${ruleKey}`,
      attrs,
    )
  },

  delete(merchantKey: string, ruleKey: string) {
    return deleteResource(`merchants/${merchantKey}/routing-rules/${ruleKey}`)
  },
} as const
