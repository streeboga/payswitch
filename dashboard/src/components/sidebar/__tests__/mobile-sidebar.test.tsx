import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest'
import { render, screen, cleanup, fireEvent } from '@testing-library/react'
import type { User } from '@/stores/auth'

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

vi.mock('@/stores/auth', () => ({
  useAuthStore: (selector?: (state: typeof mockAuthStore) => unknown) =>
    selector ? selector(mockAuthStore) : mockAuthStore,
}))

vi.mock('@/api/endpoints/auth', () => ({
  auth: { logout: vi.fn() },
}))

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

vi.mock('@/components/context-switcher/context-switcher', () => ({
  ContextSwitcher: () => <div data-testid="context-switcher" />,
}))

vi.mock('@/components/notifications/notification-bell', () => ({
  NotificationBell: () => <div data-testid="notification-bell" />,
}))

// Mock Sheet to render children directly when open
let sheetOpen = false
vi.mock('@/components/ui/sheet', () => ({
  Sheet: ({
    children,
    open,
  }: {
    children: React.ReactNode
    open: boolean
    onOpenChange: (v: boolean) => void
  }) => {
    sheetOpen = open
    return <>{children}</>
  },
  SheetContent: ({ children }: { children: React.ReactNode }) =>
    sheetOpen ? <div data-testid="sheet-content">{children}</div> : null,
  SheetHeader: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  SheetTitle: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}))

vi.mock('@/components/ui/separator', () => ({
  Separator: () => <hr />,
}))

describe('MobileSidebar', () => {
  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
  })

  beforeEach(() => {
    sheetOpen = false
    mockAuthStore.user = { ...mockUser, role: 'operator' }
  })

  it('renders the hamburger menu button', async () => {
    const { MobileSidebar } = await import('../mobile-sidebar')
    render(<MobileSidebar />)

    expect(screen.getByTestId('mobile-menu-button')).toBeDefined()
    expect(screen.getByLabelText('common.expand')).toBeDefined()
  })

  it('renders notification bell', async () => {
    const { MobileSidebar } = await import('../mobile-sidebar')
    render(<MobileSidebar />)

    expect(screen.getByTestId('notification-bell')).toBeDefined()
  })

  it('opens sheet when hamburger is clicked', async () => {
    const { MobileSidebar } = await import('../mobile-sidebar')
    render(<MobileSidebar />)

    const button = screen.getByTestId('mobile-menu-button')
    fireEvent.click(button)

    expect(screen.getByTestId('sheet-content')).toBeDefined()
  })

  it('shows navigation items when sheet is open', async () => {
    const { MobileSidebar } = await import('../mobile-sidebar')
    render(<MobileSidebar />)

    fireEvent.click(screen.getByTestId('mobile-menu-button'))

    expect(screen.getByText('sidebar.overview')).toBeDefined()
    expect(screen.getByText('sidebar.payments')).toBeDefined()
    expect(screen.getByText('sidebar.refunds')).toBeDefined()
  })

  it('hides admin group for non-admin users', async () => {
    mockAuthStore.user = { ...mockUser, role: 'operator' }
    const { MobileSidebar } = await import('../mobile-sidebar')
    render(<MobileSidebar />)

    fireEvent.click(screen.getByTestId('mobile-menu-button'))

    expect(screen.queryByText('sidebar.management')).toBeNull()
  })

  it('shows admin group for admin users', async () => {
    mockAuthStore.user = { ...mockUser, role: 'admin' }
    const { MobileSidebar } = await import('../mobile-sidebar')
    render(<MobileSidebar />)

    fireEvent.click(screen.getByTestId('mobile-menu-button'))

    expect(screen.getByText('sidebar.management')).toBeDefined()
    expect(screen.getByText('sidebar.organizations')).toBeDefined()
  })

  it('shows logout button when sheet is open', async () => {
    const { MobileSidebar } = await import('../mobile-sidebar')
    render(<MobileSidebar />)

    fireEvent.click(screen.getByTestId('mobile-menu-button'))

    expect(screen.getByText('sidebar.logout')).toBeDefined()
  })

  it('displays user email in sheet header', async () => {
    const { MobileSidebar } = await import('../mobile-sidebar')
    render(<MobileSidebar />)

    fireEvent.click(screen.getByTestId('mobile-menu-button'))

    expect(screen.getByText('test@example.com')).toBeDefined()
  })
})
