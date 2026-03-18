---
status: review
story_key: 10-2-api-client-types
epic: 10
---

# Story 10.2: API-клиент и типы

## Story

As a developer, I want a typed API client, So that the SPA can safely interact with the backend.

## Acceptance Criteria

**Given** the SPA project **When** API client created **Then** ky configured with base URL, CSRF, error interceptors **And** TypeScript types for all JSON:API responses exist **And** endpoint modules per resource **And** 401 → redirect /login **And** errors parsed into ErrorResponse

## Tasks/Subtasks

- [x] Task 1: API client (src/api/client.ts) — ky with prefixUrl, CSRF, credentials, error interceptor, typed helpers
- [x] Task 2: Types (src/api/types/) — all entities + enums + JSON:API wrappers
- [x] Task 3: Endpoints (src/api/endpoints/) — typed modules per resource (auth, payments, merchants, etc.)
- [x] Task 4: TanStack Query hooks (src/hooks/use-api.ts) — generic wrappers + query key factory

## Dev Notes

- JSON:API format: { data: { type, id, attributes, relationships, links } }
- CSRF: GET /sanctum/csrf-cookie → XSRF-TOKEN cookie → X-XSRF-TOKEN header

## Dev Agent Record

### Implementation Plan
- Task 1: ky client with JSON:API content type, CSRF auto-attach for mutations, 401→redirect, error parsing into ApiError class
- Task 2: Generic JSON:API types (JsonApiResource, Document, Collection, Pagination, Errors), entity attribute types for all 11 models, enum types mirroring PHP enums with label maps
- Task 3: Typed endpoint modules for auth, organizations, merchants, profiles, api-keys, connectors, routing-rules, payments, refunds, customers, payment-methods. Shared ListParams + buildSearchParams for JSON:API filter/sort/page/include
- Task 4: Query key factory with hierarchical keys per entity. Generic hooks: useJsonApiResource, useJsonApiCollection, useJsonApiMutation with auto-invalidation

### Debug Log
- Fixed ApiError class/import conflict — class defined in client.ts, not types
- Fixed unused getResource import in organizations.ts
- Fixed Prettier formatting in test file

### Completion Notes
All 4 tasks implemented and tested. 14 unit tests cover: ApiError construction, JSON:API extractAttributes/extractCollectionAttributes helpers, enum constants, and buildSearchParams for JSON:API query format. TypeScript strict mode passes, ESLint and Prettier clean, Vite build succeeds.

## File List
- dashboard/src/api/client.ts (modified — full ky client with CSRF, error handling, typed helpers)
- dashboard/src/api/types/json-api.ts (new — JSON:API v1.1 generic types + helpers)
- dashboard/src/api/types/enums.ts (new — all backend enum types + label maps)
- dashboard/src/api/types/entities.ts (new — attribute interfaces for all 11 entities)
- dashboard/src/api/types/index.ts (new — barrel export)
- dashboard/src/api/endpoints/auth.ts (new — Sanctum auth: login, logout, user, csrf)
- dashboard/src/api/endpoints/organizations.ts (new — create)
- dashboard/src/api/endpoints/merchants.ts (new — get, create)
- dashboard/src/api/endpoints/profiles.ts (new — get, create)
- dashboard/src/api/endpoints/api-keys.ts (new — create, revoke)
- dashboard/src/api/endpoints/connectors.ts (new — CRUD + list)
- dashboard/src/api/endpoints/routing-rules.ts (new — CRUD + list)
- dashboard/src/api/endpoints/payments.ts (new — get, create, confirm, capture, cancel)
- dashboard/src/api/endpoints/refunds.ts (new — get, create)
- dashboard/src/api/endpoints/customers.ts (new — CRUD + list)
- dashboard/src/api/endpoints/payment-methods.ts (new — CRUD + list + setDefault)
- dashboard/src/api/endpoints/params.ts (new — ListParams type + buildSearchParams)
- dashboard/src/api/endpoints/index.ts (new — barrel export)
- dashboard/src/hooks/use-api.ts (new — query key factory + generic TanStack Query hooks)
- dashboard/src/api/__tests__/client.test.ts (new — ApiError tests)
- dashboard/src/api/__tests__/types.test.ts (new — JSON:API helpers + enum tests)
- dashboard/src/api/__tests__/params.test.ts (new — buildSearchParams tests)
- dashboard/vite.config.ts (modified — added vitest test config)

## Change Log
- 2026-03-18: Story 10.2 implemented — API client, TypeScript types, endpoint modules, TanStack Query hooks

## Status
review
