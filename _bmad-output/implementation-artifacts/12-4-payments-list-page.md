---
status: ready-for-dev
story_key: 12-4-payments-list-page
epic: 12
---
# Story 12-4-payments-list-page
## Story
(See epics.md for full story description)
## Acceptance Criteria
(See epics.md for full acceptance criteria)
## Tasks/Subtasks
- [ ] Task 1: Payments page — DataTable with columns (ID link, amount MoneyFormat, currency, status StatusBadge, connector, customer, capture method, attempts, date DateFormat)
- [ ] Task 2: Filters — status multi-select, connector, currency, amount range, date range, capture method, ID search
- [ ] Task 3: CSV export button
## Dev Notes
- UI kit: shadcn/ui components (Select, Input, Button)
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
