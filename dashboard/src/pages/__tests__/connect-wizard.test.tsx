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
import { ConnectWizard } from '@/components/connectors/connect-wizard'

// Mock the API
vi.mock('@/api/endpoints/dashboard-connectors', () => ({
  dashboardConnectors: {
    list: vi.fn().mockReturnValue(new Promise(() => {})),
    connectable: vi.fn().mockResolvedValue(['cloudpayments', 'test', 'test_sbp']),
    get: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
    testConnection: vi.fn(),
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

function WizardWrapper() {
  return <ConnectWizard open={true} onOpenChange={() => {}} />
}

function renderWithProviders() {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
    },
  })

  const rootRoute = createRootRoute()
  const wizardRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/wizard',
    component: WizardWrapper,
  })
  const routeTree = rootRoute.addChildren([wizardRoute])
  const router = createRouter({
    routeTree,
    history: createMemoryHistory({ initialEntries: ['/wizard'] }),
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

describe('ConnectWizard', () => {
  it('renders step 1 — only connectable connectors', async () => {
    renderWithProviders()
    expect(await screen.findByText('connectWizard.title')).toBeDefined()
    expect(await screen.findByText('CloudPayments')).toBeDefined()
    expect(await screen.findByText('Test')).toBeDefined()
    expect(screen.queryByText('Stripe')).toBeNull()
  })

  it('renders step indicator', async () => {
    renderWithProviders()
    // Step 1 should be active — the number "1" is displayed
    expect(await screen.findByText('1')).toBeDefined()
  })
})
