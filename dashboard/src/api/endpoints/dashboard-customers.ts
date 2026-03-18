import { api, getCollection, createResource, updateResource } from '../client'
import type {
  JsonApiResource,
  CustomerAttributes,
  PaymentMethodAttributes,
  PaymentIntentAttributes,
  PaginatedResult,
} from '../types'
import { parseCollection, buildJsonApiParams, extractAttributes } from '../types'

// ─── Filter Params ──────────────────────────────────────────

export interface CustomerListParams {
  search?: string
  sort?: string
  direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

// ─── Response Types ─────────────────────────────────────────

export interface CustomerDetailResponse {
  data: JsonApiResource<CustomerAttributes>
  included?: JsonApiResource[]
}

export interface CustomerDetail {
  customer: CustomerAttributes & { id: string }
  paymentMethods: (PaymentMethodAttributes & { id: string })[]
  payments: (PaymentIntentAttributes & { id: string })[]
}

// ─── Helpers ────────────────────────────────────────────────

function parseDetailResponse(response: CustomerDetailResponse): CustomerDetail {
  const customer = {
    ...response.data.attributes,
    id: response.data.id,
  }

  const paymentMethods: (PaymentMethodAttributes & { id: string })[] = []
  const payments: (PaymentIntentAttributes & { id: string })[] = []

  for (const resource of response.included ?? []) {
    if (resource.type === 'payment-methods' || resource.type === 'payment_methods') {
      paymentMethods.push({
        ...(resource.attributes as unknown as PaymentMethodAttributes),
        id: resource.id,
      })
    } else if (
      resource.type === 'payments' ||
      resource.type === 'payment_intents' ||
      resource.type === 'payment-intents'
    ) {
      payments.push({
        ...(resource.attributes as unknown as PaymentIntentAttributes),
        id: resource.id,
      })
    }
  }

  return { customer, paymentMethods, payments }
}

// ─── Create / Update Attrs ──────────────────────────────────

export interface CustomerCreateAttrs {
  name: string
  email?: string
  phone?: string
  phone_country_code?: string
  description?: string
  metadata?: Record<string, unknown>
}

export type CustomerUpdateAttrs = Partial<CustomerCreateAttrs>

// ─── API Functions ──────────────────────────────────────────

export const dashboardCustomers = {
  async list(
    params: CustomerListParams = {},
  ): Promise<PaginatedResult<CustomerAttributes>> {
    const doc = await getCollection<CustomerAttributes>('dashboard/customers', {
      searchParams: buildJsonApiParams(params),
    })
    return parseCollection(doc)
  },

  get(customerKey: string): Promise<CustomerDetail> {
    return api
      .get(`dashboard/customers/${customerKey}`, {
        searchParams: { include: 'payment_methods,payments' },
      })
      .json<CustomerDetailResponse>()
      .then(parseDetailResponse)
  },

  async create(attrs: CustomerCreateAttrs) {
    const doc = await createResource<CustomerAttributes>('dashboard/customers', attrs)
    return extractAttributes(doc.data)
  },

  async update(customerKey: string, attrs: CustomerUpdateAttrs) {
    const doc = await updateResource<CustomerAttributes>(
      `dashboard/customers/${customerKey}`,
      attrs,
    )
    return extractAttributes(doc.data)
  },

  remove(customerKey: string) {
    return api.delete(`dashboard/customers/${customerKey}`)
  },
} as const
