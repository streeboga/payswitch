/** JSON:API v1.1 generic types */

export interface JsonApiResource<T = Record<string, unknown>> {
  type: string
  id: string
  attributes: T
  relationships?: Record<string, JsonApiRelationship>
  links?: { self?: string }
}

export interface JsonApiRelationship {
  data: JsonApiResourceIdentifier | JsonApiResourceIdentifier[] | null
  links?: { self?: string; related?: string }
}

export interface JsonApiResourceIdentifier {
  type: string
  id: string
}

export interface JsonApiDocument<T = Record<string, unknown>> {
  data: JsonApiResource<T>
  included?: JsonApiResource[]
}

export interface JsonApiCollectionDocument<T = Record<string, unknown>> {
  data: JsonApiResource<T>[]
  meta: JsonApiPaginationMeta
  links: JsonApiPaginationLinks
  included?: JsonApiResource[]
}

export interface JsonApiPaginationMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export interface JsonApiPaginationLinks {
  first: string | null
  last: string | null
  prev: string | null
  next: string | null
}

export interface JsonApiErrorItem {
  status: number
  code: string
  title: string
  detail?: string
  source?: { pointer?: string; parameter?: string }
}

export interface JsonApiErrorResponse {
  errors: JsonApiErrorItem[]
}

/** Helper to extract attributes from a JSON:API resource */
export function extractAttributes<T>(resource: JsonApiResource<T>): T & { id: string } {
  return { ...resource.attributes, id: resource.id }
}

/** Helper to extract attributes from a JSON:API collection */
export function extractCollectionAttributes<T>(
  resources: JsonApiResource<T>[],
): (T & { id: string })[] {
  return resources.map(extractAttributes)
}

/** Parsed list result with extracted items and pagination meta */
export interface PaginatedResult<T> {
  items: (T & { id: string })[]
  meta: JsonApiPaginationMeta
}

/** Parse a JSON:API collection document into a flat paginated result */
export function parseCollection<T>(
  doc: JsonApiCollectionDocument<T>,
): PaginatedResult<T> {
  return {
    items: extractCollectionAttributes(doc.data),
    meta: doc.meta,
  }
}

/** Filter param keys that should be wrapped in filter[] notation */
const SORT_KEY = 'sort'
const DIRECTION_KEY = 'direction'
const PASSTHROUGH_KEYS = new Set(['include', 'format', 'q', 'types', 'period'])

/** Build JSON:API-compatible search params from a flat params object */
export function buildJsonApiParams(params: object): URLSearchParams {
  const sp = new URLSearchParams()

  let sortField: string | undefined
  let sortDirection: string | undefined

  for (const [key, value] of Object.entries(params) as [string, unknown][]) {
    if (value === undefined || value === '') continue

    if (key === SORT_KEY) {
      sortField = String(value)
    } else if (key === DIRECTION_KEY) {
      sortDirection = String(value)
    } else if (key === 'per_page') {
      sp.set('page[size]', String(value))
    } else if (key === 'page') {
      sp.set('page[number]', String(value))
    } else if (PASSTHROUGH_KEYS.has(key)) {
      sp.set(key, String(value))
    } else {
      sp.set(`filter[${key}]`, String(value))
    }
  }

  if (sortField) {
    const prefix = sortDirection === 'desc' ? '-' : ''
    sp.set('sort', `${prefix}${sortField}`)
  }

  return sp
}
