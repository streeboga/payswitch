/** Shared query parameter types for list endpoints */

export interface ListParams {
  filter?: Record<string, string | number | boolean>
  sort?: string
  page?: { number?: number; size?: number }
  include?: string
}

export function buildSearchParams(params?: ListParams): URLSearchParams {
  const sp = new URLSearchParams()

  if (!params) return sp

  if (params.filter) {
    for (const [key, value] of Object.entries(params.filter)) {
      sp.set(`filter[${key}]`, String(value))
    }
  }

  if (params.sort) {
    sp.set('sort', params.sort)
  }

  if (params.page) {
    if (params.page.number !== undefined) {
      sp.set('page[number]', String(params.page.number))
    }
    if (params.page.size !== undefined) {
      sp.set('page[size]', String(params.page.size))
    }
  }

  if (params.include) {
    sp.set('include', params.include)
  }

  return sp
}
