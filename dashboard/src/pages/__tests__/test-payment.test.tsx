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
import { TestPaymentPage } from '../test-payment'

// Mock the API
vi.mock('@/api/endpoints/dashboard-test-payment', () => ({
  dashboardTestPayment: {
    create: vi.fn().mockReturnValue(new Promise(() => {})),
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

// Mock connectors hook
vi.mock('@/hooks/use-connectors', () => ({
  useConnectorsList: () => ({
    data: { items: [] },
    isLoading: false,
  }),
}))

function renderWithProviders() {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
    },
  })

  const rootRoute = createRootRoute()
  const testPaymentRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/test-payment',
    component: TestPaymentPage,
  })
  const routeTree = rootRoute.addChildren([testPaymentRoute])
  const router = createRouter({
    routeTree,
    history: createMemoryHistory({ initialEntries: ['/test-payment'] }),
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

describe('TestPaymentPage', () => {
  it('renders form', async () => {
    renderWithProviders()
    expect(await screen.findByRole('heading', { name: 'testPayment.title' })).toBeDefined()
    expect(screen.getByText('testPayment.sendButton')).toBeDefined()
  })

  it('shows presets', async () => {
    renderWithProviders()
    await screen.findByRole('heading', { name: 'testPayment.title' })
    expect(screen.getByText('testPayment.presetSuccessful')).toBeDefined()
    expect(screen.getByText('testPayment.presetDecline')).toBeDefined()
    expect(screen.getByText('testPayment.preset3ds')).toBeDefined()
  })
})
