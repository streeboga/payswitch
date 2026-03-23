# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Payswitch — Laravel 13 backend API + React 19 SPA dashboard (separate Vite app in `/dashboard/`). Payment processing platform with multi-tenancy (Organization → Merchant → Business Profile), smart routing, and multiple PSP connectors. Authentication powered by Laravel Sanctum (SPA cookie auth) with custom 2FA via google2fa. No Inertia.js, no Fortify.

## Common Commands

```bash
# Full project setup (install deps, key generate, migrate, build)
composer setup

# Development (runs server, queue, logs, vite concurrently)
composer dev

# Testing
composer test             # lint + tests
./vendor/bin/pest         # run all tests
./vendor/bin/pest --filter=TestName   # single test
./vendor/bin/pest tests/Feature/Auth  # test directory

# PHP linting
composer lint             # fix with Pint
composer lint:check       # check only

# Frontend linting & formatting (from dashboard/)
cd dashboard
npm run lint              # ESLint fix
npm run lint:check        # ESLint check
npm run format            # Prettier fix
npm run format:check      # Prettier check
npm run types:check       # TypeScript type checking
npm run test              # Vitest
npm run e2e               # Playwright E2E tests

# Full CI check
composer ci:check         # lint + format + types + tests

# Build
cd dashboard && npm run build
```

## Architecture

### Backend (PHP)

#### Internal Packages (`packages/`)

- **streeboga/payment-data** — Core domain: models, enums, state machine, migrations, contracts (see `packages/streeboga/payment-data/CLAUDE.md`)
- **streeboga/payment-connectors** — PSP integrations: 9 connectors (Stripe, CloudPayments, YooKassa, Sberbank, Alfa-Bank, T-Bank, Robokassa, Tochka, Test). Config-driven factory, 4 integration types (redirect, form_redirect, widget, qr_inline). See `packages/streeboga/payment-connectors/CLAUDE.md` and `CONNECTOR_SDK.md`
- **scramble** — Custom API documentation generator with JSON:API v1.1 support

#### Application Layer (`app/`)

- **Controllers** — Auth (3), API/V1 Admin (6), API/V1 Public (5), Dashboard (22)
- **Services** (`app/Services/`) — 27 service classes: PaymentService, RoutingService, WebhookService, AnalyticsService, etc.
- **Repositories** (`app/Repositories/`) — 16 repository interfaces + Eloquent implementations
- **Query Builders** (`app/Builders/`) — 10 custom Eloquent query builders with Spatie filtering
- **DTOs** (`app/DataTransferObjects/`) — Spatie data objects grouped by domain (Admin, Payment, Customer, Refund)
- **Policies** (`app/Policies/`) — 20 authorization policies for RBAC (Admin, Operator, Viewer)
- **Models** (`app/Models/`) — User, UserRole, UserPreference, AppNotification, Dispute, DisputeEvidence, SavedFilter
- **Middleware** — AuthenticateAdminApiKey, AuthenticateSecretApiKey, AuthenticateClientSecret, ForceJsonApiContentType, ResolveApiKey, ResolveMerchantContext, HandleAppearance
- **Events** — PaymentStatusChanged → LogPaymentAudit, SendWebhookNotification
- **Jobs** — CleanExpiredPaymentsJob, DeliverWebhookJob
- **Enums** (`app/Enums/`) — ConnectorName, UserRole, PaymentAttemptStatus, DisputeStatus, etc.

#### Routes

- `routes/web.php` — Auth: login, logout, 2FA challenge
- `routes/api.php` — `/api/v1/`: user endpoint, dashboard API (Sanctum), admin API (API key), public API (publishable key + client_secret), merchant API (secret key), webhook receiver, health check

### Frontend (TypeScript/React) — `/dashboard/`

See `dashboard/CLAUDE.md` for detailed frontend architecture.

- **Pages** (`src/pages/`) — ~30 pages: overview, payments, refunds, customers, connectors, routing, webhooks, disputes, audit, etc.
- **Components** (`src/components/`) — ~80 components: ui (shadcn/Radix), shared, data-table, analytics, sidebar, routing, notifications
- **Hooks** (`src/hooks/`) — ~25 custom hooks wrapping React Query for each domain
- **Stores** (`src/stores/`) — Zustand: auth, context (multi-tenancy), preferences, saved-filters
- **API** (`src/api/`) — ky HTTP client with Sanctum CSRF, JSON:API types, ~20 endpoint modules
- **i18n** (`src/locales/`) — English + Russian translations via i18next

### Payment Widget (`/widget/`)

Embeddable JS SDK (`@payswitch/js`) for merchants to accept payments on their sites. Preact-based UI, Vite library mode (ESM + UMD).

- **SDK** (`src/index.ts`) — `loadPayswitch(publishableKey, options)` entry point
- **API Client** (`src/api.ts`) — fetch wrapper for Public API (publishable key + client_secret auth)
- **Widget** (`src/payswitch.ts`) — `PayswitchInstance.widgets().create('payment').mount('#el')`
- **UI** (`src/ui/`) — Preact payment method selector component
- **Demo** (`demo.html`) — Test harness page

```bash
cd widget && npm install && npm run build   # Build SDK
cd widget && npm run test                   # Vitest
```

### Stack Integration

Laravel serves JSON API. Dashboard SPA runs on Vite port 3000, proxies `/api` and `/sanctum` to Laravel port 8000. Authentication via Sanctum session cookies (not tokens). Payment widget authenticates via publishable key + client_secret (no cookies).

## Tech Stack Details

- **PHP**: 8.4+ | **Laravel**: 13 | **Testing**: Pest PHP
- **Node**: 22 | **React**: 19 (with React Compiler) | **TypeScript**: 5.9
- **Build**: Vite 8 | **CSS**: Tailwind 4 | **UI**: shadcn/ui + Radix primitives
- **Auth**: Laravel Sanctum SPA + custom 2FA (google2fa)
- **State**: Zustand | **Routing**: TanStack Router | **Data**: React Query + ky
- **Forms**: react-hook-form + Zod | **Charts**: Recharts | **Icons**: Lucide
- **Queue**: Database driver
- **Code Style**: Pint (PHP, preset: laravel), ESLint + Prettier (TS/React)
- **Static Analysis**: Larastan + PHPStan (strict rules) | **Architecture**: Deptrac

## Key Patterns

- **Repository Pattern** — Interface → Eloquent implementation for all data access
- **Service Layer** — Business logic isolated from controllers
- **State Machine** — `PaymentStateMachine` manages payment status transitions
- **Multi-Tenancy** — Organization → Merchant → Business Profile hierarchy with middleware resolution
- **RBAC** — UserRole (Admin, Operator, Viewer) per organization with weight-based authorization
- **JSON:API v1.1** — All API responses follow JSON:API spec via `JsonApiResource`
- **Event-Driven Webhooks** — Payment events → audit log + webhook delivery
- **Public API** — Publishable key + client_secret auth for browser-side payment widget (no secret key exposure)

## Testing

Backend tests use Pest PHP with `RefreshDatabase` trait and in-memory SQLite. 85+ test files organized: `tests/Feature/` (Api, Auth, Dashboard, Services, Jobs, EdgeCases, ContractViolations) and `tests/Unit/` (Builders, Connectors, Enums, StateMachine). Frontend tests use Vitest with happy-dom (50+ test files). E2E tests use Playwright. CI runs tests against PHP 8.3, 8.4, 8.5.
