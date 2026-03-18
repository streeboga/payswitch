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
import { PaymentDetailPage } from '../payment-detail'

// Mock the API — return a never-resolving promise to keep loading state
vi.mock('@/api/endpoints/dashboard-payment-detail', () => ({
  dashboardPaymentDetail: {
    get: vi.fn().mockReturnValue(new Promise(() => {})),
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
  const paymentDetailRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/payments/$paymentKey',
    component: PaymentDetailPage,
  })
  const routeTree = rootRoute.addChildren([paymentDetailRoute])
  const router = createRouter({
    routeTree,
    history: createMemoryHistory({
      initialEntries: ['/payments/pay_test_123'],
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

describe('PaymentDetailPage', () => {
  it('renders payment ID heading', async () => {
    // Override mock to return actual data
    const { dashboardPaymentDetail } =
      await import('@/api/endpoints/dashboard-payment-detail')
    vi.mocked(dashboardPaymentDetail.get).mockResolvedValueOnce({
      payment: {
        id: 'pay_test_123',
        status: 'succeeded',
        amount: 10000,
        net_amount: 10000,
        amount_capturable: 0,
        amount_received: 10000,
        currency: 'USD',
        client_secret: 'cs_test_secret',
        capture_method: 'automatic',
        authentication_type: 'no_three_ds',
        customer_id: null,
        description: null,
        return_url: null,
        metadata: null,
        connector: 'stripe',
        attempt_count: 1,
        error_code: null,
        error_message: null,
        cancellation_reason: null,
        session_expiry: null,
        created_at: '2026-03-18T10:00:00Z',
        expires_on: null,
      },
      attempts: [],
      refunds: [],
    })

    renderWithProviders()
    expect(await screen.findByText('pay_test_123')).toBeDefined()
  })

  it('shows loading state', async () => {
    renderWithProviders()
    // Wait for router to render the page, then check for skeletons
    await screen.findByText('paymentDetail.backToPayments')
    const skeletons = document.querySelectorAll('[data-slot="skeleton"]')
    expect(skeletons.length).toBeGreaterThan(0)
  })
})
