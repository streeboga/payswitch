import { createResource, deleteResource } from '../client'
import type { ApiKeyAttributes } from '../types'

export const apiKeys = {
  create(merchantKey: string, attrs: { name: string; type: string }) {
    return createResource<ApiKeyAttributes>(`merchants/${merchantKey}/api-keys`, attrs)
  },

  revoke(merchantKey: string, keyId: string) {
    return deleteResource(`merchants/${merchantKey}/api-keys/${keyId}`)
  },
} as const
