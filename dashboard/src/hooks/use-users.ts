import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  dashboardUsers,
  type UserInviteData,
  type UserUpdateData,
} from '@/api/endpoints/dashboard-users'

const STALE_TIME = 30_000

export function useUsersList() {
  return useQuery({
    queryKey: ['users', 'list'],
    queryFn: () => dashboardUsers.list(),
    staleTime: STALE_TIME,
  })
}

export function useInviteUser() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: UserInviteData) => dashboardUsers.invite(data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['users', 'list'] })
    },
  })
}

export function useUpdateUser() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UserUpdateData }) =>
      dashboardUsers.update(id, data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['users', 'list'] })
    },
  })
}

export function useRemoveUser() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => dashboardUsers.remove(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['users', 'list'] })
    },
  })
}
