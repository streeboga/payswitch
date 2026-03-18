import { describe, it, expect } from 'vitest'
import { hasPermission, getPermissions, type Permission, type Role } from '../use-rbac'

describe('RBAC hasPermission', () => {
  it('admin has all permissions', () => {
    const allPermissions: Permission[] = [
      'manage_users',
      'manage_settings',
      'manage_connectors',
      'view_audit',
      'create_payments',
      'view_payments',
    ]

    for (const permission of allPermissions) {
      expect(hasPermission('admin', permission)).toBe(true)
    }
  })

  it('operator has create_payments, view_payments, manage_connectors', () => {
    expect(hasPermission('operator', 'create_payments')).toBe(true)
    expect(hasPermission('operator', 'view_payments')).toBe(true)
    expect(hasPermission('operator', 'manage_connectors')).toBe(true)
  })

  it('operator does not have admin-only permissions', () => {
    expect(hasPermission('operator', 'manage_users')).toBe(false)
    expect(hasPermission('operator', 'manage_settings')).toBe(false)
    expect(hasPermission('operator', 'view_audit')).toBe(false)
  })

  it('viewer only has view_payments', () => {
    expect(hasPermission('viewer', 'view_payments')).toBe(true)
    expect(hasPermission('viewer', 'create_payments')).toBe(false)
    expect(hasPermission('viewer', 'manage_users')).toBe(false)
    expect(hasPermission('viewer', 'manage_settings')).toBe(false)
    expect(hasPermission('viewer', 'manage_connectors')).toBe(false)
    expect(hasPermission('viewer', 'view_audit')).toBe(false)
  })

  it('undefined role has no permissions', () => {
    expect(hasPermission(undefined, 'view_payments')).toBe(false)
  })
})

describe('RBAC getPermissions', () => {
  it('returns all permissions for admin', () => {
    const perms = getPermissions('admin')
    expect(perms).toHaveLength(6)
  })

  it('returns 3 permissions for operator', () => {
    const perms = getPermissions('operator')
    expect(perms).toHaveLength(3)
    expect(perms).toContain('create_payments')
    expect(perms).toContain('view_payments')
    expect(perms).toContain('manage_connectors')
  })

  it('returns 1 permission for viewer', () => {
    const perms = getPermissions('viewer')
    expect(perms).toHaveLength(1)
    expect(perms).toContain('view_payments')
  })

  it('returns empty array for undefined role', () => {
    expect(getPermissions(undefined as unknown as Role)).toEqual([])
  })
})
