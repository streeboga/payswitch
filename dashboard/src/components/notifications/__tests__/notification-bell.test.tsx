import { describe, it, expect, afterEach, vi } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'

// ─── Mocks ───────────────────────────────────────────────────

let mockUnreadCount = 5

vi.mock('@/hooks/use-notifications', () => ({
  useUnreadCount: () => ({
    data: { count: mockUnreadCount },
    isLoading: false,
  }),
}))

// Mock Popover to avoid Radix portal issues in tests
vi.mock('@/components/ui/popover', () => ({
  Popover: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  PopoverTrigger: ({
    children,
    asChild: _asChild,
    ...props
  }: {
    children: React.ReactNode
    asChild?: boolean
  }) => <div {...props}>{children}</div>,
  PopoverContent: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}))

// Mock NotificationPanel to keep tests focused
vi.mock('../notification-panel', () => ({
  NotificationPanel: () => <div data-testid="notification-panel" />,
}))

// ─── Tests ───────────────────────────────────────────────────

describe('NotificationBell', () => {
  afterEach(() => {
    cleanup()
    mockUnreadCount = 5
  })

  it('renders bell icon', async () => {
    const { NotificationBell } = await import('../notification-bell')
    render(<NotificationBell />)

    expect(screen.getByTestId('notification-bell')).toBeDefined()
  })

  it('shows badge with unread count', async () => {
    mockUnreadCount = 3
    const { NotificationBell } = await import('../notification-bell')
    render(<NotificationBell />)

    const badge = screen.getByTestId('notification-badge')
    expect(badge).toBeDefined()
    expect(badge.textContent).toBe('3')
  })

  it('hides badge when count is 0', async () => {
    mockUnreadCount = 0
    const { NotificationBell } = await import('../notification-bell')
    render(<NotificationBell />)

    expect(screen.queryByTestId('notification-badge')).toBeNull()
  })

  it('shows 99+ for large counts', async () => {
    mockUnreadCount = 150
    const { NotificationBell } = await import('../notification-bell')
    render(<NotificationBell />)

    const badge = screen.getByTestId('notification-badge')
    expect(badge.textContent).toBe('99+')
  })
})
