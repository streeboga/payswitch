import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  dashboardNotifications,
  type NotificationListParams,
} from '@/api/endpoints/dashboard-notifications'
import { useContextStore } from '@/stores/context'

const STALE_TIME = 15_000

export function useNotificationsList(params: NotificationListParams = {}) {
  return useQuery({
    queryKey: ['notifications', 'list', params],
    queryFn: () => dashboardNotifications.list(params),
    staleTime: STALE_TIME,
  })
}

export function useMarkNotificationsRead() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (ids: string[]) => dashboardNotifications.markRead(ids),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['notifications'] })
    },
  })
}

export function useMarkAllNotificationsRead() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => dashboardNotifications.markAllRead(),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['notifications'] })
    },
  })
}

export function useUnreadCount() {
  const merchantKey = useContextStore((s) => s.currentMerchantKey)

  return useQuery({
    queryKey: ['notifications', 'unread-count', merchantKey],
    queryFn: () => dashboardNotifications.unreadCount(),
    staleTime: STALE_TIME,
    refetchInterval: 30_000,
    enabled: !!merchantKey,
  })
}

export function useDeleteReadNotifications() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => dashboardNotifications.deleteRead(),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['notifications'] })
    },
  })
}
