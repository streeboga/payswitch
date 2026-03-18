---
status: ready-for-dev
story_key: 11-6-notification-center
epic: 11
---
# Story 11.6: Notification Center
## Story
As a user, I want notifications about important events, So that I don't miss critical situations.
## Acceptance Criteria
**Given** bell in header **When** clicked **Then** dropdown with notifications **And** badge unread count **And** click → navigate **And** mark all read **And** polling 30s
## Tasks/Subtasks
- [ ] Task 1: Notifications store — unreadCount, list, markRead, markAllRead
- [ ] Task 2: NotificationBell — **shadcn Popover** + **shadcn Badge**, bell icon (Lucide)
- [ ] Task 3: NotificationPanel — list with icon, text, timestamp, click
- [ ] Task 4: Polling — useQuery refetchInterval 30s for unread-count
- [ ] Task 5: Mark read — POST /notifications/mark-read
## Dev Notes
Backend needs: GET /notifications, GET /notifications/unread-count, POST /notifications/mark-read. Max 50 in dropdown.
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
