import { getCollection } from '../client'

// ─── Response Types ────────────────────────────────────────

export interface ContextOrganization {
  key: string
  name: string
}

export interface ContextMerchant {
  key: string
  name: string
}

export interface ContextProfile {
  key: string
  name: string
}

interface OrgAttrs {
  name: string
}

interface MerchantAttrs {
  name: string
}

interface ProfileAttrs {
  name: string
}

// ─── API Functions ─────────────────────────────────────────

export const dashboardContext = {
  async listOrganizations(): Promise<ContextOrganization[]> {
    const doc = await getCollection<OrgAttrs>('dashboard/organizations', {
      searchParams: { 'page[size]': '100' },
    })
    return doc.data.map((r) => ({ key: r.id, name: r.attributes.name }))
  },

  async listMerchants(orgKey: string): Promise<ContextMerchant[]> {
    const doc = await getCollection<MerchantAttrs>(
      `dashboard/organizations/${orgKey}/merchants`,
      { searchParams: { 'page[size]': '100' } },
    )
    return doc.data.map((r) => ({ key: r.id, name: r.attributes.name }))
  },

  async listProfiles(merchantKey: string): Promise<ContextProfile[]> {
    const doc = await getCollection<ProfileAttrs>(
      `dashboard/merchants/${merchantKey}/profiles`,
      { searchParams: { 'page[size]': '100' } },
    )
    return doc.data.map((r) => ({ key: r.id, name: r.attributes.name }))
  },
} as const
