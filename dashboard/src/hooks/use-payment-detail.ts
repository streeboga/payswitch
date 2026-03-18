import { useQuery } from '@tanstack/react-query'
import { dashboardPaymentDetail } from '@/api/endpoints/dashboard-payment-detail'
import { useContextStore } from '@/stores/context'

const STALE_TIME = 30_000

export function usePaymentDetail(paymentKey: string) {
  const merchantKey = useContextStore((s) => s.currentMerchantKey)
  const testMode = useContextStore((s) => s.testMode)

  return useQuery({
    queryKey: ['payments', 'detail', paymentKey, merchantKey, testMode],
    queryFn: () => dashboardPaymentDetail.get(paymentKey),
    staleTime: STALE_TIME,
    enabled: !!merchantKey && !!paymentKey,
  })
}
