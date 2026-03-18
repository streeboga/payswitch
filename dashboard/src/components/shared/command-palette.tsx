import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from '@tanstack/react-router'
import { Clock, FlaskConical, Loader2, Plus, type LucideIcon } from 'lucide-react'
import {
  CommandDialog,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
} from '@/components/ui/command'
import { getNavGroups, type NavItem } from '@/components/sidebar/nav-config'
import { registerShortcut, useHotkey } from '@/hooks/use-hotkey'
import { dashboardSearch } from '@/api/endpoints/dashboard-search'
import type { SearchResultGroup } from '@/api/endpoints/dashboard-search'

// ─── Module-level Constants ──────────────────────────────────

const IS_MAC =
  typeof navigator !== 'undefined' && navigator.platform.toLowerCase().includes('mac')

const HOTKEY_OPTIONS: import('@/hooks/use-hotkey').HotkeyOptions = {
  modifiers: [IS_MAC ? 'meta' : 'ctrl'],
}

// ─── Quick Actions ──────────────────────────────────────────

interface QuickAction {
  label: string
  path: string
  icon: LucideIcon
}

// ─── Component ──────────────────────────────────────────────

export function CommandPalette() {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [searchResults, setSearchResults] = useState<SearchResultGroup[]>([])
  const [isSearching, setIsSearching] = useState(false)
  const [recentSearches, setRecentSearches] = useState<string[]>([])
  const navigate = useNavigate()
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const quickActions: QuickAction[] = useMemo(
    () => [
      { label: t('commandPalette.createPayment'), path: '/test-payment', icon: FlaskConical },
      { label: t('commandPalette.connectConnector'), path: '/connectors', icon: Plus },
    ],
    [t],
  )

  const allNavItems = useMemo(() => {
    const navGroups = getNavGroups(t)
    return navGroups.flatMap((group: { items: NavItem[] }) => group.items)
  }, [t])

  useHotkey('k', () => setOpen((prev) => !prev), HOTKEY_OPTIONS)

  // Register in central registry
  useEffect(() => {
    return registerShortcut({
      keys: '⌘K',
      description: 'Open command palette',
      group: 'General',
    })
  }, [])

  const handleOpenChange = useCallback((newOpen: boolean) => {
    setOpen(newOpen)
    if (!newOpen) {
      setQuery('')
      setSearchResults([])
      setIsSearching(false)
    }
  }, [])

  // Debounced search
  useEffect(() => {
    if (debounceRef.current) {
      clearTimeout(debounceRef.current)
    }

    if (!query.trim()) {
      setSearchResults([])
      setIsSearching(false)
      return
    }

    debounceRef.current = setTimeout(async () => {
      setIsSearching(true)
      try {
        const response = await dashboardSearch.search(query.trim())
        setSearchResults(response.groups)
      } catch {
        setSearchResults([])
      } finally {
        setIsSearching(false)
      }
    }, 300)

    return () => {
      if (debounceRef.current) {
        clearTimeout(debounceRef.current)
      }
    }
  }, [query])

  const addRecentSearch = useCallback((term: string) => {
    setRecentSearches((prev) => {
      const filtered = prev.filter((s) => s !== term)
      return [term, ...filtered].slice(0, 5)
    })
  }, [])

  const handleSelect = useCallback(
    (path: string) => {
      if (query.trim()) {
        addRecentSearch(query.trim())
      }
      setOpen(false)
      void navigate({ to: path })
    },
    [navigate, query, addRecentSearch],
  )

  const handleRecentSelect = useCallback((term: string) => {
    setQuery(term)
  }, [])

  const hasQuery = query.trim().length > 0

  return (
    <CommandDialog
      open={open}
      onOpenChange={handleOpenChange}
      title="Command Palette"
      description="Search for a command to run..."
    >
      <CommandInput
        placeholder={t('commandPalette.placeholder')}
        value={query}
        onValueChange={setQuery}
      />
      <CommandList>
        {isSearching ? (
          <div className="flex items-center justify-center gap-2 py-6">
            <Loader2 className="text-muted-foreground size-4 animate-spin" />
            <span className="text-muted-foreground text-sm">{t('commandPalette.searching')}</span>
          </div>
        ) : hasQuery ? (
          <>
            {searchResults.map((group) => (
              <CommandGroup key={group.type} heading={group.label}>
                {group.items.map((item) => (
                  <CommandItem
                    key={item.id}
                    value={`${group.type}-${item.label}`}
                    onSelect={() => handleSelect(item.path)}
                  >
                    <div className="flex flex-col gap-0.5">
                      <span>{item.label}</span>
                      {item.description && (
                        <span className="text-muted-foreground text-xs">
                          {item.description}
                        </span>
                      )}
                    </div>
                  </CommandItem>
                ))}
              </CommandGroup>
            ))}
            <CommandEmpty>
              <div className="flex flex-col items-center gap-2 py-4">
                <p className="text-muted-foreground text-sm">{t('commandPalette.noResults')}</p>
                <p className="text-muted-foreground text-xs">
                  {t('commandPalette.noResultsHint')}
                </p>
              </div>
            </CommandEmpty>
          </>
        ) : (
          <>
            {recentSearches.length > 0 && (
              <>
                <CommandGroup heading={t('commandPalette.recent')}>
                  {recentSearches.map((term) => (
                    <CommandItem
                      key={term}
                      value={`recent-${term}`}
                      onSelect={() => handleRecentSelect(term)}
                    >
                      <Clock className="text-muted-foreground size-4" />
                      <span>{term}</span>
                    </CommandItem>
                  ))}
                </CommandGroup>
                <CommandSeparator />
              </>
            )}
            <CommandGroup heading={t('commandPalette.quickActions')}>
              {quickActions.map((action) => (
                <CommandItem
                  key={action.path}
                  value={`action-${action.label}`}
                  onSelect={() => handleSelect(action.path)}
                >
                  <action.icon className="text-muted-foreground size-4" />
                  <span>{action.label}</span>
                </CommandItem>
              ))}
            </CommandGroup>
            <CommandSeparator />
            <CommandGroup heading={t('commandPalette.navigation')}>
              {allNavItems.map((item) => (
                <CommandItem
                  key={item.path}
                  value={`nav-${item.label}`}
                  onSelect={() => handleSelect(item.path)}
                >
                  <item.icon className="text-muted-foreground size-4" />
                  <span>{item.label}</span>
                </CommandItem>
              ))}
            </CommandGroup>
            <CommandEmpty>
              <div className="flex flex-col items-center gap-2 py-4">
                <p className="text-muted-foreground text-sm">{t('commandPalette.noResults')}</p>
              </div>
            </CommandEmpty>
          </>
        )}
      </CommandList>
    </CommandDialog>
  )
}
