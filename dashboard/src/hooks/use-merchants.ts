import { useQuery } from '@tanstack/react-query'
import { type OrgListParams } from '@/api/endpoints/dashboard-orgs'
import { getCollection } from '@/api/client'
import type { MerchantAccountAttributes, PaginatedResult } from '@/api/types'
import { parseCollection, buildJsonApiParams } from '@/api/types'
import { useContextStore } from '@/stores/context'

const STALE_TIME = 30_000

type MerchantListItem = MerchantAccountAttributes & {
  profiles_count: number
  connectors_count: number
}

export function useMerchantsList(params: OrgListParams = {}) {
  const testMode = useContextStore((s) => s.testMode)

  return useQuery({
    queryKey: ['merchants', 'list', params, testMode],
    queryFn: async (): Promise<PaginatedResult<MerchantListItem>> => {
      const doc = await getCollection<MerchantListItem>('dashboard/merchants', {
        searchParams: buildJsonApiParams(params),
      })
      return parseCollection(doc)
    },
    staleTime: STALE_TIME,
  })
}

export { useMerchantDetail, useCreateMerchant, useUpdateMerchant, useDeleteMerchant } from './use-organizations'
