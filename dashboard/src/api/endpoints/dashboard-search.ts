import { api } from '../client'

// ─── Types ──────────────────────────────────────────────────

export interface SearchResult {
  id: string
  label: string
  description?: string
  path: string
  icon?: string
}

export interface SearchResultGroup {
  type: string
  label: string
  items: SearchResult[]
}

export interface SearchResponse {
  groups: SearchResultGroup[]
}

// ─── API Functions ──────────────────────────────────────────

const BASE = 'dashboard/search'

export const dashboardSearch = {
  search(query: string, types?: string[]) {
    const searchParams: Record<string, string> = { q: query }
    if (types?.length) {
      searchParams.types = types.join(',')
    }
    return api.get(BASE, { searchParams }).json<SearchResponse>()
  },
} as const
