import { useCallback, useMemo } from 'react'
import { useNavigate, useSearch } from '@tanstack/react-router'
import type { SortingState } from '@tanstack/react-table'
import { navigateWithSearch } from './navigate-search'

interface UseTableSortingOptions {
  defaultSort?: string
  defaultOrder?: 'asc' | 'desc'
}

export function useTableSorting(options: UseTableSortingOptions = {}) {
  const { defaultSort, defaultOrder = 'asc' } = options
  const search = useSearch({ strict: false }) as Record<string, unknown>
  const navigate = useNavigate()

  const sortField = (search['sort'] as string | undefined) ?? defaultSort
  const sortOrder = (search['order'] as 'asc' | 'desc' | undefined) ?? defaultOrder

  const sorting: SortingState = useMemo(() => {
    if (!sortField) return []
    return [{ id: sortField, desc: sortOrder === 'desc' }]
  }, [sortField, sortOrder])

  const onSortingChange = useCallback(
    (updaterOrValue: SortingState | ((prev: SortingState) => SortingState)) => {
      const newSorting =
        typeof updaterOrValue === 'function' ? updaterOrValue(sorting) : updaterOrValue

      if (newSorting.length === 0) {
        navigateWithSearch(navigate, (prev) => {
          const next = { ...prev }
          delete next['sort']
          delete next['order']
          return next
        })
      } else {
        const sort = newSorting[0]!
        navigateWithSearch(navigate, (prev) => ({
          ...prev,
          sort: sort.id,
          order: sort.desc ? 'desc' : 'asc',
        }))
      }
    },
    [sorting, navigate],
  )

  return { sorting, onSortingChange }
}
