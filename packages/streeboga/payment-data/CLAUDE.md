# CLAUDE.md — payment-data

Core domain package for the Payswitch payment processing platform. Contains all payment-related models, enums, state machine, migrations, and contracts.

## Package Info

- **Namespace**: `Streeboga\PaymentData`
- **Requires**: PHP 8.3+, illuminate/database 13.0, illuminate/support 13.0, spatie/laravel-data 4.0
- **Provider**: `PaymentDataServiceProvider`

## Structure

### Models (`src/Models/`)

Organization, MerchantAccount, BusinessProfile, ApiKey, MerchantConnectorAccount, PaymentIntent, PaymentAttempt, PaymentAuditLog, Customer, PaymentMethod, Refund, RoutingRule, WebhookEvent

### Enums (`src/Enums/`)

ApiKeyType, AuthenticationType, CaptureMethod, ConnectorType, PaymentStatus, RefundStatus, WebhookEventType

### Contracts (`src/Contracts/`)

ConnectorInterface — contract that all PSP connectors must implement. HasColor, HasLabel — display metadata.

### State Machine (`src/StateMachine/`)

`PaymentStateMachine` — defines valid payment status transitions. Central to payment lifecycle management.

### Support (`src/Support/`)

- `IdGenerator` — generates unique IDs for payment entities
- `WebhookSigner` — HMAC signing for webhook payloads

### Exceptions (`src/Exceptions/`)

ApiAuthenticationException, ConnectorException, InvalidStateTransitionException, PaymentException

### Migrations (`database/migrations/`)

15 migrations: organizations, merchant_accounts, business_profiles, api_keys, merchant_connector_accounts, payment_intents, payment_attempts, payment_audit_log, customers, refunds, webhook_events, payment_methods, routing_rules

### Config

`config/payswitch.php` — core payment processing configuration
