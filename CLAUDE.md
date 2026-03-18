# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Payswitch — Laravel 13 backend API + React 19 SPA dashboard (separate Vite app in `/dashboard/`). Authentication powered by Laravel Sanctum (SPA cookie auth) with custom 2FA via google2fa. No Inertia.js, no Fortify.

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

# Full CI check
composer ci:check         # lint + format + types + tests

# Build
cd dashboard && npm run build
```

## Architecture

### Backend (PHP)

- **Concerns** (`app/Concerns/`) — `HasTwoFactorAuthentication` trait (custom 2FA via google2fa)
- **Auth Controllers** (`app/Http/Controllers/Auth/`) — LoginController, TwoFactorChallengeController, UserController
- **API Controllers** (`app/Http/Controllers/Api/V1/`) — JSON:API v1.1 compliant payment processing API
- **Form Requests** (`app/Http/Requests/Auth/`) — LoginRequest (with rate limiting), TwoFactorChallengeRequest
- **Middleware** — `HandleAppearance` (theme cookie), `EnsureFrontendRequestsAreStateful` (Sanctum SPA)
- **Routes**: `routes/web.php` (auth: login, logout, 2FA), `routes/api.php` (v1 API + user endpoint)

### Frontend (TypeScript/React) — `/dashboard/`

- **Pages** (`dashboard/src/pages/`) — login, two-factor-challenge, overview
- **Stores** (`dashboard/src/stores/`) — Zustand: auth (user + 2FA state), context (multi-tenancy), preferences
- **API** (`dashboard/src/api/`) — ky HTTP client with Sanctum CSRF, JSON:API helpers, domain endpoints
- **Router** (`dashboard/src/app/router.tsx`) — TanStack Router with auth guards
- **Entry point**: `dashboard/src/main.tsx`

### Stack Integration

Laravel serves JSON API. Dashboard SPA runs on Vite port 3000, proxies `/api` and `/sanctum` to Laravel port 8000. Authentication via Sanctum session cookies (not tokens).

## Tech Stack Details

- **PHP**: 8.3+ | **Laravel**: 13 | **Testing**: Pest PHP
- **Node**: 22 | **React**: 19 (with React Compiler) | **TypeScript**: 5.9
- **Build**: Vite 8 | **CSS**: Tailwind 4 | **UI**: Radix primitives
- **Auth**: Laravel Sanctum SPA + custom 2FA (google2fa)
- **State**: Zustand | **Routing**: TanStack Router | **Data**: React Query + ky
- **Queue**: Database driver
- **Code Style**: Pint (PHP, preset: laravel), ESLint + Prettier (TS/React)

## Testing

Backend tests use Pest PHP with `RefreshDatabase` trait and in-memory SQLite. Test structure: `tests/Feature/Auth/`, `tests/Feature/Api/`. Frontend tests use Vitest with happy-dom. CI runs tests against PHP 8.3, 8.4, 8.5.
