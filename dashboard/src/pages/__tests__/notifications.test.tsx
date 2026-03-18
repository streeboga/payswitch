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
import { NotificationsPage } from '../notifications'

// Mock the API
vi.mock('@/api/endpoints/dashboard-notifications', () => ({
  dashboardNotifications: {
    list: vi.fn().mockReturnValue(new Promise(() => {})),
    markRead: vi.fn(),
    markAllRead: vi.fn(),
    deleteRead: vi.fn(),
  },
}))

// Mock preferences store
vi.mock('@/stores/preferences', () => ({
  usePreferencesStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({
      density: 'comfortable',
    }),
}))

function renderWithProviders() {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
    },
  })

  const rootRoute = createRootRoute()
  const notificationsRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/notifications',
    component: NotificationsPage,
  })
  const routeTree = rootRoute.addChildren([notificationsRoute])
  const router = createRouter({
    routeTree,
    history: createMemoryHistory({ initialEntries: ['/notifications'] }),
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

describe('NotificationsPage', () => {
  it('renders page heading', async () => {
    renderWithProviders()
    expect(await screen.findByText('notifications.title')).toBeDefined()
  })

  it('shows loading skeleton initially', async () => {
    renderWithProviders()
    await screen.findByText('notifications.title')
    const skeletons = document.querySelectorAll('[data-slot="skeleton"]')
    expect(skeletons.length).toBeGreaterThan(0)
  })
})
