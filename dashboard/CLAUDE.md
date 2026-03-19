# CLAUDE.md — Dashboard

React 19 SPA for the Payswitch payment processing platform. Runs as a standalone Vite app, communicates with the Laravel backend via JSON:API over Sanctum session cookies.

## Commands

```bash
npm run dev           # Vite dev server on port 3000
npm run build         # TypeScript check + Vite production build
npm run lint          # ESLint fix
npm run lint:check    # ESLint check only
npm run format        # Prettier fix
npm run format:check  # Prettier check only
npm run types:check   # TypeScript type checking
npm run test          # Vitest unit/component tests
npm run test:watch    # Vitest watch mode
npm run e2e           # Playwright E2E tests
npm run e2e:headed    # Playwright with browser visible
npm run e2e:api       # API-level E2E tests
```

## Tech Stack

- **React** 19.2 (with React Compiler) | **TypeScript** 5.9
- **Build**: Vite 8 + Tailwind CSS 4
- **Routing**: TanStack Router (lazy-loaded routes, auth guards)
- **Data fetching**: React Query + ky HTTP client
- **State**: Zustand (auth, context, preferences, saved-filters)
- **UI**: shadcn/ui + Radix primitives + Lucide icons
- **Forms**: react-hook-form + Zod validation
- **Charts**: Recharts
- **i18n**: i18next (English + Russian)
- **Testing**: Vitest + happy-dom (unit), Playwright (E2E)

## Architecture

### API Layer (`src/api/`)

- **client.ts** — ky instance with Sanctum CSRF, merchant context headers (X-Merchant-Key, X-Profile-Key), 401 redirect, JSON:API error parsing
- **endpoints/** — ~20 endpoint modules (dashboardPayments, dashboardConnectors, etc.) exporting const objects with methods
- **types/** — JSON:API v1.1 types (JsonApiResource, parseCollection, buildJsonApiParams), entity attributes, enums

### State Management (`src/stores/`)

- **auth** — user, isAuthenticated, requiresTwoFactor
- **context** — currentOrgKey, currentMerchantKey, currentProfileKey, testMode (persisted)
- **preferences** — density, timezone, sidebarCollapsed (persisted)
- **saved-filters** — filter presets for data tables (persisted)

### Hooks (`src/hooks/`)

~25 hooks wrapping React Query for each domain. Pattern:
```ts
usePaymentsList(params) → useQuery(['payments', 'list', params, merchantKey, testMode])
```
Queries are `enabled` only when `merchantKey` is set (multi-tenancy context).

### Pages (`src/pages/`)

~30 pages, all lazy-loaded via TanStack Router. Key pages: overview (analytics dashboard), payments, refunds, customers, connectors, routing-rules, webhooks, disputes, audit-log, api-keys, users, settings.

### Components (`src/components/`)

- **ui/** — 30+ shadcn/ui Radix-based primitives
- **shared/** — Reusable business components: command-palette, status-badge, money-format, date-format, json-viewer, copy-button, etc.
- **data-table/** — Generic data grid with filtering, sorting, pagination, density, export, saved filters, URL param sync
- **analytics/** — Metric cards, charts (payments, volume, funnel, failure reasons, payment methods)
- **sidebar/** — Desktop/mobile navigation, test/live mode toggle
- **context-switcher/** — Org/merchant/profile selector
- **notifications/** — Bell icon + notification panel

### Routing (`src/app/router.tsx`)

- Auth routes: `/login`, `/two-factor-challenge` (no guards)
- Protected routes: `requireAuth` beforeLoad guard
- Admin routes: `/users`, `/organizations` use `requireAdmin` guard
- Test-only routes: `/test-payment` uses `requireTestMode` guard

### i18n (`src/locales/`)

- `en.json`, `ru.json` — full translations
- Language detected from localStorage > navigator
- Eager load detected language, lazy load the other

## Key Patterns

- **JSON:API v1.1** — All API responses follow JSON:API spec; `parseCollection()` and `extractAttributes()` helpers handle deserialization
- **Multi-tenancy** — Context store provides org/merchant/profile keys; API client injects them as headers
- **URL-synced table state** — Data table filters, sorting, pagination sync with URL search params via `navigate-search.ts`
- **RBAC** — `useRbac()` hook checks user roles per organization for UI element visibility

## Vite Config

- Dev proxy: `/api/`, `/sanctum`, `/login`, `/logout`, `/two-factor-challenge` → backend (VITE_BACKEND_URL, default `payswitch.test`)
- Path alias: `@` → `./src`
- Manual chunks: recharts, dnd-kit, react-hook-form/zod
