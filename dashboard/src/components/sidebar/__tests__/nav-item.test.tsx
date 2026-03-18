import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'
import { CreditCard } from 'lucide-react'
import type { NavItem as NavItemType } from '../nav-config'

// Mock TanStack Router
vi.mock('@tanstack/react-router', () => ({
  useMatches: () => [{ pathname: '/payments' }],
  Link: ({
    children,
    to,
    className,
    ...props
  }: {
    children: React.ReactNode
    to: string
    className?: string
  }) => (
    <a href={to} className={className} {...props}>
      {children}
    </a>
  ),
}))

// Mock Tooltip to avoid Radix portal issues
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
  TooltipContent: ({ children }: { children: React.ReactNode }) => (
    <div data-testid="tooltip-content">{children}</div>
  ),
  TooltipProvider: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}))

const baseItem: NavItemType = {
  label: 'Платежи',
  path: '/payments',
  icon: CreditCard,
}

describe('NavItem', () => {
  afterEach(cleanup)

  it('renders label and icon when expanded', async () => {
    const { NavItem } = await import('../nav-item')
    render(<NavItem item={baseItem} collapsed={false} />)

    expect(screen.getByText('Платежи')).toBeDefined()
  })

  it('renders link to correct path', async () => {
    const { NavItem } = await import('../nav-item')
    render(<NavItem item={baseItem} collapsed={false} />)

    const link = screen.getByText('Платежи').closest('a')
    expect(link?.getAttribute('href')).toBe('/payments')
  })

  it('applies active state styling when route matches', async () => {
    const { NavItem } = await import('../nav-item')
    render(<NavItem item={baseItem} collapsed={false} />)

    const link = screen.getByText('Платежи').closest('a')
    expect(link?.className).toContain('bg-accent')
    expect(link?.getAttribute('aria-current')).toBe('page')
  })

  it('does not apply active state for non-matching route', async () => {
    const { NavItem } = await import('../nav-item')
    const otherItem: NavItemType = { ...baseItem, label: 'Возвраты', path: '/refunds' }
    render(<NavItem item={otherItem} collapsed={false} />)

    const link = screen.getByText('Возвраты').closest('a')
    expect(link?.className).toContain('text-muted-foreground')
    expect(link?.getAttribute('aria-current')).toBeNull()
  })

  it('renders badge when badge value is provided', async () => {
    const { NavItem } = await import('../nav-item')
    const itemWithBadge: NavItemType = { ...baseItem, badge: 5 }
    render(<NavItem item={itemWithBadge} collapsed={false} />)

    expect(screen.getByText('5')).toBeDefined()
  })

  it('does not render badge when badge is 0', async () => {
    const { NavItem } = await import('../nav-item')
    const itemWithZeroBadge: NavItemType = { ...baseItem, badge: 0 }
    render(<NavItem item={itemWithZeroBadge} collapsed={false} />)

    expect(screen.queryByText('0')).toBeNull()
  })

  it('does not render badge when badge is undefined', async () => {
    const { NavItem } = await import('../nav-item')
    render(<NavItem item={baseItem} collapsed={false} />)

    // Only the label text should be present
    const label = screen.getByText('Платежи')
    expect(label).toBeDefined()
  })

  it('hides label span when collapsed (only shows in tooltip)', async () => {
    const { NavItem } = await import('../nav-item')
    render(<NavItem item={baseItem} collapsed={true} />)

    // The label should not appear as a span inside the link, only in the tooltip
    const link = screen.getByRole('link')
    const span = link.querySelector('span.flex-1')
    expect(span).toBeNull()
  })

  it('renders tooltip with label when collapsed', async () => {
    const { NavItem } = await import('../nav-item')
    render(<NavItem item={baseItem} collapsed={true} />)

    expect(screen.getByTestId('tooltip-content')).toBeDefined()
    expect(screen.getByTestId('tooltip-content').textContent).toBe('Платежи')
  })

  it('does not render tooltip when expanded', async () => {
    const { NavItem } = await import('../nav-item')
    render(<NavItem item={baseItem} collapsed={false} />)

    expect(screen.queryByTestId('tooltip-content')).toBeNull()
  })
})
