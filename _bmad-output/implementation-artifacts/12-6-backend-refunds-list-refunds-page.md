---
status: ready-for-dev
story_key: 12-6-backend-refunds-list-refunds-page
epic: 12
---
# Story 12-6-backend-refunds-list-refunds-page
## Story
(See epics.md for full story description)
## Acceptance Criteria
(See epics.md for full acceptance criteria)
## Tasks/Subtasks
- [ ] Task 1: Backend — GET /api/v1/refunds with filters + pagination
- [ ] Task 2: Refunds page — DataTable (ID, payment link, amount, currency, status, reason, connector, date)
- [ ] Task 3: Filters — status, date, ID search
## Dev Notes
- UI kit: shadcn/ui components (Select, Input, Button, Badge)
- DataTable from 10-5 for list
- Shared components from 10-4: StatusBadge, MoneyFormat, DateFormat
- All filter state via URL search params (shareable URLs) + React Query
- See epics.md and docs/plans/2026-03-18-dashboard-design.md for full details.
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
