import { createResource, getCollection, getResource, deleteResource } from '../client'
import { api } from '../client'
import type { PaymentMethodAttributes, JsonApiDocument } from '../types'
import type { ListParams } from './params'
import { buildSearchParams } from './params'

export const paymentMethods = {
  list(customerKey: string, params?: ListParams) {
    return getCollection<PaymentMethodAttributes>(
      `customers/${customerKey}/payment-methods`,
      { searchParams: buildSearchParams(params) },
    )
  },

  get(pmKey: string) {
    return getResource<PaymentMethodAttributes>(`payment-methods/${pmKey}`)
  },

  create(
    customerKey: string,
    attrs: {
      type: string
      card_last4?: string
      card_brand?: string
      card_exp_month?: number
      card_exp_year?: number
      card_holder_name?: string
      connector_name: string
      connector_token: string
      metadata?: Record<string, unknown>
    },
  ) {
    return createResource<PaymentMethodAttributes>(
      `customers/${customerKey}/payment-methods`,
      attrs,
    )
  },

  delete(pmKey: string) {
    return deleteResource(`payment-methods/${pmKey}`)
  },

  setDefault(pmKey: string) {
    return api
      .post(`payment-methods/${pmKey}/default`)
      .json<JsonApiDocument<PaymentMethodAttributes>>()
  },
} as const
