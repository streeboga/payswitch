import ky, { type KyInstance, type Options as KyOptions } from 'ky'
import type {
  JsonApiDocument,
  JsonApiCollectionDocument,
  JsonApiErrorResponse,
  JsonApiErrorItem,
} from './types/json-api'
import { useContextStore } from '@/stores/context'

export const API_BASE_URL = import.meta.env.VITE_API_URL ?? '/api/v1'
const SANCTUM_CSRF_URL = '/sanctum/csrf-cookie'

function getCsrfToken(): string | undefined {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)
  return match ? decodeURIComponent(match[1]!) : undefined
}

async function ensureCsrfToken(): Promise<void> {
  if (!getCsrfToken()) {
    await ky.get(SANCTUM_CSRF_URL, { credentials: 'include' })
  }
}

function buildApiError(body: JsonApiErrorResponse): ApiError {
  const first = body.errors[0]
  return new ApiError({
    status: first?.status ?? 500,
    code: first?.code ?? 'unknown_error',
    title: first?.title ?? 'Unknown error',
    detail: first?.detail,
    source: first?.source,
    errors: body.errors,
  })
}

export class ApiError extends Error {
  status: number
  code: string
  title: string
  detail?: string
  source?: { pointer?: string; parameter?: string }
  errors: JsonApiErrorItem[]

  constructor(data: {
    status: number
    code: string
    title: string
    detail?: string
    source?: { pointer?: string; parameter?: string }
    errors: JsonApiErrorItem[]
  }) {
    super(data.detail ?? data.title)
    this.name = 'ApiError'
    this.status = data.status
    this.code = data.code
    this.title = data.title
    this.detail = data.detail
    this.source = data.source
    this.errors = data.errors
  }
}

export const api: KyInstance = ky.create({
  prefixUrl: API_BASE_URL,
  credentials: 'include',
  headers: {
    Accept: 'application/vnd.api+json',
    'Content-Type': 'application/vnd.api+json',
  },
  hooks: {
    beforeRequest: [
      async (request) => {
        const method = request.method.toUpperCase()
        if (method !== 'GET' && method !== 'HEAD') {
          await ensureCsrfToken()
          const token = getCsrfToken()
          if (token) {
            request.headers.set('X-XSRF-TOKEN', token)
          }
        }

        // Inject merchant context header for dashboard requests
        const { currentMerchantKey, currentProfileKey } = useContextStore.getState()
        if (currentMerchantKey) {
          request.headers.set('X-Merchant-Key', currentMerchantKey)
        }
        if (currentProfileKey) {
          request.headers.set('X-Profile-Key', currentProfileKey)
        }
      },
    ],
    afterResponse: [
      async (request, _options, response) => {
        if (response.status === 401) {
          window.location.href = '/login'
          throw new ApiError({
            status: 401,
            code: 'unauthenticated',
            title: 'Unauthenticated',
            detail: 'Your session has expired. Please log in again.',
            errors: [{ status: 401, code: 'unauthenticated', title: 'Unauthenticated' }],
          })
        }

        // If merchant context returns 404, the stored merchant key is stale
        // (e.g. after DB reseed). Reset context so the user can re-select.
        if (response.status === 404 && request.headers.get('X-Merchant-Key')) {
          const { currentMerchantKey, setMerchant } = useContextStore.getState()
          if (currentMerchantKey) {
            setMerchant(null)
          }
        }
      },
    ],
    beforeError: [
      async (error) => {
        const { response } = error
        if (response) {
          try {
            const body = (await response.json()) as JsonApiErrorResponse
            if (body.errors) {
              throw buildApiError(body)
            }
          } catch (e) {
            if (e instanceof ApiError) throw e
          }
        }
        return error
      },
    ],
  },
})

/** GET a single JSON:API resource */
export async function getResource<T>(
  url: string,
  options?: KyOptions,
): Promise<JsonApiDocument<T>> {
  return api.get(url, options).json<JsonApiDocument<T>>()
}

/** GET a JSON:API collection */
export async function getCollection<T>(
  url: string,
  options?: KyOptions,
): Promise<JsonApiCollectionDocument<T>> {
  return api.get(url, options).json<JsonApiCollectionDocument<T>>()
}

/** POST a resource (create) — flat JSON body */
export async function createResource<T>(
  url: string,
  attrs: object,
  options?: KyOptions,
): Promise<JsonApiDocument<T>> {
  return api.post(url, { json: attrs, ...options }).json<JsonApiDocument<T>>()
}

/** PATCH a resource (update) — flat JSON body */
export async function updateResource<T>(
  url: string,
  attrs: object,
  options?: KyOptions,
): Promise<JsonApiDocument<T>> {
  return api.patch(url, { json: attrs, ...options }).json<JsonApiDocument<T>>()
}

/** DELETE a JSON:API resource */
export async function deleteResource(url: string, options?: KyOptions): Promise<void> {
  await api.delete(url, options)
}
