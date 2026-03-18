---
status: ready-for-dev
story_key: 10-5-data-table-component
epic: 10
---

# Story 10.5: shadcn/ui init + DataTable компонент

## Story

As a user, I want powerful tables with filters and sorting, So that I can work with data efficiently.

## Approach

**Перед DataTable — инициализировать shadcn/ui** в `dashboard/`. Это даёт нам готовые компоненты (Button, Input, Table, Select, DropdownMenu, etc.) которые используются внутри DataTable и во всех последующих сториях. Затем рефакторим login/2FA страницы и shared-компоненты на shadcn/ui примитивы.

## Acceptance Criteria

**Given** TanStack Table **When** DataTable created **Then** server sorting, pagination 20/50/100, sticky headers, bulk selection, data density, filter panel, CSV export, empty/loading/error states, mobile cards

## Tasks/Subtasks

### Phase 0: shadcn/ui bootstrap

- [ ] Task 0.1: `npx shadcn@latest init` — style "new-york", CSS variables, `@/components/ui` path
- [ ] Task 0.2: Установить shadcn/ui примитивы через `npx shadcn@latest add`:
  - Формы: `button`, `input`, `label`, `form`, `select`, `checkbox`, `switch`, `toggle`, `toggle-group`, `textarea`
  - Оверлеи: `dialog`, `alert-dialog`, `sheet`, `popover`, `tooltip`, `dropdown-menu`, `command`
  - Отображение: `card`, `badge`, `separator`, `skeleton`, `alert`, `avatar`, `table`, `tabs`, `collapsible`, `navigation-menu`
  - Sonner уже установлен — проверить совместимость
- [ ] Task 0.3: Рефакторить `pages/login.tsx` на shadcn (Card, Button, Input, Label, Alert)
- [ ] Task 0.4: Рефакторить `pages/two-factor-challenge.tsx` на shadcn
- [ ] Task 0.5: Рефакторить `root-layout.tsx` — header на shadcn Button
- [ ] Task 0.6: Рефакторить shared-компоненты из 10-4 на shadcn примитивы:
  - `confirm-dialog.tsx` → использовать shadcn AlertDialog
  - `copy-button.tsx` → использовать shadcn Button + Tooltip
  - `status-badge.tsx` → использовать shadcn Badge
  - `json-viewer.tsx` → использовать shadcn Collapsible
  - `error-state.tsx` → использовать shadcn Alert + Button
  - `empty-state.tsx` → использовать shadcn Button

### Phase 1: DataTable core

- [ ] Task 1: Core DataTable (`src/components/data-table/data-table.tsx`) — TanStack Table v8, shadcn Table, sticky thead, responsive
- [ ] Task 2: Column definitions — generic column helpers, accessor functions

### Phase 2: DataTable features

- [ ] Task 3: Sorting (`use-table-sorting.ts`) — URL params (TanStack Router search params), column header toggle, sort arrows
- [ ] Task 4: Pagination (`table-pagination.tsx`) — shadcn Select для per-page (20/50/100), page buttons, URL sync
- [ ] Task 5: Bulk selection (`table-selection.tsx`) — shadcn Checkbox, toolbar с actions, useTableSelection hook
- [ ] Task 6: Filter panel (`table-filters.tsx`) — shadcn Collapsible, типы фильтров (select, date range, number range), badge count, reset, URL sync
- [ ] Task 7: Data density (`table-density.tsx`) — compact/comfortable/spacious, preferences store integration
- [ ] Task 8: CSV export (`table-export.tsx`) — API call с текущими фильтрами, blob download, shadcn Button
- [ ] Task 9: States — shadcn Skeleton для loading, EmptyState (из shared), ErrorState с retry

### Phase 3: Mobile

- [ ] Task 10: Mobile cards — responsive breakpoint (<768px), карточки вместо строк, 3-4 ключевых поля

## Dev Notes

- **shadcn/ui** инициализация делается один раз, потом все стори используют готовые компоненты.
- TanStack Table v8, manual sorting/pagination (server-side).
- Все state в URL params для shareable URLs (TanStack Router search params).
- Mobile cards: 3-4 most important fields.
- Тесты: Vitest + Testing Library для DataTable и фильтров.

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
