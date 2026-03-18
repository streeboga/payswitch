import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  dashboardCustomers,
  type CustomerListParams,
  type CustomerCreateAttrs,
  type CustomerUpdateAttrs,
} from '@/api/endpoints/dashboard-customers'
import { useContextStore } from '@/stores/context'

const STALE_TIME = 30_000

export function useCustomersList(params: CustomerListParams = {}) {
  const merchantKey = useContextStore((s) => s.currentMerchantKey)
  const testMode = useContextStore((s) => s.testMode)

  return useQuery({
    queryKey: ['customers', 'list', params, merchantKey, testMode],
    queryFn: () => dashboardCustomers.list(params),
    staleTime: STALE_TIME,
    enabled: !!merchantKey,
  })
}

export function useCustomerDetail(customerKey: string) {
  const merchantKey = useContextStore((s) => s.currentMerchantKey)
  const testMode = useContextStore((s) => s.testMode)

  return useQuery({
    queryKey: ['customers', 'detail', customerKey, merchantKey, testMode],
    queryFn: () => dashboardCustomers.get(customerKey),
    staleTime: STALE_TIME,
    enabled: !!merchantKey && !!customerKey,
  })
}

export function useCreateCustomer() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (attrs: CustomerCreateAttrs) => dashboardCustomers.create(attrs),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['customers', 'list'] })
    },
  })
}

export function useUpdateCustomer() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ key, attrs }: { key: string; attrs: CustomerUpdateAttrs }) =>
      dashboardCustomers.update(key, attrs),
    onSuccess: (_data, variables) => {
      void queryClient.invalidateQueries({ queryKey: ['customers', 'list'] })
      void queryClient.invalidateQueries({
        queryKey: ['customers', 'detail', variables.key],
      })
    },
  })
}

export function useDeleteCustomer() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (key: string) => dashboardCustomers.remove(key),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['customers', 'list'] })
    },
  })
}
