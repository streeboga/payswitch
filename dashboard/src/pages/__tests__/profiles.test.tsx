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
import { ProfilesPage } from '../profiles'

// Mock the API
vi.mock('@/api/endpoints/dashboard-profiles', () => ({
  dashboardProfiles: {
    list: vi.fn().mockReturnValue(new Promise(() => {})),
    get: vi.fn().mockReturnValue(new Promise(() => {})),
    create: vi.fn(),
    update: vi.fn(),
  },
}))

// Mock context store
vi.mock('@/stores/context', () => ({
  useContextStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({
      currentMerchantKey: 'mch_test',
      testMode: true,
    }),
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
  const profilesRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/profiles',
    component: ProfilesPage,
  })
  const routeTree = rootRoute.addChildren([profilesRoute])
  const router = createRouter({
    routeTree,
    history: createMemoryHistory({ initialEntries: ['/profiles'] }),
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

describe('ProfilesPage', () => {
  it('renders page heading', async () => {
    renderWithProviders()
    expect(await screen.findByText('profiles.title')).toBeDefined()
  })

  it('shows loading skeleton initially', async () => {
    renderWithProviders()
    await screen.findByText('profiles.title')
    const skeletons = document.querySelectorAll('[data-slot="skeleton"]')
    expect(skeletons.length).toBeGreaterThan(0)
  })
})
