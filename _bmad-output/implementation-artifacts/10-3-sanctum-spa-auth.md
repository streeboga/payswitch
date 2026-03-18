---
status: in-progress
story_key: 10-3-sanctum-spa-auth
epic: 10
---

# Story 10.3: Sanctum SPA аутентификация

## Story

As a user, I want to log in to the dashboard, So that I can manage the payment system.

## Acceptance Criteria

**Given** /login page **When** user enters email+password **Then** CSRF+POST /login **And** success → /overview **And** 2FA → code page **And** GET /api/v1/user returns user+role+orgs **And** auth store updated **And** /logout works **And** protected routes redirect to /login

## Tasks/Subtasks

- [ ] Task 1: Auth store (src/stores/auth.ts) — user, isAuthenticated, isLoading, organizations, actions
- [ ] Task 2: Login page (src/pages/login.tsx) — form, RHF+Zod, CSRF→POST /login, errors, 2FA redirect
- [ ] Task 3: 2FA page (src/pages/two-factor-challenge.tsx) — OTP/recovery input
- [ ] Task 4: Auth guard — route protection, redirect to /login, fetch user on load
- [ ] Task 5: Logout — POST /logout → clear → redirect

## Dev Notes

- Sanctum SPA: cookies not tokens. Same domain or CORS.
- Backend may need GET /api/v1/user endpoint.

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
in-progress
