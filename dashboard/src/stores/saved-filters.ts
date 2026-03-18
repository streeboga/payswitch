import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import type { TFunction } from 'i18next'

// ─── Types ──────────────────────────────────────────────────

export interface SavedFilter {
  id: string
  name: string
  page: string
  params: Record<string, string>
}

interface SavedFiltersState {
  filters: SavedFilter[]
  save: (filter: Omit<SavedFilter, 'id'>) => void
  remove: (id: string) => void
  rename: (id: string, name: string) => void
}

// ─── Built-in Presets ───────────────────────────────────────

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

export function getFilterPresets(t: TFunction): SavedFilter[] {
  const d = today()
  return [
    {
      id: 'preset-failed-today',
      name: t('savedFilters.failedToday'),
      page: 'payments',
      params: { status: 'failed', date: `${d},${d}` },
    },
    {
      id: 'preset-requires-capture',
      name: t('savedFilters.requiresCapture'),
      page: 'payments',
      params: { status: 'requires_capture' },
    },
    {
      id: 'preset-undelivered-webhooks',
      name: t('savedFilters.undeliveredWebhooks'),
      page: 'webhooks',
      params: { delivered: 'false' },
    },
  ]
}

/** @deprecated Use getFilterPresets(t) instead — this evaluates today() at module load */
export const FILTER_PRESETS: SavedFilter[] = [
  {
    id: 'preset-failed-today',
    name: 'Неуспешные за сегодня',
    page: 'payments',
    params: { status: 'failed', date: `${today()},${today()}` },
  },
  {
    id: 'preset-requires-capture',
    name: 'Требуют capture',
    page: 'payments',
    params: { status: 'requires_capture' },
  },
  {
    id: 'preset-undelivered-webhooks',
    name: 'Недоставленные вебхуки',
    page: 'webhooks',
    params: { delivered: 'false' },
  },
]

// ─── Store ──────────────────────────────────────────────────

export const useSavedFiltersStore = create<SavedFiltersState>()(
  persist(
    (set) => ({
      filters: [],

      save: (filter) =>
        set((state) => ({
          filters: [
            ...state.filters,
            {
              ...filter,
              id: `sf-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
            },
          ],
        })),

      remove: (id) =>
        set((state) => ({
          filters: state.filters.filter((f) => f.id !== id),
        })),

      rename: (id, name) =>
        set((state) => ({
          filters: state.filters.map((f) => (f.id === id ? { ...f, name } : f)),
        })),
    }),
    { name: 'payswitch-saved-filters', version: 1 },
  ),
)
