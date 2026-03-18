import { describe, it, expect, vi, beforeEach } from 'vitest'
import type { AnalyticsContext } from '../use-analytics'

// Mock the analytics API
vi.mock('@/api/endpoints/analytics', () => ({
  analyticsApi: {
    getOverview: vi.fn(),
    getCharts: vi.fn(),
    getFunnel: vi.fn(),
    getPaymentMethods: vi.fn(),
    getFailureReasons: vi.fn(),
  },
}))

// Track useQuery calls
interface QueryCall {
  queryKey: unknown[]
  staleTime: number
  enabled?: boolean
}

const useQueryCalls: QueryCall[] = []

function lastCall(): QueryCall {
  return useQueryCalls[useQueryCalls.length - 1]!
}

vi.mock('@tanstack/react-query', () => ({
  useQuery: (opts: QueryCall) => {
    useQueryCalls.push(opts)
    return { data: undefined, isLoading: true }
  },
}))

const defaultCtx: AnalyticsContext = { merchantKey: 'merch_123', testMode: true }

describe('use-analytics hooks', () => {
  beforeEach(() => {
    useQueryCalls.length = 0
  })

  it('useOverview passes correct query key with period, merchant, and testMode', async () => {
    const { useOverview } = await import('../use-analytics')
    useOverview('7d', defaultCtx)

    expect(useQueryCalls).toHaveLength(1)
    expect(lastCall().queryKey).toEqual([
      'analytics',
      'overview',
      '7d',
      'merch_123',
      true,
    ])
  })

  it('useCharts passes correct query key', async () => {
    const { useCharts } = await import('../use-analytics')
    useCharts('30d', defaultCtx)

    expect(useQueryCalls).toHaveLength(1)
    expect(lastCall().queryKey).toEqual(['analytics', 'charts', '30d', 'merch_123', true])
  })

  it('useFunnel passes correct query key', async () => {
    const { useFunnel } = await import('../use-analytics')
    useFunnel('today', defaultCtx)

    expect(useQueryCalls).toHaveLength(1)
    expect(lastCall().queryKey).toEqual([
      'analytics',
      'funnel',
      'today',
      'merch_123',
      true,
    ])
  })

  it('usePaymentMethods passes correct query key', async () => {
    const { usePaymentMethods } = await import('../use-analytics')
    usePaymentMethods('90d', defaultCtx)

    expect(useQueryCalls).toHaveLength(1)
    expect(lastCall().queryKey).toEqual([
      'analytics',
      'payment-methods',
      '90d',
      'merch_123',
      true,
    ])
  })

  it('useFailureReasons passes correct query key', async () => {
    const { useFailureReasons } = await import('../use-analytics')
    useFailureReasons('7d', defaultCtx)

    expect(useQueryCalls).toHaveLength(1)
    expect(lastCall().queryKey).toEqual([
      'analytics',
      'failure-reasons',
      '7d',
      'merch_123',
      true,
    ])
  })

  it('all hooks use 30s stale time', async () => {
    const { useOverview, useCharts, useFunnel, usePaymentMethods, useFailureReasons } =
      await import('../use-analytics')

    useOverview('7d', defaultCtx)
    useCharts('7d', defaultCtx)
    useFunnel('7d', defaultCtx)
    usePaymentMethods('7d', defaultCtx)
    useFailureReasons('7d', defaultCtx)

    for (const call of useQueryCalls) {
      expect(call.staleTime).toBe(30_000)
    }
  })

  it('query key reflects testMode=false', async () => {
    const { useOverview } = await import('../use-analytics')
    useOverview('7d', { merchantKey: 'merch_123', testMode: false })

    expect(lastCall().queryKey).toEqual([
      'analytics',
      'overview',
      '7d',
      'merch_123',
      false,
    ])
  })

  it('query key reflects null merchant', async () => {
    const { useOverview } = await import('../use-analytics')
    useOverview('today', { merchantKey: null, testMode: true })

    expect(lastCall().queryKey).toEqual(['analytics', 'overview', 'today', null, true])
  })
})
