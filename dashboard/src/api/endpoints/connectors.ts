import {
  createResource,
  getCollection,
  getResource,
  updateResource,
  deleteResource,
} from '../client'
import type { ConnectorAttributes } from '../types'
import type { ListParams } from './params'
import { buildSearchParams } from './params'

export const connectors = {
  list(merchantKey: string, params?: ListParams) {
    return getCollection<ConnectorAttributes>(`merchants/${merchantKey}/connectors`, {
      searchParams: buildSearchParams(params),
    })
  },

  get(merchantKey: string, connectorKey: string) {
    return getResource<ConnectorAttributes>(
      `merchants/${merchantKey}/connectors/${connectorKey}`,
    )
  },

  create(
    merchantKey: string,
    attrs: {
      connector_name: string
      business_profile_id: string
      connector_account_details: Record<string, unknown>
      payment_methods_enabled?: string[]
      test_mode?: boolean
    },
  ) {
    return createResource<ConnectorAttributes>(
      `merchants/${merchantKey}/connectors`,
      attrs,
    )
  },

  update(
    merchantKey: string,
    connectorKey: string,
    attrs: Partial<{
      connector_account_details: Record<string, unknown>
      payment_methods_enabled: string[]
      disabled: boolean
    }>,
  ) {
    return updateResource<ConnectorAttributes>(
      `merchants/${merchantKey}/connectors/${connectorKey}`,
      attrs,
    )
  },

  delete(merchantKey: string, connectorKey: string) {
    return deleteResource(`merchants/${merchantKey}/connectors/${connectorKey}`)
  },
} as const
