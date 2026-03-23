import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  dashboardConnectors,
  type ConnectorCreateAttrs,
  type ConnectorUpdateAttrs,
  type ConnectorCapabilitiesData,
} from '@/api/endpoints/dashboard-connectors'
import { useContextStore } from '@/stores/context'

const STALE_TIME = 30_000

export function useConnectorsList() {
  const merchantKey = useContextStore((s) => s.currentMerchantKey)
  const testMode = useContextStore((s) => s.testMode)

  return useQuery({
    queryKey: ['connectors', 'list', merchantKey, testMode],
    queryFn: () => dashboardConnectors.list(),
    staleTime: STALE_TIME,
    enabled: !!merchantKey,
  })
}

export function useConnector(key: string) {
  const merchantKey = useContextStore((s) => s.currentMerchantKey)
  const testMode = useContextStore((s) => s.testMode)

  return useQuery({
    queryKey: ['connectors', 'detail', key, merchantKey, testMode],
    queryFn: () => dashboardConnectors.get(key),
    staleTime: STALE_TIME,
    enabled: !!merchantKey && !!key,
  })
}

export function useCreateConnector() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (attrs: ConnectorCreateAttrs) => dashboardConnectors.create(attrs),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['connectors', 'list'] })
    },
  })
}

export function useUpdateConnector() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ key, attrs }: { key: string; attrs: ConnectorUpdateAttrs }) =>
      dashboardConnectors.update(key, attrs),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['connectors'] })
    },
  })
}

export function useDeleteConnector() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (key: string) => dashboardConnectors.remove(key),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['connectors', 'list'] })
    },
  })
}

export function useTestConnection() {
  return useMutation({
    mutationFn: (key: string) => dashboardConnectors.testConnection(key),
  })
}

export function useConnectorCapabilities(key: string) {
  const merchantKey = useContextStore((s) => s.currentMerchantKey)

  return useQuery({
    queryKey: ['connectors', 'capabilities', key, merchantKey],
    queryFn: () => dashboardConnectors.getCapabilities(key),
    staleTime: 60_000,
    enabled: !!merchantKey && !!key,
  })
}

export type { ConnectorCapabilitiesData }
