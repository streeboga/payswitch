import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  dashboardRoutingRules,
  type RoutingRuleListParams,
  type RoutingRuleCreateAttrs,
  type RoutingRuleUpdateAttrs,
} from '@/api/endpoints/dashboard-routing-rules'
import { useContextStore } from '@/stores/context'

const STALE_TIME = 30_000

export function useRoutingRulesList(params: RoutingRuleListParams = {}) {
  const merchantKey = useContextStore((s) => s.currentMerchantKey)
  const testMode = useContextStore((s) => s.testMode)

  return useQuery({
    queryKey: ['routing-rules', 'list', params, merchantKey, testMode],
    queryFn: () => dashboardRoutingRules.list(params),
    staleTime: STALE_TIME,
    enabled: !!merchantKey,
  })
}

export function useCreateRoutingRule() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (attrs: RoutingRuleCreateAttrs) => dashboardRoutingRules.create(attrs),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['routing-rules', 'list'] })
    },
  })
}

export function useUpdateRoutingRule() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ key, attrs }: { key: string; attrs: RoutingRuleUpdateAttrs }) =>
      dashboardRoutingRules.update(key, attrs),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['routing-rules'] })
    },
  })
}

export function useDeleteRoutingRule() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (key: string) => dashboardRoutingRules.remove(key),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['routing-rules', 'list'] })
    },
  })
}
