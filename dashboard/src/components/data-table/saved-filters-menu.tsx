import { useState, useCallback, useMemo } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useTranslation } from 'react-i18next'
import { Bookmark, Plus, Pencil, Trash2 } from 'lucide-react'

import {
  useSavedFiltersStore,
  getFilterPresets,
  type SavedFilter,
} from '@/stores/saved-filters'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

// ─── Types ──────────────────────────────────────────────────

interface SavedFiltersMenuProps {
  /** Current page identifier (e.g., 'payments', 'webhooks', 'disputes') */
  page: string
  /** Current filter values to save */
  currentParams: Record<string, string | undefined>
}

// ─── Component ──────────────────────────────────────────────

export function SavedFiltersMenu({ page, currentParams }: SavedFiltersMenuProps) {
  const { t } = useTranslation()
  const filters = useSavedFiltersStore((s) => s.filters)
  const save = useSavedFiltersStore((s) => s.save)
  const remove = useSavedFiltersStore((s) => s.remove)
  const rename = useSavedFiltersStore((s) => s.rename)
  const navigate = useNavigate()

  const [saveDialogOpen, setSaveDialogOpen] = useState(false)
  const [renameDialogOpen, setRenameDialogOpen] = useState(false)
  const [renamingFilter, setRenamingFilter] = useState<SavedFilter | null>(null)
  const [filterName, setFilterName] = useState('')

  // Filters for current page (user-saved + presets)
  const pageFilters = useMemo(() => filters.filter((f) => f.page === page), [filters, page])
  const pagePresets = useMemo(
    () => getFilterPresets(t).filter((f) => f.page === page),
    [t, page],
  )

  const applyFilter = useCallback(
    (params: Record<string, string>) => {
      const searchParams = new URLSearchParams()
      for (const [key, value] of Object.entries(params)) {
        if (value) searchParams.set(key, value)
      }
      void navigate({
        to: '.',
        search: Object.fromEntries(searchParams.entries()),
      })
    },
    [navigate],
  )

  const handleSave = useCallback(() => {
    if (!filterName.trim()) return
    const params: Record<string, string> = {}
    for (const [key, value] of Object.entries(currentParams)) {
      if (value !== undefined && value !== '') {
        params[key] = value
      }
    }
    save({ name: filterName.trim(), page, params })
    setFilterName('')
    setSaveDialogOpen(false)
  }, [filterName, currentParams, page, save])

  const handleRename = useCallback(() => {
    if (!renamingFilter || !filterName.trim()) return
    rename(renamingFilter.id, filterName.trim())
    setFilterName('')
    setRenamingFilter(null)
    setRenameDialogOpen(false)
  }, [renamingFilter, filterName, rename])

  const openRenameDialog = useCallback((filter: SavedFilter) => {
    setRenamingFilter(filter)
    setFilterName(filter.name)
    setRenameDialogOpen(true)
  }, [])

  const hasActiveFilters = Object.values(currentParams).some(
    (v) => v !== undefined && v !== '',
  )

  return (
    <>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="outline" size="sm">
            <Bookmark className="mr-1.5 h-3.5 w-3.5" />
            {t('table.savedFilters')}
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" className="w-64">
          {/* Presets */}
          {pagePresets.length > 0 && (
            <>
              <DropdownMenuLabel>{t('table.presets')}</DropdownMenuLabel>
              {pagePresets.map((preset) => (
                <DropdownMenuItem
                  key={preset.id}
                  onClick={() => applyFilter(preset.params)}
                >
                  {preset.name}
                </DropdownMenuItem>
              ))}
              <DropdownMenuSeparator />
            </>
          )}

          {/* User saved filters */}
          {pageFilters.length > 0 && (
            <>
              <DropdownMenuLabel>{t('table.userFilters')}</DropdownMenuLabel>
              {pageFilters.map((filter) => (
                <DropdownMenuItem
                  key={filter.id}
                  className="group flex items-center justify-between"
                  onClick={() => applyFilter(filter.params)}
                >
                  <span className="truncate">{filter.name}</span>
                  <span className="flex shrink-0 gap-1 opacity-0 group-hover:opacity-100">
                    <button
                      type="button"
                      className="hover:text-foreground rounded p-0.5"
                      onClick={(e) => {
                        e.stopPropagation()
                        openRenameDialog(filter)
                      }}
                    >
                      <Pencil className="h-3 w-3" />
                    </button>
                    <button
                      type="button"
                      className="rounded p-0.5 hover:text-red-600"
                      onClick={(e) => {
                        e.stopPropagation()
                        remove(filter.id)
                      }}
                    >
                      <Trash2 className="h-3 w-3" />
                    </button>
                  </span>
                </DropdownMenuItem>
              ))}
              <DropdownMenuSeparator />
            </>
          )}

          {/* Save current */}
          <DropdownMenuItem
            disabled={!hasActiveFilters}
            onClick={() => setSaveDialogOpen(true)}
          >
            <Plus className="mr-2 h-3.5 w-3.5" />
            {t('table.saveCurrent')}
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>

      {/* Save Dialog */}
      <Dialog open={saveDialogOpen} onOpenChange={setSaveDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('table.saveFilterTitle')}</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label htmlFor="filter-name">{t('table.filterNameLabel')}</Label>
              <Input
                id="filter-name"
                placeholder={t('table.filterNamePlaceholder')}
                value={filterName}
                onChange={(e) => setFilterName(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') handleSave()
                }}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setSaveDialogOpen(false)}>
              {t('common.cancel')}
            </Button>
            <Button onClick={handleSave} disabled={!filterName.trim()}>
              {t('common.save')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Rename Dialog */}
      <Dialog open={renameDialogOpen} onOpenChange={setRenameDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('table.renameFilterTitle')}</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label htmlFor="rename-filter">{t('table.newNameLabel')}</Label>
              <Input
                id="rename-filter"
                value={filterName}
                onChange={(e) => setFilterName(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') handleRename()
                }}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setRenameDialogOpen(false)}>
              {t('common.cancel')}
            </Button>
            <Button onClick={handleRename} disabled={!filterName.trim()}>
              {t('table.rename')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}
