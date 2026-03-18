import { useMemo } from 'react'
import { useMatches } from '@tanstack/react-router'
import { useTranslation } from 'react-i18next'
import { getNavGroups } from '@/components/sidebar/nav-config'
import type { BreadcrumbItem } from '@/components/shared/breadcrumbs'

export function useBreadcrumbs(): BreadcrumbItem[] {
  const { t } = useTranslation()
  const matches = useMatches()

  return useMemo(() => {
    const navGroups = getNavGroups(t)
    const pathLabelMap = new Map<string, string>()
    for (const group of navGroups) {
      for (const item of group.items) {
        pathLabelMap.set(item.path, item.label)
      }
    }

    const items: BreadcrumbItem[] = []
    const addedPaths = new Set<string>()

    for (const match of matches) {
      const pathname = match.pathname

      // Skip root match
      if (pathname === '/') continue

      // Check if this is a known nav path
      const label = pathLabelMap.get(pathname)
      if (label) {
        items.push({ label, href: pathname })
        addedPaths.add(pathname)
        continue
      }

      // For detail pages like /payments/pay_123, find parent
      const segments = pathname.split('/').filter(Boolean)
      if (segments.length >= 2) {
        const parentPath = `/${segments[0]}`
        const parentLabel = pathLabelMap.get(parentPath)
        if (parentLabel && !addedPaths.has(parentPath)) {
          items.push({ label: parentLabel, href: parentPath })
          addedPaths.add(parentPath)
        }
        // Add the detail segment as current item
        const detailKey = segments[segments.length - 1] ?? ''
        items.push({ label: detailKey })
      }
    }

    // Mark last item as current (no href)
    const last = items.at(-1)
    if (last) {
      last.href = undefined
    }

    return items
  }, [matches, t])
}
