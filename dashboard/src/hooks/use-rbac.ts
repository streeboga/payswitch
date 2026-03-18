import { useMemo } from 'react'
import { useAuthStore } from '@/stores/auth'

// ─── Permission Types ───────────────────────────────────────

export type Permission =
  | 'manage_users'
  | 'manage_settings'
  | 'manage_connectors'
  | 'view_audit'
  | 'create_payments'
  | 'view_payments'

export type Role = 'admin' | 'operator' | 'viewer'

// ─── Role → Permission Mapping ──────────────────────────────

const ROLE_PERMISSIONS: Record<Role, Set<Permission>> = {
  admin: new Set([
    'manage_users',
    'manage_settings',
    'manage_connectors',
    'view_audit',
    'create_payments',
    'view_payments',
  ]),
  operator: new Set(['create_payments', 'view_payments', 'manage_connectors']),
  viewer: new Set(['view_payments']),
}

// ─── Pure helpers (testable without hooks) ──────────────────

export function hasPermission(role: Role | undefined, permission: Permission): boolean {
  if (!role) return false
  const permissions = ROLE_PERMISSIONS[role]
  return permissions.has(permission)
}

export function getPermissions(role: Role | undefined): Permission[] {
  if (!role) return []
  return Array.from(ROLE_PERMISSIONS[role])
}

// ─── Hooks ──────────────────────────────────────────────────

export function useCanAccess(permission: Permission): boolean {
  const role = useAuthStore((s) => s.user?.role)
  return useMemo(() => hasPermission(role, permission), [role, permission])
}

export function useIsAdmin(): boolean {
  return useAuthStore((s) => s.user?.role === 'admin')
}
