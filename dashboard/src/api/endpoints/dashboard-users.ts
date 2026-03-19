import { getCollection, createResource, updateResource, deleteResource } from '../client'
import type { PaginatedResult } from '../types'
import { parseCollection, extractAttributes } from '../types'

// ─── Types ──────────────────────────────────────────────────

export type UserRole = 'admin' | 'operator' | 'viewer'
export type UserStatus = 'active' | 'invited' | 'disabled'

export interface DashboardUserAttributes {
  name: string
  email: string
  role: UserRole
  two_factor_enabled: boolean
  last_login_at: string | null
  status: UserStatus
  created_at: string
}

export interface UserInviteData {
  email: string
  role: UserRole
}

export interface UserUpdateData {
  role?: UserRole
  status?: UserStatus
}

// ─── API Functions ──────────────────────────────────────────

export const dashboardUsers = {
  async list(): Promise<PaginatedResult<DashboardUserAttributes>> {
    const doc = await getCollection<DashboardUserAttributes>('dashboard/users')
    return parseCollection(doc)
  },

  async invite(data: UserInviteData) {
    const doc = await createResource<DashboardUserAttributes>(
      'dashboard/users/roles',
      data,
    )
    return extractAttributes(doc.data)
  },

  async update(id: string, data: UserUpdateData) {
    const doc = await updateResource<DashboardUserAttributes>(
      `dashboard/users/roles/${id}`,
      data,
    )
    return extractAttributes(doc.data)
  },

  remove(id: string) {
    return deleteResource(`dashboard/users/roles/${id}`)
  },
} as const
