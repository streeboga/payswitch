import { describe, it, expect, afterEach, vi } from 'vitest'
import { render, screen, cleanup, fireEvent } from '@testing-library/react'
import { ErrorState } from '../error-state'

afterEach(cleanup)

describe('ErrorState', () => {
  it('renders default error message for 500', () => {
    render(<ErrorState status={500} />)
    expect(screen.getByText('errors.somethingWrong')).toBeDefined()
  })

  it('renders 404 message', () => {
    render(<ErrorState status={404} />)
    expect(screen.getByText('errors.notFound')).toBeDefined()
  })

  it('renders 401 message with login action', () => {
    render(<ErrorState status={401} />)
    expect(screen.getByText('errors.sessionExpired')).toBeDefined()
    expect(screen.getByRole('link', { name: /errors\.signIn/i })).toBeDefined()
  })

  it('shows retry button when onRetry provided', () => {
    const onRetry = vi.fn()
    render(<ErrorState status={500} onRetry={onRetry} />)
    const btn = screen.getByRole('button', { name: /common\.retry/i })
    fireEvent.click(btn)
    expect(onRetry).toHaveBeenCalledOnce()
  })

  it('does not show retry for 401', () => {
    render(<ErrorState status={401} onRetry={vi.fn()} />)
    expect(screen.queryByRole('button', { name: /common\.retry/i })).toBeNull()
  })

  it('renders custom message', () => {
    render(<ErrorState status={500} message="Custom error text" />)
    expect(screen.getByText('Custom error text')).toBeDefined()
  })

  it('applies custom className', () => {
    const { container } = render(<ErrorState status={500} className="min-h-96" />)
    expect(container.firstElementChild?.className).toContain('min-h-96')
  })
})
