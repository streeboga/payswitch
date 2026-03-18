---
status: ready-for-dev
story_key: 11-5-global-search-command-palette
epic: 11
---
# Story 11.5: Глобальный поиск (Command Palette)
## Story
As a user, I want to search entities via ⌘K, So that I find things instantly.
## Acceptance Criteria
**Given** ⌘K **When** overlay opens **Then** search by ID/name/email **And** quick actions **And** navigation **And** grouped results **And** keyboard nav **And** debounce 300ms
## Tasks/Subtasks
- [ ] Task 1: CommandPalette component — **shadcn Command** (cmdk) + **shadcn Dialog**, input, results, keyboard
- [ ] Task 2: Search API — GET /api/v1/search?q=...&types=... debounced
- [ ] Task 3: Result rendering — grouped by type, icons
- [ ] Task 4: Quick actions — static action list filtered by query
- [ ] Task 5: Navigation items — page list filtered by query
- [ ] Task 6: Keyboard nav — ↑↓ Enter Escape
## Dev Notes
Backend needs: GET /api/v1/search endpoint. Cache recent searches in memory.
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
