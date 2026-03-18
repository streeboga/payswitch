import { useQuery } from '@tanstack/react-query'
import { analyticsApi, type AnalyticsPeriod } from '@/api/endpoints/analytics'

const STALE_TIME = 30_000

export interface AnalyticsContext {
  merchantKey: string | null
  testMode: boolean
}

export function useOverview(period: AnalyticsPeriod, ctx: AnalyticsContext) {
  const { merchantKey, testMode } = ctx
  return useQuery({
    queryKey: ['analytics', 'overview', period, merchantKey, testMode],
    queryFn: () => analyticsApi.getOverview(period),
    staleTime: STALE_TIME,
    enabled: !!merchantKey,
  })
}

export function useCharts(period: AnalyticsPeriod, ctx: AnalyticsContext) {
  const { merchantKey, testMode } = ctx
  return useQuery({
    queryKey: ['analytics', 'charts', period, merchantKey, testMode],
    queryFn: () => analyticsApi.getCharts(period),
    staleTime: STALE_TIME,
    enabled: !!merchantKey,
  })
}

export function useFunnel(period: AnalyticsPeriod, ctx: AnalyticsContext) {
  const { merchantKey, testMode } = ctx
  return useQuery({
    queryKey: ['analytics', 'funnel', period, merchantKey, testMode],
    queryFn: () => analyticsApi.getFunnel(period),
    staleTime: STALE_TIME,
    enabled: !!merchantKey,
  })
}

export function usePaymentMethods(period: AnalyticsPeriod, ctx: AnalyticsContext) {
  const { merchantKey, testMode } = ctx
  return useQuery({
    queryKey: ['analytics', 'payment-methods', period, merchantKey, testMode],
    queryFn: () => analyticsApi.getPaymentMethods(period),
    staleTime: STALE_TIME,
    enabled: !!merchantKey,
  })
}

export function useFailureReasons(period: AnalyticsPeriod, ctx: AnalyticsContext) {
  const { merchantKey, testMode } = ctx
  return useQuery({
    queryKey: ['analytics', 'failure-reasons', period, merchantKey, testMode],
    queryFn: () => analyticsApi.getFailureReasons(period),
    staleTime: STALE_TIME,
    enabled: !!merchantKey,
  })
}
