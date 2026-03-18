import { useMutation } from '@tanstack/react-query'
import {
  dashboardTestPayment,
  type TestPaymentRequest,
} from '@/api/endpoints/dashboard-test-payment'

export function useCreateTestPayment() {
  return useMutation({
    mutationFn: (data: TestPaymentRequest) => dashboardTestPayment.create(data),
  })
}
