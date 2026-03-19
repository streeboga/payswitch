import { describe, it, expect, afterEach, vi, beforeEach } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'
import type { User } from '@/stores/auth'
import type { TFunction } from 'i18next'
import { getNavGroups } from '../nav-config'

const mockT = ((key: string) => key) as TFunction
const navGroups = getNavGroups(mockT)

// Mock stores
const mockUser: User = {
  id: 1,
  name: 'Test',
  email: 'test@example.com',
  email_verified_at: '2024-01-01',
  role: 'operator',
}

const mockAuthStore: { user: User | null; [key: string]: unknown } = {
  user: mockUser,
  isAuthenticated: true,
  isLoading: false,
  requiresTwoFactor: false,
  setUser: vi.fn(),
  setLoading: vi.fn(),
  clearUser: vi.fn(),
  setRequiresTwoFactor: vi.fn(),
}

const mockPreferencesStore = {
  density: 'comfortable' as const,
  timezone: 'Europe/Moscow',
  sidebarCollapsed: false,
  setDensity: vi.fn(),
  setTimezone: vi.fn(),
  toggleSidebar: vi.fn(),
}

vi.mock('@/stores/auth', () => ({
  useAuthStore: (selector?: (state: typeof mockAuthStore) => unknown) =>
    selector ? selector(mockAuthStore) : mockAuthStore,
}))

vi.mock('@/stores/preferences', () => ({
  usePreferencesStore: (selector?: (state: typeof mockPreferencesStore) => unknown) =>
    selector ? selector(mockPreferencesStore) : mockPreferencesStore,
}))

vi.mock('@/api/endpoints/auth', () => ({
  auth: { logout: vi.fn() },
}))

// Mock TanStack Router
vi.mock('@tanstack/react-router', () => ({
  useMatches: () => [{ pathname: '/overview' }],
  useNavigate: () => vi.fn(),
  Link: ({
    children,
    to,
    className,
    ...props
  }: {
    children: React.ReactNode
    to: string
    className?: string
    onClick?: () => void
  }) => (
    <a href={to} className={className} {...props}>
      {children}
    </a>
  ),
}))

// Mock ContextSwitcher to avoid needing QueryClientProvider
vi.mock('@/components/context-switcher/context-switcher', () => ({
  ContextSwitcher: () => <div data-testid="context-switcher" />,
}))

vi.mock('../test-live-toggle', () => ({
  TestLiveToggle: () => <div data-testid="test-live-toggle" />,
}))

// Mock NotificationBell to avoid needing QueryClientProvider
vi.mock('@/components/notifications/notification-bell', () => ({
  NotificationBell: () => <div data-testid="notification-bell" />,
}))

// Mock Tooltip to avoid Radix portal issues in tests
vi.mock('@/components/ui/tooltip', () => ({
  Tooltip: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  TooltipTrigger: ({
    children,
    asChild: _asChild,
    ...props
  }: {
    children: React.ReactNode
    asChild?: boolean
  }) => <div {...props}>{children}</div>,
  TooltipContent: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  TooltipProvider: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}))

describe('Sidebar', () => {
  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
  })

  beforeEach(() => {
    mockAuthStore.user = { ...mockUser, role: 'operator' }
    mockPreferencesStore.sidebarCollapsed = false
  })

  it('renders all non-admin navigation groups', async () => {
    const { Sidebar } = await import('../sidebar')
    render(<Sidebar />)

    const nonAdminGroups = navGroups.filter((g) => !g.adminOnly)
    for (const group of nonAdminGroups) {
      expect(screen.getByText(group.label)).toBeDefined()
    }
  })

  it('renders all nav items in non-admin groups', async () => {
    const { Sidebar } = await import('../sidebar')
    render(<Sidebar />)

    const nonAdminItems = navGroups.filter((g) => !g.adminOnly).flatMap((g) => g.items)

    for (const item of nonAdminItems) {
      expect(screen.getByText(item.label)).toBeDefined()
    }
  })

  it('hides admin group for non-admin users', async () => {
    mockAuthStore.user = { ...mockUser, role: 'operator' }
    const { Sidebar } = await import('../sidebar')
    render(<Sidebar />)

    expect(screen.queryByText('sidebar.management')).toBeNull()
  })

  it('shows admin group for admin users', async () => {
    mockAuthStore.user = {
      ...mockUser,
      role: 'admin',
      roles: [{ organization_id: 'org1', role: 'admin' }],
    }
    const { Sidebar } = await import('../sidebar')
    render(<Sidebar />)

    expect(screen.getByText('sidebar.management')).toBeDefined()
    expect(screen.getByText('sidebar.organizations')).toBeDefined()
    expect(screen.getByText('sidebar.merchants')).toBeDefined()
    expect(screen.getByText('sidebar.auditLog')).toBeDefined()
  })

  it('collapsed state hides group labels', async () => {
    mockPreferencesStore.sidebarCollapsed = true
    const { Sidebar } = await import('../sidebar')
    render(<Sidebar />)

    // Group labels should not be present when collapsed
    expect(screen.queryByText('sidebar.operations')).toBeNull()
    expect(screen.queryByText('sidebar.configuration')).toBeNull()
  })

  it('highlights active item based on current path', async () => {
    const { Sidebar } = await import('../sidebar')
    render(<Sidebar />)

    // The link to /overview should have the active class
    const overviewLink = screen.getByText('sidebar.overview').closest('a')
    expect(overviewLink?.className).toContain('bg-accent')
  })

  it('displays user email in expanded state', async () => {
    const { Sidebar } = await import('../sidebar')
    render(<Sidebar />)

    expect(screen.getByText('test@example.com')).toBeDefined()
  })
})

describe('nav-config', () => {
  it('has 4 navigation groups', () => {
    expect(navGroups).toHaveLength(4)
  })

  it('marks admin group with adminOnly flag', () => {
    const adminGroup = navGroups.find((g) => g.label === 'sidebar.management')
    expect(adminGroup?.adminOnly).toBe(true)
  })

  it('all items have required fields', () => {
    for (const group of navGroups) {
      for (const item of group.items) {
        expect(item.label).toBeTruthy()
        expect(item.path).toMatch(/^\//)
        expect(item.icon).toBeDefined()
      }
    }
  })
})
