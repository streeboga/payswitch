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
import { ConnectorsPage } from '../connectors'

// Mock the API — return a never-resolving promise to keep loading state
vi.mock('@/api/endpoints/dashboard-connectors', () => ({
  dashboardConnectors: {
    list: vi.fn().mockReturnValue(new Promise(() => {})),
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

function renderWithProviders() {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
    },
  })

  const rootRoute = createRootRoute()
  const connectorsRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/connectors',
    component: ConnectorsPage,
  })
  const routeTree = rootRoute.addChildren([connectorsRoute])
  const router = createRouter({
    routeTree,
    history: createMemoryHistory({ initialEntries: ['/connectors'] }),
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

describe('ConnectorsPage', () => {
  it('renders page heading', async () => {
    renderWithProviders()
    expect(await screen.findByText('connectors.title')).toBeDefined()
  })

  it('renders connect button', async () => {
    renderWithProviders()
    expect(await screen.findByText('connectors.connectButton')).toBeDefined()
  })
})
