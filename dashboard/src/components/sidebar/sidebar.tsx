import { useMemo, useCallback } from 'react'
import { PanelLeftClose, PanelLeft, LogOut, Sun, Moon, Monitor } from 'lucide-react'
import { useNavigate } from '@tanstack/react-router'
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

  const label = t(`settings.theme${currentTheme === 'dark' ? 'Dark' : currentTheme === 'light' ? 'Light' : 'System'}`)

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
    <Button variant="ghost" size="icon" className="size-7" onClick={cycleTheme} aria-label={label}>
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
      return userRoles.some((r) => r.organization_id === currentOrgKey && r.role === 'admin')
    }
    return userRoles.some((r) => r.role === 'admin')
  }, [userRoles, currentOrgKey])
  const clearUser = useAuthStore((s) => s.clearUser)
  const sidebarCollapsed = usePreferencesStore((s) => s.sidebarCollapsed)
  const toggleSidebar = usePreferencesStore((s) => s.toggleSidebar)

  const visibleGroups = useMemo(
    () => getNavGroups(t).filter((group) => !group.adminOnly || isAdmin),
    [t, isAdmin],
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
      <div className="border-border flex items-center justify-between border-b px-2 py-1">
        <TestLiveToggle />
        <NotificationBell />
      </div>

      <div className="border-border border-b">
        <ContextSwitcher />
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

      <div className="border-border border-t px-2 py-3">
        {!sidebarCollapsed && userEmail ? (
          <div className="mb-2 flex items-center gap-2 px-3">
            <span className="text-muted-foreground truncate text-xs">{userEmail}</span>
            <Button
              variant="ghost"
              size="icon"
              className="ml-auto size-7"
              onClick={handleLogout}
              aria-label={t('sidebar.logout')}
            >
              <LogOut className="size-3.5" />
            </Button>
          </div>
        ) : sidebarCollapsed ? (
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                variant="ghost"
                size="icon"
                className="mx-auto flex size-8"
                onClick={handleLogout}
                aria-label={t('sidebar.logout')}
              >
                <LogOut className="size-4" />
              </Button>
            </TooltipTrigger>
            <TooltipContent side="right" sideOffset={8}>
              {t('sidebar.logout')}
            </TooltipContent>
          </Tooltip>
        ) : null}

        <div className={`flex items-center ${sidebarCollapsed ? 'flex-col gap-1' : 'gap-1 px-1'}`}>
          <ThemeToggle collapsed={sidebarCollapsed} />
          <LanguageToggle collapsed={sidebarCollapsed} />
          {!sidebarCollapsed && <div className="flex-1" />}
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
            <Button
              variant="ghost"
              size="sm"
              className="justify-start gap-2"
              onClick={toggleSidebar}
            >
              <PanelLeftClose className="size-4" />
              <span>{t('sidebar.collapseSidebar')}</span>
            </Button>
          )}
        </div>
      </div>
    </aside>
  )
}
