import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  dashboardWebhooks,
  type WebhookListParams,
} from '@/api/endpoints/dashboard-webhooks'
import { useContextStore } from '@/stores/context'

const STALE_TIME = 30_000

export function useWebhooksList(params: WebhookListParams = {}) {
  const merchantKey = useContextStore((s) => s.currentMerchantKey)
  const testMode = useContextStore((s) => s.testMode)

  return useQuery({
    queryKey: ['webhooks', 'list', params, merchantKey, testMode],
    queryFn: () => dashboardWebhooks.list(params),
    staleTime: STALE_TIME,
    enabled: !!merchantKey,
  })
}

export function useRetryWebhook() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => dashboardWebhooks.retry(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['webhooks', 'list'] })
    },
  })
}
