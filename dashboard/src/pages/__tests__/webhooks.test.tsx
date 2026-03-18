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
import { WebhooksPage } from '../webhooks'

// Mock the API — return a never-resolving promise to keep loading state
vi.mock('@/api/endpoints/dashboard-webhooks', () => ({
  dashboardWebhooks: {
    list: vi.fn().mockReturnValue(new Promise(() => {})),
    retry: vi.fn().mockReturnValue(new Promise(() => {})),
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
  const webhooksRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/webhooks',
    component: WebhooksPage,
  })
  const routeTree = rootRoute.addChildren([webhooksRoute])
  const router = createRouter({
    routeTree,
    history: createMemoryHistory({ initialEntries: ['/webhooks'] }),
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

describe('WebhooksPage', () => {
  it('renders page heading', async () => {
    renderWithProviders()
    expect(await screen.findByText('webhooks.title')).toBeDefined()
  })

  it('shows loading skeleton initially', async () => {
    renderWithProviders()
    // Wait for router to render the page, then check for skeletons
    await screen.findByText('webhooks.title')
    const skeletons = document.querySelectorAll('[data-slot="skeleton"]')
    expect(skeletons.length).toBeGreaterThan(0)
  })
})
