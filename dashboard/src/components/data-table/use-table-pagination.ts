import { useCallback } from 'react'
import { useNavigate, useSearch } from '@tanstack/react-router'
import type { PaginationState } from './table-pagination'
import type { JsonApiPaginationMeta } from '@/api/types'
import { navigateWithSearch } from './navigate-search'

interface UseTablePaginationOptions {
  defaultPerPage?: number
}

export function useTablePagination(
  meta: JsonApiPaginationMeta | undefined,
  options: UseTablePaginationOptions = {},
) {
  const { defaultPerPage = 20 } = options
  const search = useSearch({ strict: false }) as Record<string, unknown>
  const navigate = useNavigate()

  const currentPage = Number(search['page'] ?? 1)
  const perPage = Number(search['per_page'] ?? defaultPerPage)

  const pagination: PaginationState = {
    currentPage: meta?.current_page ?? currentPage,
    lastPage: meta?.last_page ?? 1,
    perPage: meta?.per_page ?? perPage,
    total: meta?.total ?? 0,
  }

  const onPageChange = useCallback(
    (page: number) => {
      navigateWithSearch(navigate, (prev) => ({
        ...prev,
        page: page === 1 ? undefined : page,
      }))
    },
    [navigate],
  )

  const onPerPageChange = useCallback(
    (newPerPage: number) => {
      navigateWithSearch(navigate, (prev) => ({
        ...prev,
        per_page: newPerPage === defaultPerPage ? undefined : newPerPage,
        page: undefined, // Reset to page 1 when changing per_page
      }))
    },
    [navigate, defaultPerPage],
  )

  return {
    pagination,
    currentPage,
    perPage,
    onPageChange,
    onPerPageChange,
  }
}
