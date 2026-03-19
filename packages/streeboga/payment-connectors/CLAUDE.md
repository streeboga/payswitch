# CLAUDE.md — payment-connectors

PSP connector implementations for the Payswitch platform. Each connector integrates with a payment gateway via the `ConnectorInterface` contract from `payment-data`.

## Package Info

- **Namespace**: `Streeboga\PaymentConnectors`
- **Requires**: PHP 8.3+, streeboga/payment-data, omnipay/common 3.0, omnipay/stripe 3.0
- **Provider**: `PaymentConnectorsServiceProvider`

## Structure

### Drivers (`src/Drivers/`)

- **StripeConnector** — Stripe integration via Omnipay
- **CloudPaymentsConnector** — CloudPayments integration
- **YooKassaConnector** — YooKassa integration
- **TestConnector** — Mock connector for testing and development

All extend `AbstractConnector` which implements `ConnectorInterface`.

### Factory

`ConnectorFactory` — resolves connector driver by `ConnectorName` enum. Registered in the service provider.

### Error Handling

`ConnectorErrorNormalizer` — normalizes PSP-specific errors into a unified format for consistent error reporting.

## Adding a New Connector

1. Create a new driver class in `src/Drivers/` extending `AbstractConnector`
2. Implement all methods from `ConnectorInterface` (authorize, capture, refund, etc.)
3. Add connector name to `ConnectorName` enum in `app/Enums/`
4. Register in `ConnectorFactory`
5. Add webhook fixture JSON files in `tests/Fixtures/Webhooks/`
6. Write unit tests in `tests/Unit/Connectors/`
