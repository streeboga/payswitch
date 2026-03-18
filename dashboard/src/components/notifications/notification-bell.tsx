import { lazy, Suspense, useState } from 'react'
import { Bell } from 'lucide-react'
import { useTranslation } from 'react-i18next'

import { useUnreadCount } from '@/hooks/use-notifications'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'

const NotificationPanel = lazy(() =>
  import('./notification-panel').then((m) => ({ default: m.NotificationPanel })),
)

export function NotificationBell() {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const { data } = useUnreadCount()
  const count = data?.count ?? 0

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          className="relative size-8"
          aria-label={t('notifications.title')}
          data-testid="notification-bell"
        >
          <Bell className="size-4" />
          {count > 0 && (
            <Badge
              variant="destructive"
              className="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center px-1 text-[10px]"
              data-testid="notification-badge"
            >
              {count > 99 ? '99+' : count}
            </Badge>
          )}
        </Button>
      </PopoverTrigger>
      <PopoverContent align="end" className="w-auto p-2">
        <Suspense fallback={null}>
          <NotificationPanel onClose={() => setOpen(false)} />
        </Suspense>
      </PopoverContent>
    </Popover>
  )
}
