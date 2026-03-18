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
import { OnboardingPage } from '../onboarding'

// Mock the connectors hook
vi.mock('@/hooks/use-connectors', () => ({
  useCreateConnector: () => ({
    mutate: vi.fn(),
    isPending: false,
  }),
}))

// Mock context store
vi.mock('@/stores/context', () => ({
  useContextStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({
      currentMerchantKey: 'mch_test',
      testMode: true,
    }),
}))

// Mock test payment API
vi.mock('@/api/endpoints/dashboard-test-payment', () => ({
  dashboardTestPayment: {
    create: vi.fn().mockReturnValue(new Promise(() => {})),
  },
}))

function renderWithProviders() {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
    },
  })

  const rootRoute = createRootRoute()
  const onboardingRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/onboarding',
    component: OnboardingPage,
  })
  const routeTree = rootRoute.addChildren([onboardingRoute])
  const router = createRouter({
    routeTree,
    history: createMemoryHistory({ initialEntries: ['/onboarding'] }),
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

describe('OnboardingPage', () => {
  it('renders step 1', async () => {
    renderWithProviders()
    expect(await screen.findByText('onboarding.title')).toBeDefined()
    expect(await screen.findByText('onboarding.step1Desc')).toBeDefined()
    expect(screen.getByLabelText('onboarding.orgNameLabel')).toBeDefined()
  })
})
