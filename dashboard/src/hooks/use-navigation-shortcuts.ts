import { useEffect, useCallback } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useAuthStore } from '@/stores/auth'
import { useHotkey, registerShortcut } from '@/hooks/use-hotkey'

const NAV_SHORTCUTS = [
  { keys: 'g o', path: '/overview', description: 'Go to Overview' },
  { keys: 'g p', path: '/payments', description: 'Go to Payments' },
  { keys: 'g r', path: '/refunds', description: 'Go to Refunds' },
  { keys: 'g c', path: '/customers', description: 'Go to Customers' },
  { keys: 'g s', path: '/settings', description: 'Go to Settings' },
] as const

export function useNavigationShortcuts(): void {
  const navigate = useNavigate()
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)
  const disabled = !isAuthenticated

  // Helper that bypasses strict route typing for future routes
  const go = useCallback(
    (path: string) => {
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      void navigate({ to: path as any })
    },
    [navigate],
  )

  useHotkey('g o', () => go('/overview'), { disabled })
  useHotkey('g p', () => go('/payments'), { disabled })
  useHotkey('g r', () => go('/refunds'), { disabled })
  useHotkey('g c', () => go('/customers'), { disabled })
  useHotkey('g s', () => go('/settings'), { disabled })

  // Register shortcuts in central registry
  useEffect(() => {
    const cleanups = NAV_SHORTCUTS.map((s) =>
      registerShortcut({
        keys: s.keys,
        description: s.description,
        group: 'Navigation',
      }),
    )
    return () => {
      cleanups.forEach((fn) => fn())
    }
  }, [])
}
