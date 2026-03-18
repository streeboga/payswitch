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
import { RoutingRulesPage } from '../routing-rules'

// Mock the API — return a never-resolving promise to keep loading state
vi.mock('@/api/endpoints/dashboard-routing-rules', () => ({
  dashboardRoutingRules: {
    list: vi.fn().mockReturnValue(new Promise(() => {})),
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
  const routingRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/routing',
    component: RoutingRulesPage,
  })
  const routeTree = rootRoute.addChildren([routingRoute])
  const router = createRouter({
    routeTree,
    history: createMemoryHistory({ initialEntries: ['/routing'] }),
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

describe('RoutingRulesPage', () => {
  it('renders page heading', async () => {
    renderWithProviders()
    expect(await screen.findByText('routingRules.title')).toBeDefined()
  })

  it('renders create button', async () => {
    renderWithProviders()
    expect(await screen.findByText('routingRules.createButton')).toBeDefined()
  })
})
