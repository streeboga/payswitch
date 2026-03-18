import { getCollection, getResource, createResource, updateResource, deleteResource } from '../client'
import type {
  OrganizationAttributes,
  MerchantAccountAttributes,
  PaginatedResult,
} from '../types'
import { parseCollection, buildJsonApiParams, extractAttributes } from '../types'

// ─── Filter Params ──────────────────────────────────────────

export interface OrgListParams {
  search?: string
  sort?: string
  direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

// ─── API Functions ──────────────────────────────────────────

export const dashboardOrgs = {
  async list(
    params: OrgListParams = {},
  ): Promise<PaginatedResult<OrganizationAttributes & { merchants_count: number }>> {
    const doc = await getCollection<OrganizationAttributes & { merchants_count: number }>(
      'dashboard/organizations',
      {
        searchParams: buildJsonApiParams(params),
      },
    )
    return parseCollection(doc)
  },

  async get(orgKey: string) {
    const doc = await getResource<OrganizationAttributes & { merchants_count: number }>(
      `dashboard/organizations/${orgKey}`,
    )
    return extractAttributes(doc.data)
  },

  async create(data: { name: string }) {
    const doc = await createResource<OrganizationAttributes>('dashboard/organizations', data)
    return extractAttributes(doc.data)
  },

  async listMerchants(
    orgKey: string,
    params: OrgListParams = {},
  ): Promise<PaginatedResult<MerchantAccountAttributes>> {
    const doc = await getCollection<MerchantAccountAttributes>(
      `dashboard/organizations/${orgKey}/merchants`,
      { searchParams: buildJsonApiParams(params) },
    )
    return parseCollection(doc)
  },

  async getMerchant(merchantKey: string) {
    const doc = await getResource<
      MerchantAccountAttributes & { profiles_count: number; connectors_count: number }
    >(`dashboard/merchants/${merchantKey}`)
    return extractAttributes(doc.data)
  },

  async createMerchant(data: { name: string; organization_id: string }) {
    const doc = await createResource<MerchantAccountAttributes>('dashboard/merchants', data)
    return extractAttributes(doc.data)
  },

  async updateOrg(orgKey: string, data: { name: string }) {
    const doc = await updateResource<OrganizationAttributes>(
      `dashboard/organizations/${orgKey}`,
      data,
    )
    return extractAttributes(doc.data)
  },

  async deleteOrg(orgKey: string) {
    await deleteResource(`dashboard/organizations/${orgKey}`)
  },

  async updateMerchant(merchantKey: string, data: { name: string }) {
    const doc = await updateResource<MerchantAccountAttributes>(
      `dashboard/merchants/${merchantKey}`,
      data,
    )
    return extractAttributes(doc.data)
  },

  async deleteMerchant(merchantKey: string) {
    await deleteResource(`dashboard/merchants/${merchantKey}`)
  },
} as const
