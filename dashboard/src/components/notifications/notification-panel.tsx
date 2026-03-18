import { useCallback } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useTranslation } from 'react-i18next'
import {
  AlertTriangle,
  CheckCircle,
  ExternalLink,
  Info,
  ShieldAlert,
  Webhook,
  XCircle,
} from 'lucide-react'

import {
  useNotificationsList,
  useMarkNotificationsRead,
  useMarkAllNotificationsRead,
} from '@/hooks/use-notifications'
import { DateFormat } from '@/components/shared/date-format'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import { Skeleton } from '@/components/ui/skeleton'

// ─── Type Icons ──────────────────────────────────────────────

function NotificationIcon({ type }: { type: string }) {
  switch (type) {
    case 'dispute':
      return <ShieldAlert className="h-4 w-4 shrink-0 text-orange-500" />
    case 'error':
    case 'payment_failed':
    case 'refund_failed':
      return <XCircle className="h-4 w-4 shrink-0 text-red-500" />
    case 'warning':
    case 'alert':
      return <AlertTriangle className="h-4 w-4 shrink-0 text-yellow-500" />
    case 'success':
    case 'payment_succeeded':
    case 'refund_succeeded':
      return <CheckCircle className="h-4 w-4 shrink-0 text-green-500" />
    case 'webhook':
      return <Webhook className="h-4 w-4 shrink-0 text-blue-500" />
    default:
      return <Info className="text-muted-foreground h-4 w-4 shrink-0" />
  }
}

// ─── Resource Navigation ─────────────────────────────────────

function getResourcePath(
  resourceType: string | null,
  resourceId: string | null,
): string | null {
  if (!resourceType || !resourceId) return null

  if (resourceType === 'payment') return `/payments/${resourceId}`
  if (resourceType === 'customer') return `/customers/${resourceId}`
  if (resourceType === 'connector') return `/connectors/${resourceId}`
  if (resourceType === 'refund') return '/refunds'

  return null
}

// ─── Loading Skeleton ────────────────────────────────────────

function NotificationSkeleton() {
  return (
    <div className="space-y-3 p-3">
      {Array.from({ length: 3 }).map((_, i) => (
        <div key={i} className="flex items-start gap-3">
          <Skeleton className="h-4 w-4 shrink-0 rounded-full" />
          <div className="flex-1 space-y-1.5">
            <Skeleton className="h-3.5 w-3/4" />
            <Skeleton className="h-3 w-1/3" />
          </div>
        </div>
      ))}
    </div>
  )
}

// ─── Component ───────────────────────────────────────────────

interface NotificationPanelProps {
  onClose: () => void
}

export function NotificationPanel({ onClose }: NotificationPanelProps) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { data, isLoading } = useNotificationsList({ per_page: 10 })
  const markRead = useMarkNotificationsRead()
  const markAllRead = useMarkAllNotificationsRead()

  const notifications = data?.items ?? []

  const hasUnread = notifications.some((n) => !n.read_at)

  const handleClick = useCallback(
    (notification: (typeof notifications)[number]) => {
      if (!notification.read_at) {
        markRead.mutate([notification.id])
      }

      const path = getResourcePath(notification.resource_type, notification.resource_id)
      if (path) {
        void navigate({ to: path })
      }

      onClose()
    },
    [markRead, navigate, onClose],
  )

  const handleMarkAllRead = useCallback(() => {
    markAllRead.mutate()
  }, [markAllRead])

  const handleViewAll = useCallback(() => {
    void navigate({ to: '/notifications' })
    onClose()
  }, [navigate, onClose])

  return (
    <div className="flex w-80 flex-col">
      {/* Header */}
      <div className="flex items-center justify-between px-3 pb-2">
        <h3 className="text-sm font-semibold">{t('notifications.title')}</h3>
        {hasUnread && (
          <Button
            variant="ghost"
            size="sm"
            className="text-muted-foreground h-auto px-1 py-0.5 text-xs"
            onClick={handleMarkAllRead}
            disabled={markAllRead.isPending}
          >
            {t('notifications.markAllRead')}
          </Button>
        )}
      </div>

      <Separator />

      {/* Content */}
      {isLoading ? (
        <NotificationSkeleton />
      ) : notifications.length === 0 ? (
        <div className="text-muted-foreground py-8 text-center text-sm">
          {t('notifications.noNotifications')}
        </div>
      ) : (
        <div className="max-h-80 overflow-y-auto">
          {notifications.map((notification) => (
            <button
              key={notification.id}
              type="button"
              className="hover:bg-accent flex w-full items-start gap-3 px-3 py-2.5 text-left transition-colors"
              onClick={() => handleClick(notification)}
            >
              <NotificationIcon type={notification.type} />
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{notification.title}</p>
                <DateFormat
                  date={notification.created_at}
                  variant="relative"
                  className="text-muted-foreground text-xs"
                />
              </div>
              {!notification.read_at && (
                <span className="bg-primary mt-1.5 h-2 w-2 shrink-0 rounded-full" />
              )}
            </button>
          ))}
        </div>
      )}

      <Separator />

      {/* Footer */}
      <button
        type="button"
        className="text-muted-foreground hover:text-foreground flex items-center justify-center gap-1 py-2.5 text-xs transition-colors"
        onClick={handleViewAll}
      >
        {t('notifications.viewAll')}
        <ExternalLink className="h-3 w-3" />
      </button>
    </div>
  )
}
