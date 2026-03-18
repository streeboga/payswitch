---
status: ready-for-dev
story_key: 11-4-breadcrumbs
epic: 11
---
# Story 11.4: Breadcrumbs
## Story
As a user, I want breadcrumbs on nested pages, So that I can navigate back.
## Acceptance Criteria
**Given** nested page **When** rendered **Then** "Платежи → pay_abc123" **And** segments clickable (except last) **And** last bold
## Tasks/Subtasks
- [ ] Task 1: Breadcrumb component — shadcn/ui не имеет breadcrumb, написать свой с **shadcn Separator** + cn()
- [ ] Task 2: useBreadcrumbs hook — reads TanStack Router matches
- [ ] Task 3: Integration — place in layout below header
## Dev Notes
TanStack Router matches provide route hierarchy for breadcrumbs.
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
