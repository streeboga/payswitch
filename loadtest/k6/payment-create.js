import http from 'k6/http'
import { check, sleep } from 'k6'

export const options = {
  stages: [
    { duration: '10s', target: 10 },
    { duration: '30s', target: 10 },
    { duration: '10s', target: 0 },
  ],
  thresholds: {
    http_req_duration: ['p(95)<300'],
    http_req_failed: ['rate<0.05'],
  },
}

export function setup() {
  // This would need a pre-seeded API key
  return { apiKey: __ENV.API_KEY || 'test_key' }
}

export default function (data) {
  const payload = JSON.stringify({
    amount: Math.floor(Math.random() * 100000) + 100,
    currency: 'RUB',
  })

  const params = {
    headers: {
      'Content-Type': 'application/json',
      'api-key': data.apiKey,
    },
  }

  const res = http.post(`${__ENV.BASE_URL || 'http://localhost:8000'}/api/v1/payments`, payload, params)
  check(res, {
    'status is 201': (r) => r.status === 201,
    'has payment id': (r) => JSON.parse(r.body).data?.id !== undefined,
  })
  sleep(0.1)
}
