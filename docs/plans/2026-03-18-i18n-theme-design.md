# Design: i18n + Theme Switching

**Date:** 2026-03-18
**Scope:** `/dashboard/` frontend only

## Theme (dark/light/system)

- Wrap `App` in next-themes `ThemeProvider` with `attribute="class"` — applies `.dark` on `<html>`
- Remove `theme` from Zustand `preferencesStore` — next-themes manages its own localStorage
- Sonner already uses `useTheme()` from next-themes — works automatically
- Tailwind 4 dark mode CSS variables already defined in `app.css` — no changes needed
- Toggle in sidebar footer: Sun/Moon icon button, tooltip when collapsed

## i18n (i18next + react-i18next)

- Dependencies: `i18next`, `react-i18next`, `i18next-browser-languagedetector`
- One file per language: `src/locales/ru.json`, `src/locales/en.json`
- Init in `src/lib/i18n.ts`, imported in `main.tsx` before app render
- `<html lang>` and `<html dir>` updated via useEffect on language change
- Language stored in localStorage via i18next detector (not Zustand)
- Toggle in sidebar footer: "RU" / "EN", tooltip when collapsed

## Sidebar Footer Layout

```
Expanded:  [Sun/Moon]  [RU|EN]         [Свернуть ◀]
Collapsed: [☀] [RU] [◀]  (all with tooltips)
```

## Settings Page Updates

- Appearance tab: theme via `useTheme()` instead of Zustand
- Regional tab: add language selector (Select dropdown)

## Translation Scope

All hardcoded strings across ~30 pages and ~65 components. Flat dot-notation keys:
- `common.*` — shared (loading, error, save, cancel, etc.)
- `sidebar.*` — navigation labels
- `payments.*`, `refunds.*`, etc. — page-specific
- `settings.*` — settings page

## RTL Readiness

- `dir="rtl"` on `<html>` when RTL language is active
- Add `@custom-variant rtl (&:is([dir=rtl] *))` to `app.css`
- Logical CSS properties (ms-/me- instead of ml-/mr-) — deferred, not changing layout now
- Any future RTL language just needs a translation file + RTL flag in i18n config
