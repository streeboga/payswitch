import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  dashboardApiKeys,
  type ApiKeyCreateAttrs,
} from '@/api/endpoints/dashboard-api-keys'
import { useContextStore } from '@/stores/context'

const STALE_TIME = 30_000

export function useApiKeysList() {
  const merchantKey = useContextStore((s) => s.currentMerchantKey)
  const testMode = useContextStore((s) => s.testMode)

  return useQuery({
    queryKey: ['api-keys', 'list', merchantKey, testMode],
    queryFn: () => dashboardApiKeys.list(),
    staleTime: STALE_TIME,
    enabled: !!merchantKey,
  })
}

export function useCreateApiKey() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (attrs: ApiKeyCreateAttrs) => dashboardApiKeys.create(attrs),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['api-keys', 'list'] })
    },
  })
}

export function useRevokeApiKey() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (keyId: string) => dashboardApiKeys.revoke(keyId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['api-keys', 'list'] })
    },
  })
}
