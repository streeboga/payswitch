import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  dashboardDisputes,
  type DisputeListParams,
} from '@/api/endpoints/dashboard-disputes'
import { useContextStore } from '@/stores/context'

const STALE_TIME = 30_000

export function useDisputesList(params: DisputeListParams = {}) {
  const merchantKey = useContextStore((s) => s.currentMerchantKey)
  const testMode = useContextStore((s) => s.testMode)

  return useQuery({
    queryKey: ['disputes', 'list', params, merchantKey, testMode],
    queryFn: () => dashboardDisputes.list(params),
    staleTime: STALE_TIME,
    enabled: !!merchantKey,
  })
}

export function useDisputeDetail(key: string) {
  const merchantKey = useContextStore((s) => s.currentMerchantKey)

  return useQuery({
    queryKey: ['disputes', 'detail', key, merchantKey],
    queryFn: () => dashboardDisputes.get(key),
    staleTime: STALE_TIME,
    enabled: !!merchantKey && !!key,
  })
}

export function useUploadEvidence() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      key,
      data,
    }: {
      key: string
      data: { text?: string; files?: File[] }
    }) => dashboardDisputes.uploadEvidence(key, data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['disputes'] })
    },
  })
}
