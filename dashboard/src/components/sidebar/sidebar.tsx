import { useMemo, useCallback } from 'react'
import {
  PanelLeftClose,
  PanelLeft,
  LogOut,
  Sun,
  Moon,
  Monitor,
  Settings,
  Bell,
  User,
} from 'lucide-react'
import { useNavigate, Link } from '@tanstack/react-router'
import { useTranslation } from 'react-i18next'
import { useTheme } from 'next-themes'
import { useAuthStore } from '@/stores/auth'
import { useContextStore } from '@/stores/context'
import { usePreferencesStore } from '@/stores/preferences'
import { auth } from '@/api/endpoints/auth'
import { supportedLanguages } from '@/lib/i18n'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { getNavGroups } from './nav-config'
import { NavItem } from './nav-item'
import { TestLiveToggle } from './test-live-toggle'
import { ContextSwitcher } from '@/components/context-switcher/context-switcher'
import { NotificationBell } from '@/components/notifications/notification-bell'

const THEME_CYCLE = ['light', 'dark', 'system'] as const

function ThemeToggle({ collapsed }: { collapsed: boolean }) {
  const { theme, setTheme } = useTheme()
  const { t } = useTranslation()

  const currentTheme = (theme ?? 'system') as (typeof THEME_CYCLE)[number]

  const cycleTheme = () => {
    const idx = THEME_CYCLE.indexOf(currentTheme)
    setTheme(THEME_CYCLE[(idx + 1) % THEME_CYCLE.length]!)
  }

  const icon =
    currentTheme === 'dark' ? (
      <Moon className="size-3.5" />
    ) : currentTheme === 'light' ? (
      <Sun className="size-3.5" />
    ) : (
      <Monitor className="size-3.5" />
    )

  const label = t(
    `settings.theme${currentTheme === 'dark' ? 'Dark' : currentTheme === 'light' ? 'Light' : 'System'}`,
  )

  if (collapsed) {
    return (
      <Tooltip>
        <TooltipTrigger asChild>
          <Button
            variant="ghost"
            size="icon"
            className="mx-auto flex size-8"
            onClick={cycleTheme}
            aria-label={label}
          >
            {icon}
          </Button>
        </TooltipTrigger>
        <TooltipContent side="right" sideOffset={8}>
          {label}
        </TooltipContent>
      </Tooltip>
    )
  }

  return (
    <Button
      variant="ghost"
      size="icon"
      className="size-7"
      onClick={cycleTheme}
      aria-label={label}
    >
      {icon}
    </Button>
  )
}

function LanguageToggle({ collapsed }: { collapsed: boolean }) {
  const { i18n } = useTranslation()

  const cycleLanguage = () => {
    const codes = supportedLanguages.map((l) => l.code) as string[]
    const idx = codes.indexOf(i18n.language)
    void i18n.changeLanguage(codes[(idx + 1) % codes.length]!)
  }

  const current = supportedLanguages.find((l) => l.code === i18n.language)
  const label = current?.label ?? i18n.language.toUpperCase()
  const code = i18n.language.toUpperCase()

  if (collapsed) {
    return (
      <Tooltip>
        <TooltipTrigger asChild>
          <Button
            variant="ghost"
            size="icon"
            className="mx-auto flex size-8 text-xs font-semibold"
            onClick={cycleLanguage}
            aria-label={label}
          >
            {code}
          </Button>
        </TooltipTrigger>
        <TooltipContent side="right" sideOffset={8}>
          {label}
        </TooltipContent>
      </Tooltip>
    )
  }

  return (
    <Button
      variant="ghost"
      size="icon"
      className="size-7 text-xs font-semibold"
      onClick={cycleLanguage}
      aria-label={label}
    >
      {code}
    </Button>
  )
}

