import {
  useQuery,
  useMutation,
  useQueryClient,
  type UseQueryOptions,
  type UseMutationOptions,
} from '@tanstack/react-query'
import type { JsonApiDocument, JsonApiCollectionDocument } from '@/api/types'

// ─── Query Key Factory ───────────────────────────────────────

export const queryKeys = {
  all: ['api'] as const,

  organizations: {
    all: ['api', 'organizations'] as const,
    detail: (key: string) => ['api', 'organizations', key] as const,
  },

  merchants: {
    all: ['api', 'merchants'] as const,
    detail: (key: string) => ['api', 'merchants', key] as const,
  },

  profiles: {
    all: ['api', 'profiles'] as const,
    detail: (key: string) => ['api', 'profiles', key] as const,
  },

  apiKeys: {
    all: (merchantKey: string) => ['api', 'api-keys', merchantKey] as const,
  },

  connectors: {
    all: (merchantKey: string) => ['api', 'connectors', merchantKey] as const,
    detail: (merchantKey: string, connectorKey: string) =>
      ['api', 'connectors', merchantKey, connectorKey] as const,
  },

  routingRules: {
    all: (merchantKey: string) => ['api', 'routing-rules', merchantKey] as const,
    detail: (merchantKey: string, ruleKey: string) =>
      ['api', 'routing-rules', merchantKey, ruleKey] as const,
  },

  payments: {
    all: ['api', 'payments'] as const,
    detail: (key: string) => ['api', 'payments', key] as const,
  },

  refunds: {
    all: ['api', 'refunds'] as const,
    detail: (key: string) => ['api', 'refunds', key] as const,
  },

  customers: {
    all: ['api', 'customers'] as const,
    detail: (key: string) => ['api', 'customers', key] as const,
  },

  paymentMethods: {
    all: (customerKey: string) => ['api', 'payment-methods', customerKey] as const,
    detail: (pmKey: string) => ['api', 'payment-methods', 'detail', pmKey] as const,
  },

  auth: {
    user: ['api', 'auth', 'user'] as const,
  },
} as const

// ─── Generic Hooks ───────────────────────────────────────────

/** Generic hook for fetching a single JSON:API resource */
export function useJsonApiResource<T>(
  queryKey: readonly unknown[],
  fetcher: () => Promise<JsonApiDocument<T>>,
  options?: Omit<UseQueryOptions<JsonApiDocument<T>>, 'queryKey' | 'queryFn'>,
) {
  return useQuery({
    queryKey,
    queryFn: fetcher,
    ...options,
  })
}

/** Generic hook for fetching a JSON:API collection */
export function useJsonApiCollection<T>(
  queryKey: readonly unknown[],
  fetcher: () => Promise<JsonApiCollectionDocument<T>>,
  options?: Omit<UseQueryOptions<JsonApiCollectionDocument<T>>, 'queryKey' | 'queryFn'>,
) {
  return useQuery({
    queryKey,
    queryFn: fetcher,
    ...options,
  })
}

/** Generic mutation that invalidates related queries on success */
export function useJsonApiMutation<TData, TVariables>(
  mutationFn: (variables: TVariables) => Promise<TData>,
  invalidateKeys?: readonly unknown[][],
  options?: Omit<UseMutationOptions<TData, Error, TVariables>, 'mutationFn'>,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn,
    onSuccess: async (...args) => {
      if (invalidateKeys) {
        await Promise.all(
          invalidateKeys.map((key) => queryClient.invalidateQueries({ queryKey: key })),
        )
      }
      options?.onSuccess?.(...args)
    },
    ...options,
  })
}
