import { describe, it, expect, afterEach, vi, beforeEach } from 'vitest'
import { render, screen, cleanup, fireEvent } from '@testing-library/react'

let mockTestMode = true
const mockSetTestMode = vi.fn()

const mockContextStore = () => ({
  testMode: mockTestMode,
  setTestMode: mockSetTestMode,
})

vi.mock('@/stores/context', () => ({
  useContextStore: (
    selector?: (state: ReturnType<typeof mockContextStore>) => unknown,
  ) => {
    const state = mockContextStore()
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
    mockSetTestMode.mockClear()
  })

  it('renders in test mode by default', async () => {
    const { TestLiveToggle } = await import('../test-live-toggle')
    render(<TestLiveToggle />)

    expect(screen.getByTestId('test-live-toggle')).toBeDefined()
    expect(screen.getByText('Test')).toBeDefined()
  })

  it('shows "Test" text in test mode', async () => {
    const { TestLiveToggle } = await import('../test-live-toggle')
    render(<TestLiveToggle />)

    expect(screen.getByText('Test')).toBeDefined()
    expect(screen.queryByText('Live')).toBeNull()
  })

  it('shows "Live" text when in live mode', async () => {
    mockTestMode = false
    const { TestLiveToggle } = await import('../test-live-toggle')
    render(<TestLiveToggle />)

    expect(screen.getByText('Live')).toBeDefined()
    expect(screen.queryByText('Test')).toBeNull()
  })

  it('shows confirm dialog when switching to Live', async () => {
    const { TestLiveToggle } = await import('../test-live-toggle')
    render(<TestLiveToggle />)

    // Click the toggle button
    const toggle = screen.getByTestId('test-live-toggle')
    fireEvent.click(toggle)

    // Confirm dialog should appear
    expect(screen.getByText('testLiveToggle.confirmTitle')).toBeDefined()
    expect(screen.getByText('testLiveToggle.confirmDesc')).toBeDefined()
  })

  it('switches to live mode on confirm', async () => {
    const { TestLiveToggle } = await import('../test-live-toggle')
    render(<TestLiveToggle />)

    // Click the toggle button
    const toggle = screen.getByTestId('test-live-toggle')
    fireEvent.click(toggle)

    // Confirm the dialog
    fireEvent.click(
      screen.getByRole('button', { name: /testLiveToggle\.confirmButton/i }),
    )
    expect(mockSetTestMode).toHaveBeenCalledWith(false)
  })

  it('does not switch on cancel', async () => {
    const { TestLiveToggle } = await import('../test-live-toggle')
    render(<TestLiveToggle />)

    const toggle = screen.getByTestId('test-live-toggle')
    fireEvent.click(toggle)

    // Cancel the dialog
    fireEvent.click(screen.getByRole('button', { name: /common\.cancel/i }))
    expect(mockSetTestMode).not.toHaveBeenCalled()
  })

  it('switches back to test mode without confirmation', async () => {
    mockTestMode = false
    const { TestLiveToggle } = await import('../test-live-toggle')
    render(<TestLiveToggle />)

    const toggle = screen.getByTestId('test-live-toggle')
    fireEvent.click(toggle)

    // Should switch immediately without confirm dialog
    expect(mockSetTestMode).toHaveBeenCalledWith(true)
    expect(screen.queryByText('testLiveToggle.confirmTitle')).toBeNull()
  })
})
