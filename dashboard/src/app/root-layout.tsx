import { lazy, Suspense } from 'react'
import { Outlet, useMatches } from '@tanstack/react-router'
import { useTranslation } from 'react-i18next'
import { useAuthStore } from '@/stores/auth'
import { TooltipProvider } from '@/components/ui/tooltip'
import { useNavigationShortcuts } from '@/hooks/use-navigation-shortcuts'
import { Breadcrumbs } from '@/components/shared/breadcrumbs'
import { useBreadcrumbs } from '@/hooks/use-breadcrumbs'
import { Sidebar } from '@/components/sidebar/sidebar'
import { MobileSidebar } from '@/components/sidebar/mobile-sidebar'

const CommandPalette = lazy(() =>
  import('@/components/shared/command-palette').then((m) => ({
    default: m.CommandPalette,
  })),
)

const ShortcutsHelp = lazy(() =>
  import('@/components/shared/shortcuts-help').then((m) => ({
    default: m.ShortcutsHelp,
  })),
)

export function RootLayout() {
  const { t } = useTranslation()
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)
  const user = useAuthStore((s) => s.user)
  const matches = useMatches()

  // Register keyboard shortcuts
  useNavigationShortcuts()

  const breadcrumbs = useBreadcrumbs()

  // Don't show header on login/2fa pages
  const isAuthPage = matches.some(
    (m) => m.pathname === '/login' || m.pathname === '/two-factor-challenge',
  )

  const showChrome = isAuthenticated && !isAuthPage

  return (
    <TooltipProvider>
      <div className="bg-background text-foreground flex min-h-screen flex-col">
        {showChrome && (
          <header className="border-border flex items-center border-b px-4 py-2 md:hidden">
            <MobileSidebar />
            <span className="text-muted-foreground ml-2 truncate text-sm">
              {user?.email}
            </span>
          </header>
        )}
        <div className="flex flex-1">
          {showChrome && <Sidebar />}
          <main className="flex-1 overflow-y-auto p-6">
            {showChrome && <Breadcrumbs items={breadcrumbs} className="mb-4" />}
            <Suspense fallback={<div className="flex h-64 items-center justify-center"><span className="text-muted-foreground text-sm">{t('common.loading')}</span></div>}>
              <Outlet />
            </Suspense>
          </main>
        </div>
      </div>
      <Suspense fallback={null}>
        <ShortcutsHelp />
      </Suspense>
      <Suspense fallback={null}>
        <CommandPalette />
      </Suspense>
    </TooltipProvider>
  )
}
