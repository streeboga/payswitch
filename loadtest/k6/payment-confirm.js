import http from 'k6/http'
import { check, sleep } from 'k6'

export const options = {
  stages: [
    { duration: '10s', target: 5 },
    { duration: '30s', target: 5 },
    { duration: '10s', target: 0 },
  ],
  thresholds: {
    http_req_duration: ['p(95)<500'],
    http_req_failed: ['rate<0.1'],
  },
}

export function setup() {
  return { apiKey: __ENV.API_KEY || 'test_key' }
}

export default function (data) {
  const baseUrl = __ENV.BASE_URL || 'http://localhost:8000'
  const params = {
    headers: {
      'Content-Type': 'application/json',
      'api-key': data.apiKey,
    },
  }

  // Create payment
  const createRes = http.post(`${baseUrl}/api/v1/payments`, JSON.stringify({
    amount: 5000,
    currency: 'RUB',
  }), params)

  if (createRes.status !== 201) return

  const paymentId = JSON.parse(createRes.body).data?.id
  if (!paymentId) return

  // Confirm payment
  const confirmRes = http.post(`${baseUrl}/api/v1/payments/${paymentId}/confirm`, JSON.stringify({
    payment_method: 'card',
    payment_method_data: {
      card: {
        card_number: '4242424242424242',
        card_exp_month: '12',
        card_exp_year: '2030',
        card_cvc: '123',
      },
    },
  }), params)

  check(confirmRes, {
    'confirm status < 500': (r) => r.status < 500,
  })
  sleep(0.2)
}
