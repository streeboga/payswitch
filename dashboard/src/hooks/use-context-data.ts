import { useQuery } from '@tanstack/react-query'
import { dashboardContext } from '@/api/endpoints/dashboard-context'

const STALE_TIME = 60_000

export function useOrganizations() {
  return useQuery({
    queryKey: ['context', 'organizations'],
    queryFn: () => dashboardContext.listOrganizations(),
    staleTime: STALE_TIME,
  })
}

export function useMerchants(orgKey: string | null) {
  return useQuery({
    queryKey: ['context', 'merchants', orgKey],
    queryFn: () => dashboardContext.listMerchants(orgKey!),
    staleTime: STALE_TIME,
    enabled: !!orgKey,
  })
}

export function useProfiles(merchantKey: string | null) {
  return useQuery({
    queryKey: ['context', 'profiles', merchantKey],
    queryFn: () => dashboardContext.listProfiles(merchantKey!),
    staleTime: STALE_TIME,
    enabled: !!merchantKey,
  })
}
