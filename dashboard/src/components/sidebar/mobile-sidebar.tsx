import { useState, useCallback, useMemo } from 'react'
import { Menu, LogOut } from 'lucide-react'
import { useNavigate, useMatches } from '@tanstack/react-router'
import { Link } from '@tanstack/react-router'
import { useAuthStore } from '@/stores/auth'
import { useContextStore } from '@/stores/context'
import { auth } from '@/api/endpoints/auth'
import { Button } from '@/components/ui/button'
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Separator } from '@/components/ui/separator'
import { Badge } from '@/components/ui/badge'
import { useTranslation } from 'react-i18next'
import { getNavGroups } from './nav-config'
import { TestLiveToggle } from './test-live-toggle'
import { ContextSwitcher } from '@/components/context-switcher/context-switcher'
import { NotificationBell } from '@/components/notifications/notification-bell'

export function MobileSidebar() {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const navigate = useNavigate()
  const matches = useMatches()
  const userEmail = useAuthStore((s) => s.user?.email)
  const userRoles = useAuthStore((s) => s.user?.roles)
  const currentOrgKey = useContextStore((s) => s.currentOrgKey)
  const isAdmin = useMemo(() => {
    if (!userRoles?.length) return false
    if (currentOrgKey) {
      return userRoles.some((r) => r.organization_id === currentOrgKey && r.role === 'admin')
    }
    return userRoles.some((r) => r.role === 'admin')
  }, [userRoles, currentOrgKey])
  const clearUser = useAuthStore((s) => s.clearUser)

  const visibleGroups = useMemo(
    () => getNavGroups(t).filter((group) => !group.adminOnly || isAdmin),
    [t, isAdmin],
  )

  const handleLogout = useCallback(async () => {
    setOpen(false)
    await auth.logout()
    clearUser()
    await navigate({ to: '/login' })
  }, [clearUser, navigate])

  return (
    <div className="block md:hidden">
      <div className="flex items-center gap-1">
        <NotificationBell />
        <Button
          variant="ghost"
          size="icon"
          onClick={() => setOpen(true)}
          aria-label={t('common.expand')}
          data-testid="mobile-menu-button"
        >
          <Menu className="size-5" />
        </Button>
      </div>
      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent side="left" className="w-72 p-0">
          <SheetHeader className="border-border border-b p-4">
            <SheetTitle className="text-sm">{userEmail ?? t('sidebar.account')}</SheetTitle>
          </SheetHeader>
          <div className="border-border border-b px-2 py-1">
            <TestLiveToggle />
          </div>
          <div className="border-border border-b">
            <ContextSwitcher />
          </div>
          <nav className="flex-1 overflow-y-auto px-2 py-4">
            {visibleGroups.map((group, groupIndex) => (
              <div key={group.label}>
                {groupIndex > 0 && <Separator className="my-2" />}
                <span className="text-muted-foreground mb-1 block px-3 text-xs font-semibold tracking-wider uppercase">
                  {group.label}
                </span>
                <div className="space-y-0.5">
                  {group.items.map((item) => {
                    const isActive = matches.some((m) => m.pathname === item.path)
                    const Icon = item.icon
                    return (
                      <Link
                        key={item.path}
                        to={item.path}
                        onClick={() => setOpen(false)}
                        className={`flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                          isActive
                            ? 'bg-accent text-accent-foreground'
                            : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground'
                        }`}
                        aria-current={isActive ? 'page' : undefined}
                      >
                        <Icon className="size-4 shrink-0" />
                        <span className="flex-1 truncate">{item.label}</span>
                        {item.badge != null && item.badge > 0 ? (
                          <Badge variant="secondary" className="ml-auto h-5 min-w-5 px-1">
                            {item.badge}
                          </Badge>
                        ) : null}
                      </Link>
                    )
                  })}
                </div>
              </div>
            ))}
          </nav>
          <div className="border-border border-t p-3">
            <Button
              variant="ghost"
              size="sm"
              className="w-full justify-start gap-2"
              onClick={handleLogout}
            >
              <LogOut className="size-4" />
              <span>{t('sidebar.logout')}</span>
            </Button>
          </div>
        </SheetContent>
      </Sheet>
    </div>
  )
}
