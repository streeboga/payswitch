import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'
import { PeriodFilter } from '../period-filter'

describe('PeriodFilter', () => {
  afterEach(cleanup)

  it('renders the select trigger', () => {
    const onChange = vi.fn()
    render(<PeriodFilter value="7d" onChange={onChange} />)
    expect(screen.getByTestId('period-filter')).toBeDefined()
  })

  it('displays the current period label', () => {
    const onChange = vi.fn()
    render(<PeriodFilter value="7d" onChange={onChange} />)
    expect(screen.getByText('analytics.period7d')).toBeDefined()
  })

  it('displays today label when value is today', () => {
    const onChange = vi.fn()
    render(<PeriodFilter value="today" onChange={onChange} />)
    expect(screen.getByText('analytics.periodToday')).toBeDefined()
  })

  it('displays 30 days label', () => {
    const onChange = vi.fn()
    render(<PeriodFilter value="30d" onChange={onChange} />)
    expect(screen.getByText('analytics.period30d')).toBeDefined()
  })

  it('displays quarter label', () => {
    const onChange = vi.fn()
    render(<PeriodFilter value="90d" onChange={onChange} />)
    expect(screen.getByText('analytics.period90d')).toBeDefined()
  })
})
