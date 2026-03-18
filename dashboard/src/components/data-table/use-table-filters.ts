import { useCallback, useMemo } from 'react'
import { useNavigate, useSearch } from '@tanstack/react-router'
import type { FilterDef, FilterValues } from './table-filters'
import { navigateWithSearch } from './navigate-search'

export function useTableFilters(filters: FilterDef[]) {
  const search = useSearch({ strict: false }) as Record<string, unknown>
  const navigate = useNavigate()

  const filterKeys = useMemo(() => filters.map((f) => f.key), [filters])

  const values: FilterValues = useMemo(() => {
    const result: FilterValues = {}
    for (const key of filterKeys) {
      const raw = search[key]
      result[key] = typeof raw === 'string' ? raw : undefined
    }
    return result
  }, [filterKeys, search])

  const onChange = useCallback(
    (key: string, value: string | undefined) => {
      navigateWithSearch(navigate, (prev) => {
        const next: Record<string, unknown> = { ...prev, page: undefined }
        if (value === undefined || value === '') {
          delete next[key]
        } else {
          next[key] = value
        }
        return next
      })
    },
    [navigate],
  )

  const onReset = useCallback(() => {
    navigateWithSearch(navigate, (prev) => {
      const next: Record<string, unknown> = { ...prev, page: undefined }
      for (const key of filterKeys) {
        delete next[key]
      }
      return next
    })
  }, [navigate, filterKeys])

  return { values, onChange, onReset }
}
