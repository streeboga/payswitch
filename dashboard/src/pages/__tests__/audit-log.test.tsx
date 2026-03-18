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
import { AuditLogPage } from '../audit-log'

// Mock the API
vi.mock('@/api/endpoints/dashboard-audit-log', () => ({
  dashboardAuditLog: {
    list: vi.fn().mockReturnValue(new Promise(() => {})),
    exportCsv: vi.fn(),
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
  const auditLogRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/audit-log',
    component: AuditLogPage,
  })
  const routeTree = rootRoute.addChildren([auditLogRoute])
  const router = createRouter({
    routeTree,
    history: createMemoryHistory({ initialEntries: ['/audit-log'] }),
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

describe('AuditLogPage', () => {
  it('renders page heading', async () => {
    renderWithProviders()
    expect(await screen.findByText('auditLog.title')).toBeDefined()
  })

  it('shows loading skeleton initially', async () => {
    renderWithProviders()
    await screen.findByText('auditLog.title')
    const skeletons = document.querySelectorAll('[data-slot="skeleton"]')
    expect(skeletons.length).toBeGreaterThan(0)
  })
})
