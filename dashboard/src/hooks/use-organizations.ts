import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { dashboardOrgs, type OrgListParams } from '@/api/endpoints/dashboard-orgs'
import { useContextStore } from '@/stores/context'

const STALE_TIME = 30_000

export function useOrganizationsList(params: OrgListParams = {}) {
  return useQuery({
    queryKey: ['organizations', 'list', params],
    queryFn: () => dashboardOrgs.list(params),
    staleTime: STALE_TIME,
  })
}

export function useOrganizationDetail(orgKey: string) {
  return useQuery({
    queryKey: ['organizations', 'detail', orgKey],
    queryFn: () => dashboardOrgs.get(orgKey),
    staleTime: STALE_TIME,
    enabled: !!orgKey,
  })
}

export function useOrganizationMerchants(orgKey: string, params: OrgListParams = {}) {
  return useQuery({
    queryKey: ['organizations', 'merchants', orgKey, params],
    queryFn: () => dashboardOrgs.listMerchants(orgKey, params),
    staleTime: STALE_TIME,
    enabled: !!orgKey,
  })
}

export function useCreateOrganization() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: { name: string }) => dashboardOrgs.create(data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['organizations', 'list'] })
    },
  })
}

export function useMerchantDetail(merchantKey: string) {
  const testMode = useContextStore((s) => s.testMode)

  return useQuery({
    queryKey: ['merchants', 'detail', merchantKey, testMode],
    queryFn: () => dashboardOrgs.getMerchant(merchantKey),
    staleTime: STALE_TIME,
    enabled: !!merchantKey,
  })
}

export function useCreateMerchant() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: { name: string; organization_id: string }) =>
      dashboardOrgs.createMerchant(data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['organizations'] })
      void queryClient.invalidateQueries({ queryKey: ['merchants', 'list'] })
    },
  })
}

export function useUpdateOrganization() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ orgKey, data }: { orgKey: string; data: { name: string } }) =>
      dashboardOrgs.updateOrg(orgKey, data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['organizations'] })
    },
  })
}

export function useDeleteOrganization() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (orgKey: string) => dashboardOrgs.deleteOrg(orgKey),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['organizations'] })
    },
  })
}

export function useUpdateMerchant() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ merchantKey, data }: { merchantKey: string; data: { name: string } }) =>
      dashboardOrgs.updateMerchant(merchantKey, data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['merchants'] })
      void queryClient.invalidateQueries({ queryKey: ['organizations'] })
    },
  })
}

export function useDeleteMerchant() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (merchantKey: string) => dashboardOrgs.deleteMerchant(merchantKey),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['merchants'] })
      void queryClient.invalidateQueries({ queryKey: ['organizations'] })
    },
  })
}
