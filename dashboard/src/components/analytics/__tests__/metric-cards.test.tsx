import { describe, it, expect, afterEach } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'
import { MetricCards } from '../metric-cards'
import type { OverviewData } from '@/api/endpoints/analytics'

describe('MetricCards', () => {
  afterEach(cleanup)

  const mockData: OverviewData = {
    total_volume: 1_500_000,
    transaction_count: 342,
    conversion_rate: 87.5,
    refund_rate: 2.3,
    avg_ticket: 4385,
    dispute_count: 5,
  }

  it('renders skeleton when loading', () => {
    render(<MetricCards isLoading={true} />)
    expect(screen.getByTestId('metric-cards-skeleton')).toBeDefined()
  })

  it('renders skeleton when data is undefined', () => {
    render(<MetricCards isLoading={false} />)
    expect(screen.getByTestId('metric-cards-skeleton')).toBeDefined()
  })

  it('renders all six metric cards with data', () => {
    render(<MetricCards data={mockData} isLoading={false} />)
    const container = screen.getByTestId('metric-cards')
    expect(container).toBeDefined()

    expect(screen.getByText('analytics.volume')).toBeDefined()
    expect(screen.getByText('analytics.transactions')).toBeDefined()
    expect(screen.getByText('analytics.conversion')).toBeDefined()
    expect(screen.getByText('analytics.refundsMetric')).toBeDefined()
    expect(screen.getByText('analytics.avgTicket')).toBeDefined()
    expect(screen.getByText('analytics.disputesMetric')).toBeDefined()
  })

  it('formats conversion rate as percentage', () => {
    render(<MetricCards data={mockData} isLoading={false} />)
    expect(screen.getByText('87.5%')).toBeDefined()
  })

  it('formats refund rate as percentage', () => {
    render(<MetricCards data={mockData} isLoading={false} />)
    expect(screen.getByText('2.3%')).toBeDefined()
  })

  it('formats transaction count as number', () => {
    render(<MetricCards data={mockData} isLoading={false} />)
    expect(screen.getByText('342')).toBeDefined()
  })

  it('formats dispute count as number', () => {
    render(<MetricCards data={mockData} isLoading={false} />)
    expect(screen.getByText('5')).toBeDefined()
  })
})
