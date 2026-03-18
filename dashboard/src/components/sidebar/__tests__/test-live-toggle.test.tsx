import { describe, it, expect, afterEach, vi, beforeEach } from 'vitest'
import { render, screen, cleanup, fireEvent } from '@testing-library/react'

let mockTestMode = true
const mockSetTestMode = vi.fn()
let mockSidebarCollapsed = false

const mockContextStore = () => ({
  testMode: mockTestMode,
  setTestMode: mockSetTestMode,
})

const mockPrefsStore = () => ({
  sidebarCollapsed: mockSidebarCollapsed,
})

vi.mock('@/stores/context', () => ({
  useContextStore: (selector?: (state: ReturnType<typeof mockContextStore>) => unknown) => {
    const state = mockContextStore()
    return selector ? selector(state) : state
  },
}))

vi.mock('@/stores/preferences', () => ({
  usePreferencesStore: (selector?: (state: ReturnType<typeof mockPrefsStore>) => unknown) => {
    const state = mockPrefsStore()
    return selector ? selector(state) : state
  },
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

describe('TestLiveToggle', () => {
  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
  })

  beforeEach(() => {
    mockTestMode = true
    mockSidebarCollapsed = false
    mockSetTestMode.mockClear()
  })

  it('renders in test mode by default', async () => {
    const { TestLiveToggle } = await import('../test-live-toggle')
    render(<TestLiveToggle />)

    expect(screen.getByTestId('test-live-toggle')).toBeDefined()
    expect(screen.getByTestId('test-badge')).toBeDefined()
  })

  it('shows "Test" badge in test mode', async () => {
    const { TestLiveToggle } = await import('../test-live-toggle')
    render(<TestLiveToggle />)

    expect(screen.getByTestId('test-badge')).toBeDefined()
    expect(screen.getByText('Test')).toBeDefined()
    expect(screen.queryByTestId('live-badge')).toBeNull()
  })

  it('shows "Live" badge when in live mode', async () => {
    mockTestMode = false
    const { TestLiveToggle } = await import('../test-live-toggle')
    render(<TestLiveToggle />)

    expect(screen.getByTestId('live-badge')).toBeDefined()
    expect(screen.getByText('Live')).toBeDefined()
    expect(screen.queryByTestId('test-badge')).toBeNull()
  })

  it('shows confirm dialog when switching to Live', async () => {
    const { TestLiveToggle } = await import('../test-live-toggle')
    render(<TestLiveToggle />)

    // Click the switch to toggle to Live
    const switchEl = screen.getByRole('switch')
    fireEvent.click(switchEl)

    // Confirm dialog should appear
    expect(screen.getByText('testLiveToggle.confirmTitle')).toBeDefined()
    expect(screen.getByText('testLiveToggle.confirmDesc')).toBeDefined()
  })

  it('switches to live mode on confirm', async () => {
    const { TestLiveToggle } = await import('../test-live-toggle')
    render(<TestLiveToggle />)

    // Click the switch to toggle to Live
    const switchEl = screen.getByRole('switch')
    fireEvent.click(switchEl)

    // Confirm the dialog
    fireEvent.click(screen.getByRole('button', { name: /testLiveToggle\.confirmButton/i }))
    expect(mockSetTestMode).toHaveBeenCalledWith(false)
  })

  it('does not switch on cancel', async () => {
    const { TestLiveToggle } = await import('../test-live-toggle')
    render(<TestLiveToggle />)

    const switchEl = screen.getByRole('switch')
    fireEvent.click(switchEl)

    // Cancel the dialog
    fireEvent.click(screen.getByRole('button', { name: /common\.cancel/i }))
    expect(mockSetTestMode).not.toHaveBeenCalled()
  })

  it('switches back to test mode without confirmation', async () => {
    mockTestMode = false
    const { TestLiveToggle } = await import('../test-live-toggle')
    render(<TestLiveToggle />)

    const switchEl = screen.getByRole('switch')
    fireEvent.click(switchEl)

    // Should switch immediately without confirm dialog
    expect(mockSetTestMode).toHaveBeenCalledWith(true)
    expect(screen.queryByText('testLiveToggle.confirmTitle')).toBeNull()
  })

  it('shows colored dot indicator when sidebar is collapsed', async () => {
    mockSidebarCollapsed = true
    const { TestLiveToggle } = await import('../test-live-toggle')
    render(<TestLiveToggle />)

    expect(screen.getByTestId('test-live-indicator')).toBeDefined()
    expect(screen.queryByTestId('test-live-toggle')).toBeNull()
  })
})
