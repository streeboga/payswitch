import { createResource } from '../client'
import type { OrganizationAttributes } from '../types'

export const organizations = {
  create(attrs: { name: string }) {
    return createResource<OrganizationAttributes>('organizations', attrs)
  },
} as const
