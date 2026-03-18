---
status: ready-for-dev
story_key: 10-6-keyboard-shortcuts
epic: 10
---

# Story 10.6: Keyboard shortcuts

## Story

As a user, I want keyboard shortcuts, So that I can navigate faster.

## Acceptance Criteria

**Given** SPA loaded **When** ⌘K **Then** Command Palette placeholder opens **And** g+p→Payments, g+r→Refunds, g+c→Customers, g+o→Overview **And** ↑↓ Enter Space in tables **And** Escape closes dialogs **And** disabled in input/textarea **And** ⌘/ shows help

## Tasks/Subtasks

- [ ] Task 1: useHotkey hook (`src/hooks/use-hotkey.ts`) — key, callback, modifiers, sequences (g+p), input guard (disable in input/textarea/contenteditable)
- [ ] Task 2: Navigation shortcuts (`src/hooks/use-navigation-shortcuts.ts`) — g+p/r/c/o/s → router navigate
- [ ] Task 3: Table shortcuts (`src/hooks/use-table-shortcuts.ts`) — ↑↓ Enter Space ⌘A, интеграция с DataTable
- [ ] Task 4: Help dialog (`src/components/shared/shortcuts-help.tsx`) — ⌘/ trigger, grouped shortcut list, shadcn Dialog
- [ ] Task 5: ⌘K placeholder — shadcn Command component (empty Command Palette, полный в 11-5)

## Dev Notes

- Key sequences: buffer first press, wait 1s for second.
- Register centrally, expose for help dialog.
- Disable when shadcn Dialog/AlertDialog open (except Escape).
- shadcn `Command` компонент (cmdk) — идеальная основа для Command Palette.
- Тесты: Vitest для useHotkey hook.

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
