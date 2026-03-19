import http from 'k6/http'
import { check } from 'k6'

export const options = {
  stages: [
    { duration: '10s', target: 25 },
    { duration: '30s', target: 25 },
    { duration: '10s', target: 0 },
  ],
  thresholds: {
    http_req_duration: ['p(95)<50'],
    http_req_failed: ['rate<0.01'],
  },
}

export default function () {
  const res = http.get(`${__ENV.BASE_URL || 'http://localhost:8000'}/up`)
  check(res, {
    'status is 200': (r) => r.status === 200,
  })
}
