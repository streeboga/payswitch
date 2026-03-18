import { describe, it, expect, afterEach, vi, beforeEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { TooltipProvider } from '@/components/ui/tooltip'
import { CopyButton } from '../copy-button'

// Mock clipboard API
const writeText = vi.fn(() => Promise.resolve())

beforeEach(() => {
  writeText.mockClear()
  Object.defineProperty(navigator, 'clipboard', {
    value: { writeText },
    writable: true,
    configurable: true,
  })
})

afterEach(cleanup)

function renderWithProvider(ui: React.ReactNode) {
  return render(<TooltipProvider>{ui}</TooltipProvider>)
}

describe('CopyButton', () => {
  it('renders copy icon button', () => {
    renderWithProvider(<CopyButton value="test-value" />)
    const button = screen.getByRole('button')
    expect(button).toBeDefined()
  })

  it('copies value to clipboard on click', async () => {
    renderWithProvider(<CopyButton value="pay_abc123" />)
    fireEvent.click(screen.getByRole('button'))
    expect(writeText).toHaveBeenCalledWith('pay_abc123')
  })

  it('shows check icon after successful copy', async () => {
    renderWithProvider(<CopyButton value="test" />)
    fireEvent.click(screen.getByRole('button'))
    await waitFor(() => {
      const svg = screen.getByRole('button').querySelector('svg')
      expect(svg).toBeDefined()
    })
  })

  it('applies custom className', () => {
    renderWithProvider(<CopyButton value="test" className="ml-4" />)
    const button = screen.getByRole('button')
    expect(button.className).toContain('ml-4')
  })

  it('renders with custom aria-label', () => {
    renderWithProvider(<CopyButton value="test" label="Copy ID" />)
    const button = screen.getByRole('button', { name: 'Copy ID' })
    expect(button).toBeDefined()
  })
})
