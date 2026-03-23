# CLAUDE.md — payment-connectors

PSP connector implementations for the Payswitch platform. Each connector integrates with a payment gateway via the `ConnectorInterface` contract.

## Package Info

- **Namespace**: `Streeboga\PaymentConnectors`
- **Requires**: PHP 8.4+, streeboga/payment-data
- **Provider**: `PaymentConnectorsServiceProvider` (boots ConnectorFactory from config)

## Structure

### Core (`src/`)

- **ConnectorFactory** — config-driven factory. Reads `config/payswitch.php` connectors array. Runtime registration via `::register()`.
- **ConnectorCapabilities** — readonly VO: display name, logo, direct methods, session types, amount unit
- **DirectMethod** — readonly VO: session type for a specific payment method
- **PaymentSessionResult** — readonly VO with 4 static constructors: `serverRedirect()`, `formRedirect()`, `embeddedWidget()`, `qrInline()`
- **HttpConnector** — abstract base class with build/parse pattern for new connectors. Handles HTTP transport, auth, amount formatting.
- **ConnectorErrorNormalizer** — normalizes PSP-specific errors into unified format

### Drivers (`src/Drivers/`) — 9 connectors

| Driver | PSP | Integration | Amount | Auth |
|--------|-----|-------------|--------|------|
| **StripeConnector** | Stripe | server_redirect | cents (minor) | Bearer token |
| **CloudPaymentsConnector** | CloudPayments | embedded_widget | rubles (/100) | Basic auth |
| **YooKassaConnector** | ЮKassa | server_redirect | rubles (/100) | Basic auth |
| **RbsConnector** | Сбер + Альфа (RBS gateway) | server_redirect + qr_inline (SBP) | kopecks (as-is) | Form params (user/pass or token) |
| **TBankConnector** | Т-Банк (Tinkoff) | server_redirect + qr_inline (SBP) | kopecks (as-is) | SHA-256 signed Token |
| **RobokassaConnector** | Робокасса | form_redirect | rubles (/100) | MD5 signatures (2 passwords) |
| **TochkaConnector** | Точка | server_redirect | rubles (/100) | Bearer JWT |
| **TestConnector** | Mock | server_redirect | minor units | None |

### Enums (in payment-data package)

- **SessionResultType** — `redirect`, `form_redirect`, `widget`, `qr`
- **AmountUnit** — `rubles`, `kopecks`, `minor`

## Config

Connectors registered in `config/payswitch.php`:
```php
'connectors' => [
    'stripe' => StripeConnector::class,
    'cloudpayments' => CloudPaymentsConnector::class,
    // ... 9 total
],
```

External packages register via ServiceProvider: `ConnectorFactory::register('name', Driver::class)`

## Adding a New Connector

See `CONNECTOR_SDK.md` for the full guide. Summary:

1. Create driver in `src/Drivers/` implementing `ConnectorInterface`
2. Implement `capabilities()` static method
3. Add to `config/payswitch.php`
4. Add case to `app/Enums/ConnectorName.php`
5. Add to dashboard frontend (9 files — see SDK doc)
6. Write tests + webhook fixture
