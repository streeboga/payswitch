---
status: ready-for-dev
story_key: 11-1-context-switcher-org-merchant-profile
epic: 11
---

# Story 11.1: Контекстный переключатель Org → Merchant → Profile

## Story

As an admin, I want to switch between organizations, merchants and profiles, So that I work in the correct context.

## Acceptance Criteria

**Given** header **When** user selects org **Then** merchants load cascadingly **And** profiles load on merchant select **And** "All profiles" available **And** persist localStorage **And** invalidates TanStack Query cache **And** API scoped to context **And** merchant-users see single context

## Tasks/Subtasks

- [ ] Task 1: Backend — dashboard-scoped list endpoints (`auth:sanctum`, scoped by user):
  - `GET /api/v1/organizations` → list user's organizations
  - `GET /api/v1/organizations/{key}/merchants` → list merchants for org
  - `GET /api/v1/merchants/{key}/profiles` → list profiles for merchant
- [ ] Task 2: Context store (`src/stores/context.ts`) — добавить каскадный сброс (setOrg → сбросить merchant+profile)
- [ ] Task 3: Context API hooks — `useOrganizations()`, `useMerchants(orgKey)`, `useProfiles(merchantKey)` через React Query
- [ ] Task 4: ContextSwitcher component — 3 каскадных **shadcn Select** в header
- [ ] Task 5: Query invalidation — при смене контекста инвалидировать data-кэши React Query
- [ ] Task 6: API request scoping — inject merchant context в requests через ky hook

## Dev Notes

- Backend: `auth:sanctum` middleware, JSON:API формат ответов.
- **shadcn Select** для dropdown'ов (установлен в 10-5).
- Тесты: backend (Pest) + frontend (Vitest) для store cascading.

## Dev Agent Record

### Implementation Plan
(To be filled)

### Debug Log
(To be filled)

### Completion Notes
(To be filled)

## File List
(To be filled)

## Change Log
(To be filled)

## Status
ready-for-dev
