import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  dashboardProfiles,
  type ProfileListParams,
} from '@/api/endpoints/dashboard-profiles'
import { useContextStore } from '@/stores/context'

const STALE_TIME = 30_000

export function useProfilesList(params: ProfileListParams = {}) {
  const merchantKey = useContextStore((s) => s.currentMerchantKey)
  const testMode = useContextStore((s) => s.testMode)

  return useQuery({
    queryKey: ['profiles', 'list', params, merchantKey, testMode],
    queryFn: () => dashboardProfiles.list(params),
    staleTime: STALE_TIME,
    enabled: !!merchantKey,
  })
}

export function useProfileDetail(profileKey: string) {
  const merchantKey = useContextStore((s) => s.currentMerchantKey)
  const testMode = useContextStore((s) => s.testMode)

  return useQuery({
    queryKey: ['profiles', 'detail', profileKey, merchantKey, testMode],
    queryFn: () => dashboardProfiles.get(profileKey),
    staleTime: STALE_TIME,
    enabled: !!merchantKey && !!profileKey,
  })
}

export function useCreateProfile() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: { webhook_url?: string }) => dashboardProfiles.create(data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['profiles', 'list'] })
    },
  })
}

export function useUpdateProfile() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ key, data }: { key: string; data: { webhook_url?: string } }) =>
      dashboardProfiles.update(key, data),
    onSuccess: (_data, variables) => {
      void queryClient.invalidateQueries({ queryKey: ['profiles', 'list'] })
      void queryClient.invalidateQueries({
        queryKey: ['profiles', 'detail', variables.key],
      })
    },
  })
}
