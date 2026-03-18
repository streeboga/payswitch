---
status: ready-for-dev
story_key: 11-2-test-live-toggle
epic: 11
---
# Story 11.2: Test/Live toggle
## Story
As a user, I want to switch between test and live modes, So that I see appropriate data.
## Acceptance Criteria
**Given** header toggle **When** switch to Live **Then** confirm dialog **And** red indication **And** data filtered by test_mode **And** test data purple badge
## Tasks/Subtasks
- [ ] Task 1: testMode in context store — boolean, default true, persist
- [ ] Task 2: TestLiveToggle component — **shadcn Switch**, red for Live, ConfirmDialog (shadcn AlertDialog)
- [ ] Task 3: API scoping — test_mode param
- [ ] Task 4: Visual indicators — **shadcn Badge** purple for test, red accent for Live
## Dev Notes
MerchantConnectorAccount has test_mode field. Filter data accordingly.
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
