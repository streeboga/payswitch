# Load Testing

## Prerequisites

- [k6](https://k6.io/docs/getting-started/installation/)
- Running Payswitch server with test database

## Run

```bash
# Health check (p95 < 50ms target)
k6 run loadtest/k6/health.js

# Payment creation (p95 < 300ms target)
API_KEY=your_test_key k6 run loadtest/k6/payment-create.js

# Payment confirm (p95 < 500ms target)
API_KEY=your_test_key k6 run loadtest/k6/payment-confirm.js

# With custom base URL
BASE_URL=https://staging.example.com API_KEY=key k6 run loadtest/k6/health.js
```

## Thresholds

| Scenario | p95 Target | Fail Rate |
|----------|-----------|-----------|
| Health check | < 50ms | < 1% |
| Payment create | < 300ms | < 5% |
| Payment confirm | < 500ms | < 10% |
