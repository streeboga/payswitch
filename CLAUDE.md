# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Payswitch — Laravel 13 + React 19 full-stack application with Inertia.js. Authentication powered by Laravel Fortify (login, registration, 2FA, email verification, password reset). Frontend uses TypeScript, Tailwind CSS 4, and Radix UI components.

## Common Commands

```bash
# Full project setup (install deps, key generate, migrate, build)
composer setup

# Development (runs server, queue, logs, vite concurrently)
composer dev
composer dev:ssr          # with SSR

# Testing
composer test             # lint + tests
./vendor/bin/pest         # run all tests
./vendor/bin/pest --filter=TestName   # single test
./vendor/bin/pest tests/Feature/Auth  # test directory

# PHP linting
composer lint             # fix with Pint
composer lint:check       # check only

# Frontend linting & formatting
npm run lint              # ESLint fix
npm run lint:check        # ESLint check
npm run format            # Prettier fix
npm run format:check      # Prettier check
npm run types:check       # TypeScript type checking

# Full CI check
composer ci:check         # lint + format + types + tests

# Build
npm run build
npm run build:ssr
```

## Architecture

### Backend (PHP)

- **Actions** (`app/Actions/Fortify/`) — Fortify authentication actions (user creation, password reset)
- **Concerns** (`app/Concerns/`) — Shared traits for validation rules (reused across actions and form requests)
- **Controllers** (`app/Http/Controllers/Settings/`) — Profile and security settings
- **Form Requests** (`app/Http/Requests/Settings/`) — Validation for profile, password, account deletion, 2FA
- **Middleware** — `HandleInertiaRequests` (shares auth/appearance data), `HandleAppearance` (theme cookie)
- **Routes**: `routes/web.php` (main), `routes/settings.php` (authenticated settings routes)

### Frontend (TypeScript/React)

- **Pages** (`resources/js/pages/`) — Inertia pages mapped to routes: `auth/`, `settings/`, `dashboard.tsx`, `welcome.tsx`
- **Components** (`resources/js/components/ui/`) — Radix UI-based component library
- **Wayfinder** (`resources/js/wayfinder/`) — Auto-generated typed route helpers from Laravel routes
- **Entry points**: `app.tsx` (client), `ssr.tsx` (server-side rendering)

### Stack Integration

Inertia.js bridges Laravel backend and React frontend — controllers return `Inertia::render()` responses, React pages receive props. Wayfinder generates typed route functions from PHP routes.

## Tech Stack Details

- **PHP**: 8.3+ | **Laravel**: 13 | **Testing**: Pest PHP
- **Node**: 22 | **React**: 19 (with React Compiler) | **TypeScript**: 5.7
- **Build**: Vite 7 | **CSS**: Tailwind 4 | **UI**: Radix primitives
- **Auth**: Laravel Fortify (with 2FA support)
- **Queue**: Database driver
- **Code Style**: Pint (PHP, preset: laravel), ESLint + Prettier (TS/React)

## Testing

Tests use Pest PHP with `RefreshDatabase` trait and in-memory SQLite. Test structure mirrors app structure: `tests/Feature/Auth/`, `tests/Feature/Settings/`. CI runs tests against PHP 8.3, 8.4, 8.5.
