import { describe, it, expect, afterEach, vi } from 'vitest'
import { render, cleanup } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import {
  createRouter,
  createRootRoute,
  createRoute,
  RouterProvider,
  createMemoryHistory,
} from '@tanstack/react-router'
import { TooltipProvider } from '@/components/ui/tooltip'
import { ConnectorHealthPage } from '../connector-health'

// Mock the API — return a never-resolving promise to keep loading state
vi.mock('@/api/endpoints/dashboard-connector-health', () => ({
  dashboardConnectorHealth: {
    getHealth: vi.fn().mockReturnValue(new Promise(() => {})),
    getHealthHistory: vi.fn().mockReturnValue(new Promise(() => {})),
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
  const healthRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/connectors/$connectorKey/health',
    component: ConnectorHealthPage,
  })
  const routeTree = rootRoute.addChildren([healthRoute])
  const router = createRouter({
    routeTree,
    history: createMemoryHistory({
      initialEntries: ['/connectors/conn_stripe/health'],
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

describe('ConnectorHealthPage', () => {
  it('renders loading skeleton initially', async () => {
    const { container } = renderWithProviders()
    // Wait for router to settle then check skeletons are present
    await vi.waitFor(() => {
      const skeletons = container.querySelectorAll('[data-slot="skeleton"]')
      expect(skeletons.length).toBeGreaterThan(0)
    })
  })
})
