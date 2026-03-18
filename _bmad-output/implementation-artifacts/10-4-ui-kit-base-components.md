---
status: review
story_key: 10-4-ui-kit-base-components
epic: 10
---

# Story 10.4: UI-kit — базовые компоненты

## Story

As a developer, I want a library of reusable UI components, So that I can build pages quickly and consistently.

## Acceptance Criteria

**Given** SPA with Radix UI **When** UI kit created **Then** StatusBadge, CopyButton, MoneyFormat, DateFormat, JsonViewer, ConfirmDialog, EmptyState, ErrorState all work in light/dark themes

## Tasks/Subtasks

- [x] Task 1: StatusBadge (src/components/shared/status-badge.tsx) — color mapping per status
- [x] Task 2: CopyButton (src/components/shared/copy-button.tsx) — clipboard + tooltip
- [x] Task 3: MoneyFormat (src/components/shared/money-format.tsx) — minor→major, Intl.NumberFormat
- [x] Task 4: DateFormat (src/components/shared/date-format.tsx) — relative + absolute tooltip + user tz
- [x] Task 5: JsonViewer (src/components/shared/json-viewer.tsx) — collapsible tree, syntax, copy
- [x] Task 6: ConfirmDialog (src/components/shared/confirm-dialog.tsx) — Radix AlertDialog, destructive, requireInput
- [x] Task 7: EmptyState (src/components/shared/empty-state.tsx) — icon + title + hint + CTA
- [x] Task 8: ErrorState (src/components/shared/error-state.tsx) — 401→login, 404, 500, retry

## Dev Notes

- All components: light+dark via Tailwind dark: variants.
- Use Radix primitives (Tooltip, AlertDialog).
- cn() from lib/utils.ts for class merging.

## Dev Agent Record

### Implementation Plan
Red-green-refactor cycle per component. Each component gets tests first (Vitest + happy-dom), then implementation, then refinement. Using Radix primitives (Tooltip, AlertDialog), Lucide icons, cn() for class merging, Tailwind dark: variants. date-fns for DateFormat. All components in dashboard/src/components/shared/.

### Debug Log
- CopyButton: navigator.clipboard mock needed configurable property definition in happy-dom
- ConfirmDialog: onCancel double-fire from Radix onOpenChange — removed onOpenChange handler
- ErrorState: TypeScript strict null check on Record<number, ...> — extracted DEFAULT_CONFIG

### Completion Notes
All 8 shared UI components implemented with full test coverage (57 component tests). Each component:
- Supports light/dark themes via Tailwind dark: variants
- Uses cn() for className merging
- Has comprehensive unit tests (Vitest + @testing-library/react)
- Passes ESLint, Prettier, and TypeScript strict checks
- Zero regressions across 77 frontend tests and 238 backend tests

## File List
- dashboard/src/components/shared/status-badge.tsx (new)
- dashboard/src/components/shared/copy-button.tsx (new)
- dashboard/src/components/shared/money-format.tsx (new)
- dashboard/src/components/shared/date-format.tsx (new)
- dashboard/src/components/shared/json-viewer.tsx (new)
- dashboard/src/components/shared/confirm-dialog.tsx (new)
- dashboard/src/components/shared/empty-state.tsx (new)
- dashboard/src/components/shared/error-state.tsx (new)
- dashboard/src/components/shared/__tests__/status-badge.test.tsx (new)
- dashboard/src/components/shared/__tests__/copy-button.test.tsx (new)
- dashboard/src/components/shared/__tests__/money-format.test.tsx (new)
- dashboard/src/components/shared/__tests__/date-format.test.tsx (new)
- dashboard/src/components/shared/__tests__/json-viewer.test.tsx (new)
- dashboard/src/components/shared/__tests__/confirm-dialog.test.tsx (new)
- dashboard/src/components/shared/__tests__/empty-state.test.tsx (new)
- dashboard/src/components/shared/__tests__/error-state.test.tsx (new)
- dashboard/package.json (modified — added @testing-library/dom)

## Change Log
- 2026-03-18: Implemented all 8 shared UI components with tests (StatusBadge, CopyButton, MoneyFormat, DateFormat, JsonViewer, ConfirmDialog, EmptyState, ErrorState)

## Status
review
