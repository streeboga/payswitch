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
import { CustomerDetailPage } from '../customer-detail'

// Mock the API — return a never-resolving promise to keep loading state
vi.mock('@/api/endpoints/dashboard-customers', () => ({
  dashboardCustomers: {
    list: vi.fn().mockReturnValue(new Promise(() => {})),
    get: vi.fn().mockReturnValue(new Promise(() => {})),
    create: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
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

function renderWithProviders() {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
    },
  })

  const rootRoute = createRootRoute()
  const customerDetailRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/customers/$customerKey',
    component: CustomerDetailPage,
  })
  const routeTree = rootRoute.addChildren([customerDetailRoute])
  const router = createRouter({
    routeTree,
    history: createMemoryHistory({
      initialEntries: ['/customers/cus_test_123'],
    }),
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

describe('CustomerDetailPage', () => {
  it('renders customer ID heading', async () => {
    // Override mock to return actual data
    const { dashboardCustomers } = await import('@/api/endpoints/dashboard-customers')
    vi.mocked(dashboardCustomers.get).mockResolvedValueOnce({
      customer: {
        id: 'cus_test_123',
        name: 'Test Customer',
        email: 'test@example.com',
        phone: null,
        phone_country_code: null,
        description: null,
        metadata: null,
        default_payment_method_id: null,
        created_at: '2026-03-18T10:00:00Z',
      },
      paymentMethods: [],
      payments: [],
    })

    renderWithProviders()
    expect(await screen.findByText('cus_test_123')).toBeDefined()
  })

  it('shows loading state', async () => {
    renderWithProviders()
    await screen.findByText('customerDetail.backToCustomers')
    const skeletons = document.querySelectorAll('[data-slot="skeleton"]')
    expect(skeletons.length).toBeGreaterThan(0)
  })
})
