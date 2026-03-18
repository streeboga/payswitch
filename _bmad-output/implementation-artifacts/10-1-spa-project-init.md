---
status: ready-for-dev
story_key: 10-1-spa-project-init
epic: 10
---

# Story 10.1: Инициализация SPA проекта

## Story

As a frontend developer,
I want an initialized React SPA project with all dependencies,
So that I can start building the dashboard.

## Acceptance Criteria

**Given** a new SPA project directory `dashboard/`
**When** configured
**Then** Vite 7 + React 19 + TypeScript 5.7 builds successfully
**And** TanStack Router with file-based routing works
**And** TanStack Query provider is configured
**And** Zustand stores initialized (auth, context, preferences)
**And** Tailwind CSS 4 is configured with dark mode support
**And** Radix UI is installed
**And** Project structure matches plan (api/, components/, hooks/, pages/, stores/, lib/)
**And** ESLint + Prettier configured
**And** `npm run dev` starts dev server
**And** `npm run build` produces production build

## Tasks/Subtasks

- [ ] Task 1: Create `dashboard/` directory with package.json and install deps
  - [ ] npm init, type: module
  - [ ] Core: react, react-dom, typescript, vite, @vitejs/plugin-react
  - [ ] Routing: @tanstack/react-router, @tanstack/router-devtools, @tanstack/router-plugin
  - [ ] Data: @tanstack/react-query, @tanstack/react-query-devtools
  - [ ] State: zustand
  - [ ] UI: @radix-ui/react-dialog, @radix-ui/react-dropdown-menu, @radix-ui/react-select, @radix-ui/react-toggle, @radix-ui/react-tooltip, @radix-ui/react-checkbox, @radix-ui/react-separator, @radix-ui/react-switch, @radix-ui/react-popover, @radix-ui/react-scroll-area, @radix-ui/react-alert-dialog, @radix-ui/react-tabs
  - [ ] Styles: tailwindcss, @tailwindcss/vite, tailwind-merge, clsx
  - [ ] Forms: react-hook-form, @hookform/resolvers, zod
  - [ ] Utils: ky, sonner, lucide-react, date-fns, @date-fns/tz, recharts, @dnd-kit/core, @dnd-kit/sortable
  - [ ] Dev: @types/react, @types/react-dom, eslint, prettier, vitest, @testing-library/react, @testing-library/jest-dom, happy-dom

- [ ] Task 2: Configure Vite + TypeScript
  - [ ] vite.config.ts: React plugin, TanStack Router plugin, path aliases (@/ → src/)
  - [ ] tsconfig.json: strict, paths, jsx: react-jsx
  - [ ] index.html entry point

- [ ] Task 3: Configure Tailwind CSS 4
  - [ ] src/app.css: @import "tailwindcss"
  - [ ] Dark mode via class strategy

- [ ] Task 4: Create project structure
  - [ ] src/api/client.ts (placeholder)
  - [ ] src/api/endpoints/, src/api/types/ (empty with .gitkeep)
  - [ ] src/app/App.tsx (root with providers), src/app/router.tsx
  - [ ] src/components/ui/, src/components/shared/, src/components/data-table/, src/components/context-switcher/
  - [ ] src/hooks/
  - [ ] src/pages/ (placeholder index)
  - [ ] src/stores/auth.ts, context.ts, preferences.ts (skeletons)
  - [ ] src/lib/utils.ts (cn helper)
  - [ ] src/main.tsx (entry)

- [ ] Task 5: Configure ESLint + Prettier
  - [ ] eslint.config.js
  - [ ] .prettierrc
  - [ ] Scripts: dev, build, preview, lint, format, types:check, test

- [ ] Task 6: Verify build
  - [ ] npm run build succeeds
  - [ ] npm run dev starts
  - [ ] npm run lint passes
  - [ ] npm run types:check passes

## Dev Notes

- SEPARATE project from Laravel. Lives in `dashboard/` at repo root.
- NOT Inertia. Standalone SPA talking to `/api/v1/` REST API.
- TanStack Router with file-based routing via vite plugin.
- Zustand stores are skeletons only — logic in later stories.
- API client placeholder — config in story 10-2.

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
