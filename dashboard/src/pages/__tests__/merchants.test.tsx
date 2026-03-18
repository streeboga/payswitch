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
import { MerchantsPage } from '../merchants'

// Mock the API
vi.mock('@/api/endpoints/dashboard-orgs', () => ({
  dashboardOrgs: {
    list: vi.fn().mockReturnValue(new Promise(() => {})),
    get: vi.fn().mockReturnValue(new Promise(() => {})),
    create: vi.fn(),
    updateOrg: vi.fn(),
    deleteOrg: vi.fn(),
    listMerchants: vi.fn().mockReturnValue(new Promise(() => {})),
    getMerchant: vi.fn().mockReturnValue(new Promise(() => {})),
    createMerchant: vi.fn(),
    updateMerchant: vi.fn(),
    deleteMerchant: vi.fn(),
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn().mockReturnValue({
      json: vi.fn().mockReturnValue(new Promise(() => {})),
    }),
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
  const merchantsRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/merchants',
    component: MerchantsPage,
  })
  const routeTree = rootRoute.addChildren([merchantsRoute])
  const router = createRouter({
    routeTree,
    history: createMemoryHistory({ initialEntries: ['/merchants'] }),
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

describe('MerchantsPage', () => {
  it('renders page heading', async () => {
    renderWithProviders()
    expect(await screen.findByText('merchants.title')).toBeDefined()
  })

  it('shows loading skeleton initially', async () => {
    renderWithProviders()
    await screen.findByText('merchants.title')
    const skeletons = document.querySelectorAll('[data-slot="skeleton"]')
    expect(skeletons.length).toBeGreaterThan(0)
  })
})
