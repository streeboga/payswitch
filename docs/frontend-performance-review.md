# Ревью фронтенда по Vercel React Best Practices

**Дата:** 2026-03-18
**Scope:** `/dashboard/src/`

---

## CRITICAL — Bundle Size

### 1. Все 29 страниц загружаются статически в роутере

**Файл:** `src/app/router.tsx:8-36`
**Правило:** `bundle-dynamic-imports`

Все страницы импортируются статически — весь код попадает в один бандл. Пользователь загружает код Disputes, AuditLog, ConnectorHealth ещё до логина.

**Исправление:** Использовать lazy routes TanStack Router:

```tsx
const overviewRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/overview',
  component: lazy(() => import('@/pages/overview').then(m => ({ default: m.OverviewPage }))),
  beforeLoad: requireAuth,
})
```

Или `route.lazy()` API. Страницы overview и login можно оставить eager, остальные 25+ — lazy.

**Импакт:** Самый большой выигрыш. Сейчас initial bundle включает код recharts, @dnd-kit, react-hook-form, zod, json-viewer и т.д. для всех страниц.

---

### 2. ReactQueryDevtools грузится в прод

**Файл:** `src/app/App.tsx:2,21`
**Правило:** `bundle-defer-third-party`

```tsx
import { ReactQueryDevtools } from '@tanstack/react-query-devtools'
```

Eagerly импортируется всегда. В проде это мёртвый вес (~50KB).

**Исправление:**

```tsx
const ReactQueryDevtools = import.meta.env.DEV
  ? lazy(() =>
      import('@tanstack/react-query-devtools').then((m) => ({
        default: m.ReactQueryDevtools,
      })),
    )
  : () => null
```

---

### 3. Barrel-файл `data-table/index.ts` импортируется 16 страницами

**Файл:** `src/components/data-table/index.ts`
**Правило:** `bundle-barrel-imports`

Каждый `import { DataTable } from '@/components/data-table'` подтягивает все экспорты: `SavedFiltersMenu`, `TableExport`, `TableSelection` и т.д. — даже если страница использует только `DataTable`.

Vite с tree-shaking **может** убрать неиспользуемое, но barrel усложняет анализ и замедляет HMR. При lazy routes это менее критично, но всё равно лучше импортировать напрямую.

---

## HIGH — Re-render Optimization

### 4. Zustand stores подписаны без селекторов

**Правило:** `rerender-derived-state`, `rerender-defer-reads`

| Файл | Код | Проблема |
|---|---|---|
| `root-layout.tsx:19` | `useAuthStore()` | Подписка на весь store вместо `(s) => s.isAuthenticated` |
| `sidebar.tsx:17` | `useAuthStore()` | Подписка на весь store (isLoading, requiresTwoFactor тоже триггерят ре-рендер) |
| `sidebar.tsx:18` | `usePreferencesStore()` | Подписка на theme, density, timezone — не нужные sidebar |
| `context-switcher.tsx:28` | `useContextStore()` | Деструктурирует всё, ре-рендер при смене testMode |
| `test-live-toggle.tsx:10-11` | `useContextStore()` + `usePreferencesStore()` | Аналогично |
| `login.tsx:30` | `useAuthStore()` | Подписка на user, isAuthenticated — не нужны на странице логина |

**Исправление:** Использовать селекторы:

```tsx
// Было
const { user, clearUser } = useAuthStore()

// Стало
const user = useAuthStore((s) => s.user)
const clearUser = useAuthStore((s) => s.clearUser)
```

---

### 5. `densityPadding` пересоздаётся каждый рендер

**Файл:** `src/components/data-table/data-table.tsx:73-77`
**Правило:** `rendering-hoist-jsx`

```tsx
// Внутри компонента — новая ссылка каждый рендер
const densityPadding: Record<Density, string> = {
  compact: 'py-1',
  comfortable: 'py-2',
  spacious: 'py-3',
}
```

Вынести за компонент как константу модуля.

---

### 6. Fragment без key в DataTable

**Файл:** `src/components/data-table/data-table.tsx:192`
**Правило:** `rendering-conditional-render`

```tsx
rows.map((row) => (
  <>  {/* Fragment без key */}
    <TableRow key={row.id} ...>
```

React требует key на внешнем элементе списка. Нужно `<Fragment key={row.id}>`.

---

## MEDIUM — Client-Side Performance

### 7. N отдельных `keydown` listeners вместо одного

**Файл:** `src/hooks/use-hotkey.ts:142-147`
**Правило:** `client-event-listeners`

Каждый вызов `useHotkey()` вешает свой `addEventListener('keydown', ...)`. В `useNavigationShortcuts` это 5 листенеров + ещё `CommandPalette` = 6+ на одном `keydown`.

**Исправление:** Одна глобальная подписка с Map хоткеев внутри.

---

### 8. `invalidateQueries()` без фильтра

**Файл:** `src/components/context-switcher/context-switcher.tsx:40-41`

```tsx
const invalidateAll = useCallback(() => {
  void queryClient.invalidateQueries()
}, [queryClient])
```

Инвалидирует **весь** кеш React Query, включая орги, мерчанты, профили, аналитику — даже то, что не зависит от контекста. Лучше инвалидировать по предикату:

```tsx
queryClient.invalidateQueries({
  predicate: (query) =>
    !['organizations'].includes(query.queryKey[0] as string),
})
```

---

### 9. `today()` в `FILTER_PRESETS` вычисляется один раз при загрузке модуля

**Файл:** `src/stores/saved-filters.ts:22-31`

```tsx
function today(): string {
  return new Date().toISOString().slice(0, 10)
}

export const FILTER_PRESETS: SavedFilter[] = [
  { params: { date: `${today()},${today()}` } },
]
```

Если вкладка открыта с вечера — утром пресет "за сегодня" покажет вчерашние данные. `today()` нужно вычислять при применении фильтра, не при инициализации модуля.

---

### 10. `new Set()` на каждый `keydown`

**Файл:** `src/hooks/use-hotkey.ts:52`

```tsx
const required = new Set(modifiers ?? [])
```

Создаётся на каждое нажатие клавиши. Для оптимизации — кешировать Set в ref или проверять модификаторы напрямую массивом (массив из 0-4 элементов, `includes()` будет быстрее).

---

## Сводка по приоритетам

| Приоритет | # | Суть | Импакт |
|---|---|---|---|
| CRITICAL | 1 | Lazy routes для 25+ страниц | Уменьшение initial bundle в 3-5x |
| CRITICAL | 2 | Lazy-import ReactQueryDevtools | -50KB в проде |
| HIGH | 4 | Zustand селекторы (6 мест) | Меньше лишних ре-рендеров |
| MEDIUM | 6 | Fragment key в DataTable | React warning + reconciliation баг |
| MEDIUM | 7 | Один keydown listener | Меньше нагрузки на event loop |
| MEDIUM | 8 | Точечная инвалидация кеша | Меньше лишних запросов |
| LOW | 3, 5, 9, 10 | Barrel, hoist, today(), Set | Мелкие оптимизации |
