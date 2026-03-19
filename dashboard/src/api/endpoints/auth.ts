import ky from 'ky'
import type { User } from '@/stores/auth'

/** Auth endpoints use Sanctum (not the JSON:API prefix) */
const sanctumClient = ky.create({
  prefixUrl: import.meta.env.VITE_BACKEND_URL ?? '',
  credentials: 'include',
  headers: { Accept: 'application/json' },
})

function getCsrfToken(): string | undefined {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)
  return match ? decodeURIComponent(match[1]!) : undefined
}

/**
 * Ensures the XSRF-TOKEN cookie is present (fetching /sanctum/csrf-cookie if
 * needed) and returns the token string.  When the cookie already exists this
 * resolves synchronously with no network round-trip.
 */
async function requireCsrfToken(): Promise<string> {
  if (!getCsrfToken()) {
    await sanctumClient.get('sanctum/csrf-cookie')
  }
  return getCsrfToken() ?? ''
}

export interface LoginResponse {
  two_factor?: true
  id?: number
  name?: string
  email?: string
}

export const auth = {
  async login(credentials: { email: string; password: string }): Promise<LoginResponse> {
    // Login is always the first request — CSRF cookie is likely absent, so we
    // must fetch it first. Sequential is correct here.
    const token = await requireCsrfToken()
    return sanctumClient
      .post('login', {
        json: credentials,
        headers: { 'X-XSRF-TOKEN': token },
      })
      .json<LoginResponse>()
  },

  async twoFactorChallenge(data: {
    code?: string
    recovery_code?: string
  }): Promise<User> {
    // After login the CSRF cookie is already set; requireCsrfToken() resolves
    // synchronously (no extra round-trip) in the common case.
    const token = await requireCsrfToken()
    return sanctumClient
      .post('two-factor-challenge', {
        json: data,
        headers: { 'X-XSRF-TOKEN': token },
      })
      .json<User>()
  },

  async logout() {
    // Cookie is always present for an authenticated session — resolves instantly.
    const token = await requireCsrfToken()
    await sanctumClient.post('logout', {
      headers: { 'X-XSRF-TOKEN': token },
    })
  },

  async user() {
    return sanctumClient.get('api/v1/user').json<User>()
  },

  async csrfCookie() {
    await sanctumClient.get('sanctum/csrf-cookie')
  },
} as const
