import { describe, it, expect, afterEach, vi } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import {
  createRouter,
  createRootRoute,
  createRoute,
  RouterProvider,
  createMemoryHistory,
} from '@tanstack/react-router'
import { TooltipProvider } from '@/components/ui/tooltip'
import { SettingsPage } from '../settings'

// Mock auth store
vi.mock('@/stores/auth', () => ({
  useAuthStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({
      user: { id: 1, name: 'Test', email: 'test@example.com', two_factor_enabled: false },
    }),
}))

// Mock preferences store
vi.mock('@/stores/preferences', () => ({
  usePreferencesStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({
      theme: 'system',
      density: 'comfortable',
      timezone: 'Europe/Moscow',
      setTheme: vi.fn(),
      setDensity: vi.fn(),
      setTimezone: vi.fn(),
    }),
}))

function renderWithProviders() {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
    },
  })

  const rootRoute = createRootRoute()
  const settingsRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/settings',
    component: SettingsPage,
  })
  const routeTree = rootRoute.addChildren([settingsRoute])
  const router = createRouter({
    routeTree,
    history: createMemoryHistory({ initialEntries: ['/settings'] }),
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <TooltipProvider>
        <RouterProvider router={router} />
      </TooltipProvider>
    </QueryClientProvider>,
  )
}

afterEach(cleanup)

describe('SettingsPage', () => {
  it('renders page heading', async () => {
    renderWithProviders()
    expect(await screen.findByText('settings.title')).toBeDefined()
  })

  it('renders all tab triggers', async () => {
    renderWithProviders()
    await screen.findByText('settings.title')
    expect(screen.getByRole('tab', { name: /settings\.tabProfile/i })).toBeDefined()
    expect(screen.getByRole('tab', { name: /settings\.tabSecurity/i })).toBeDefined()
    expect(screen.getByRole('tab', { name: /settings\.tabAppearance/i })).toBeDefined()
    expect(screen.getByRole('tab', { name: /settings\.tabRegional/i })).toBeDefined()
    expect(screen.getByRole('tab', { name: /settings\.tabNotifications/i })).toBeDefined()
  })
})
