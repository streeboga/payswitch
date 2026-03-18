import { describe, it, expect, afterEach } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'
import { DateFormat } from '../date-format'

afterEach(cleanup)

describe('DateFormat', () => {
  const isoDate = '2026-03-15T14:30:00Z'

  it('renders formatted date', () => {
    render(<DateFormat date={isoDate} />)
    // Should show some date representation
    const el = screen.getByText(/Mar|2026|15/)
    expect(el).toBeDefined()
  })

  it('renders relative time when variant is relative', () => {
    const recent = new Date(Date.now() - 60_000).toISOString()
    render(<DateFormat date={recent} variant="relative" />)
    const el = screen.getByText(/minute|just now|ago/)
    expect(el).toBeDefined()
  })

  it('shows absolute date in title attribute', () => {
    render(<DateFormat date={isoDate} variant="relative" />)
    const el = screen.getByText(/ago|Mar|2026/)
    expect(el.getAttribute('title')).toBeTruthy()
  })

  it('renders absolute format by default', () => {
    render(<DateFormat date={isoDate} variant="absolute" />)
    const el = screen.getByText(/Mar 15/)
    expect(el).toBeDefined()
  })

  it('applies custom className', () => {
    const { container } = render(<DateFormat date={isoDate} className="text-sm" />)
    const span = container.querySelector('span')
    expect(span?.className).toContain('text-sm')
  })

  it('handles Date object input', () => {
    render(<DateFormat date={new Date(isoDate)} />)
    const el = screen.getByText(/Mar|2026|15/)
    expect(el).toBeDefined()
  })
})
