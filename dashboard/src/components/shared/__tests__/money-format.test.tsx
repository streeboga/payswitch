import { describe, it, expect, afterEach } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'
import { MoneyFormat } from '../money-format'

afterEach(cleanup)

describe('MoneyFormat', () => {
  it('converts minor units to major and formats', () => {
    render(<MoneyFormat amount={1050} currency="USD" />)
    // Intl.NumberFormat will produce something like "$10.50" or "10.50"
    const el = screen.getByText(/10\.50/)
    expect(el).toBeDefined()
  })

  it('handles zero amount', () => {
    render(<MoneyFormat amount={0} currency="USD" />)
    const el = screen.getByText(/0\.00/)
    expect(el).toBeDefined()
  })

  it('handles non-decimal currencies (JPY)', () => {
    render(<MoneyFormat amount={1500} currency="JPY" />)
    // JPY has 0 decimal places — 1500 minor = 1500 major
    const el = screen.getByText(/1,500|1500/)
    expect(el).toBeDefined()
  })

  it('renders as span by default', () => {
    const { container } = render(<MoneyFormat amount={999} currency="EUR" />)
    expect(container.querySelector('span')).toBeDefined()
  })

  it('applies custom className', () => {
    const { container } = render(
      <MoneyFormat amount={100} currency="USD" className="font-mono" />,
    )
    const span = container.querySelector('span')
    expect(span?.className).toContain('font-mono')
  })

  it('formats large amounts with grouping', () => {
    render(<MoneyFormat amount={1234567} currency="USD" />)
    const el = screen.getByText(/12,345\.67|12345\.67/)
    expect(el).toBeDefined()
  })
})
