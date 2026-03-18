---
status: ready-for-dev
story_key: 11-3-sidebar-navigation
epic: 11
---
# Story 11.3: Sidebar навигация
## Story
As a user, I want sidebar navigation, So that I can quickly switch sections.
## Acceptance Criteria
**Given** sidebar **When** rendered **Then** 5 groups **And** badges on Disputes/Webhooks **And** icon-only collapse **And** mobile sheet **And** active highlight **And** Management admin-only
## Tasks/Subtasks
- [ ] Task 1: Sidebar layout component — **shadcn Collapsible** + **shadcn Sheet** for mobile
- [ ] Task 2: Nav items config — groups array with items, Lucide icons
- [ ] Task 3: NavItem — active state via TanStack Router, icon+label, **shadcn Badge** for counts
- [ ] Task 4: Collapse — icon-only mode, **shadcn Button** toggle, persist in preferences store
- [ ] Task 5: Mobile responsive — **shadcn Sheet** for <768px
- [ ] Task 6: Admin visibility — hide Management for non-admins
## Dev Notes
Groups: Операции, Конфигурация, Разработка, Управление(admin), Аккаунт. Lucide icons. Width: 240px/64px.
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
