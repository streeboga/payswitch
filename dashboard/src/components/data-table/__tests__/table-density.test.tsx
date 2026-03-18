import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest'
import { render, screen, cleanup, fireEvent } from '@testing-library/react'

const mockPreferencesStore = {
  density: 'comfortable' as string,
  setDensity: vi.fn(),
}

vi.mock('@/stores/preferences', () => ({
  usePreferencesStore: (selector: (s: typeof mockPreferencesStore) => unknown) =>
    selector(mockPreferencesStore),
}))

// Mock Tooltip to avoid Radix portal issues
vi.mock('@/components/ui/tooltip', () => ({
  Tooltip: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  TooltipTrigger: ({
    children,
    asChild: _asChild,
  }: {
    children: React.ReactNode
    asChild?: boolean
  }) => <>{children}</>,
  TooltipContent: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}))

// Mock Popover — render content inline for testability
vi.mock('@/components/ui/popover', () => ({
  Popover: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  PopoverTrigger: ({
    children,
    asChild: _asChild,
  }: {
    children: React.ReactNode
    asChild?: boolean
  }) => <>{children}</>,
  PopoverContent: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}))

// Mock Button
vi.mock('@/components/ui/button', () => ({
  Button: ({
    children,
    ...props
  }: React.ButtonHTMLAttributes<HTMLButtonElement> & {
    variant?: string
    size?: string
    asChild?: boolean
  }) => <button {...props}>{children}</button>,
}))

describe('TableDensityToggle', () => {
  afterEach(cleanup)

  beforeEach(() => {
    mockPreferencesStore.density = 'comfortable'
    mockPreferencesStore.setDensity = vi.fn()
  })

  it('renders three density options', async () => {
    const { TableDensityToggle } = await import('../table-density')
    render(<TableDensityToggle />)

    expect(screen.getByText('table.compact')).toBeDefined()
    expect(screen.getByText('table.comfortable')).toBeDefined()
    expect(screen.getByText('table.spacious')).toBeDefined()
  })

  it('calls setDensity when clicking compact option', async () => {
    const { TableDensityToggle } = await import('../table-density')
    render(<TableDensityToggle />)

    fireEvent.click(screen.getByText('table.compact'))
    expect(mockPreferencesStore.setDensity).toHaveBeenCalledWith('compact')
  })

  it('calls setDensity when clicking spacious option', async () => {
    const { TableDensityToggle } = await import('../table-density')
    render(<TableDensityToggle />)

    fireEvent.click(screen.getByText('table.spacious'))
    expect(mockPreferencesStore.setDensity).toHaveBeenCalledWith('spacious')
  })
})
