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
import { OrganizationsPage } from '../organizations'

// Mock the API — return a never-resolving promise to keep loading state
vi.mock('@/api/endpoints/dashboard-orgs', () => ({
  dashboardOrgs: {
    list: vi.fn().mockReturnValue(new Promise(() => {})),
    get: vi.fn().mockReturnValue(new Promise(() => {})),
    create: vi.fn(),
    listMerchants: vi.fn().mockReturnValue(new Promise(() => {})),
    getMerchant: vi.fn().mockReturnValue(new Promise(() => {})),
    createMerchant: vi.fn(),
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
  const orgsRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/organizations',
    component: OrganizationsPage,
  })
  const routeTree = rootRoute.addChildren([orgsRoute])
  const router = createRouter({
    routeTree,
    history: createMemoryHistory({ initialEntries: ['/organizations'] }),
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

describe('OrganizationsPage', () => {
  it('renders page heading', async () => {
    renderWithProviders()
    expect(await screen.findByText('organizations.title')).toBeDefined()
  })

  it('shows loading skeleton initially', async () => {
    renderWithProviders()
    await screen.findByText('organizations.title')
    const skeletons = document.querySelectorAll('[data-slot="skeleton"]')
    expect(skeletons.length).toBeGreaterThan(0)
  })
})