export function Sidebar() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const userEmail = useAuthStore((s) => s.user?.email)
  const userRoles = useAuthStore((s) => s.user?.roles)
  const currentOrgKey = useContextStore((s) => s.currentOrgKey)
  const isAdmin = useMemo(() => {
    if (!userRoles?.length) return false
    if (currentOrgKey) {
      return userRoles.some(
        (r) => r.organization_id === currentOrgKey && r.role === 'admin',
      )
    }
    return userRoles.some((r) => r.role === 'admin')
  }, [userRoles, currentOrgKey])
  const clearUser = useAuthStore((s) => s.clearUser)
  const sidebarCollapsed = usePreferencesStore((s) => s.sidebarCollapsed)
  const toggleSidebar = usePreferencesStore((s) => s.toggleSidebar)

  const testMode = useContextStore((s) => s.testMode)

  const visibleGroups = useMemo(
    () =>
      getNavGroups(t)
        .filter((group) => !group.adminOnly || isAdmin)
        .map((group) => ({
          ...group,
          items: group.items.filter((item) => !item.testOnly || testMode),
        }))
        .filter((group) => group.items.length > 0),
    [t, isAdmin, testMode],
  )

  const handleLogout = useCallback(async () => {
    await auth.logout()
    clearUser()
    await navigate({ to: '/login' })
  }, [clearUser, navigate])

  return (
    <aside
      data-testid="sidebar"
      className={`border-border bg-background hidden flex-col border-r md:flex ${
        sidebarCollapsed ? 'w-16' : 'w-60'
      }`}
    >
      <div className="border-border flex items-center gap-1 border-b px-2 py-1.5">
        <ContextSwitcher />
        <TestLiveToggle />
        <div className="flex-1" />
        <NotificationBell />
      </div>

      <nav className="flex-1 overflow-y-auto px-2 py-4">
        {visibleGroups.map((group, groupIndex) => (
          <div key={group.label}>
            {groupIndex > 0 && <Separator className="my-2" />}
            {!sidebarCollapsed && (
              <span className="text-muted-foreground mb-1 block px-3 text-xs font-semibold tracking-wider uppercase">
                {group.label}
              </span>
            )}
            <div className="space-y-0.5">
              {group.items.map((item) => (
                <NavItem key={item.path} item={item} collapsed={sidebarCollapsed} />
              ))}
            </div>
          </div>
        ))}
      </nav>

      <div className="border-border border-t px-2 py-2">
        <div
          className={`flex items-center ${sidebarCollapsed ? 'flex-col gap-1' : 'gap-1'}`}
        >
          <Popover>
            <Tooltip>
              <TooltipTrigger asChild>
                <PopoverTrigger asChild>
                  {sidebarCollapsed ? (
                    <Button
                      variant="ghost"
                      size="icon"
                      className="mx-auto flex size-8"
                      aria-label={userEmail ?? t('sidebar.account')}
                    >
                      <User className="size-4" />
                    </Button>
                  ) : (
                    <button className="hover:bg-accent flex min-w-0 flex-1 items-center gap-2 rounded-md px-2 py-1.5 transition-colors">
                      <User className="text-muted-foreground size-3.5 shrink-0" />
                      <span className="text-muted-foreground truncate text-xs">
                        {userEmail}
                      </span>
                    </button>
                  )}
                </PopoverTrigger>
              </TooltipTrigger>
              {sidebarCollapsed && (
                <TooltipContent side="right" sideOffset={8}>
                  {userEmail ?? t('sidebar.account')}
                </TooltipContent>
              )}
            </Tooltip>

            <PopoverContent
              side={sidebarCollapsed ? 'right' : 'top'}
              align="start"
              sideOffset={8}
              className="w-48 p-1"
            >
              <Link
                to="/settings"
                className="text-muted-foreground hover:bg-accent hover:text-foreground flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-xs transition-colors"
              >
                <Settings className="size-3.5" />
                {t('sidebar.settings')}
              </Link>
              <Link
                to="/notifications"
                className="text-muted-foreground hover:bg-accent hover:text-foreground flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-xs transition-colors"
              >
                <Bell className="size-3.5" />
                {t('sidebar.notifications')}
              </Link>
              <Separator className="my-1" />
              <button
                onClick={handleLogout}
                className="text-muted-foreground hover:bg-accent hover:text-foreground flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-xs transition-colors"
              >
                <LogOut className="size-3.5" />
                {t('sidebar.logout')}
              </button>
            </PopoverContent>
          </Popover>

          <ThemeToggle collapsed={sidebarCollapsed} />
          <LanguageToggle collapsed={sidebarCollapsed} />
          {sidebarCollapsed ? (
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  variant="ghost"
                  size="icon"
                  className="mx-auto flex size-8"
                  onClick={toggleSidebar}
                  aria-label={t('sidebar.expandSidebar')}
                >
                  <PanelLeft className="size-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent side="right" sideOffset={8}>
                {t('sidebar.expandSidebar')}
              </TooltipContent>
            </Tooltip>
          ) : (
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  variant="ghost"
                  size="icon"
                  className="size-7"
                  onClick={toggleSidebar}
                  aria-label={t('sidebar.collapseSidebar')}
                >
                  <PanelLeftClose className="size-3.5" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>{t('sidebar.collapseSidebar')}</TooltipContent>
            </Tooltip>
          )}
        </div>
      </div>
    </aside>
  )
}
