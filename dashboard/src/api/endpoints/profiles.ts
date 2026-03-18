import { createResource, getResource } from '../client'
import type { BusinessProfileAttributes } from '../types'

export const profiles = {
  get(profileKey: string) {
    return getResource<BusinessProfileAttributes>(`profiles/${profileKey}`)
  },

  create(attrs: { merchant_id: string; webhook_url?: string }) {
    return createResource<BusinessProfileAttributes>('profiles', attrs)
  },
} as const
