import { createResource, getResource } from '../client'
import type { MerchantAccountAttributes } from '../types'

export const merchants = {
  get(merchantKey: string) {
    return getResource<MerchantAccountAttributes>(`merchants/${merchantKey}`)
  },

  create(attrs: { name: string; organization_id: string }) {
    return createResource<MerchantAccountAttributes>('merchants', attrs)
  },
} as const
