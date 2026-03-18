import {
  createResource,
  getCollection,
  getResource,
  updateResource,
  deleteResource,
} from '../client'
import type { CustomerAttributes } from '../types'
import type { ListParams } from './params'
import { buildSearchParams } from './params'

export const customers = {
  list(params?: ListParams) {
    return getCollection<CustomerAttributes>('customers', {
      searchParams: buildSearchParams(params),
    })
  },

  get(customerKey: string) {
    return getResource<CustomerAttributes>(`customers/${customerKey}`)
  },

  create(attrs: {
    name: string
    email?: string
    phone?: string
    phone_country_code?: string
    description?: string
    metadata?: Record<string, unknown>
  }) {
    return createResource<CustomerAttributes>('customers', attrs)
  },

  update(
    customerKey: string,
    attrs: Partial<{
      name: string
      email: string
      phone: string
      phone_country_code: string
      description: string
      metadata: Record<string, unknown>
    }>,
  ) {
    return updateResource<CustomerAttributes>(`customers/${customerKey}`, attrs)
  },

  delete(customerKey: string) {
    return deleteResource(`customers/${customerKey}`)
  },
} as const
