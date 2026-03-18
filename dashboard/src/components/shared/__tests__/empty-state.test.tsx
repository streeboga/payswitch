import { describe, it, expect, afterEach, vi } from 'vitest'
import { render, screen, cleanup, fireEvent } from '@testing-library/react'
import { EmptyState } from '../empty-state'
import { Inbox } from 'lucide-react'

afterEach(cleanup)

describe('EmptyState', () => {
  it('renders title', () => {
    render(<EmptyState title="No payments" />)
    expect(screen.getByText('No payments')).toBeDefined()
  })

  it('renders description when provided', () => {
    render(
      <EmptyState
        title="No payments"
        description="Create your first payment to get started"
      />,
    )
    expect(screen.getByText('Create your first payment to get started')).toBeDefined()
  })

  it('renders icon when provided', () => {
    render(<EmptyState title="No data" icon={Inbox} />)
    // Lucide renders an svg
    const svg = document.querySelector('svg')
    expect(svg).toBeTruthy()
  })

  it('renders action button when provided', () => {
    const onClick = vi.fn()
    render(<EmptyState title="No items" action={{ label: 'Create item', onClick }} />)
    const btn = screen.getByRole('button', { name: 'Create item' })
    expect(btn).toBeDefined()
    fireEvent.click(btn)
    expect(onClick).toHaveBeenCalledOnce()
  })

  it('applies custom className', () => {
    const { container } = render(<EmptyState title="Empty" className="min-h-96" />)
    expect(container.firstElementChild?.className).toContain('min-h-96')
  })
})
