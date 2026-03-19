import { useEffect, useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  getShortcutRegistry,
  registerShortcut,
  useHotkey,
  type ShortcutEntry,
} from '@/hooks/use-hotkey'

// ─── Module-level Constants ──────────────────────────────────

const IS_MAC =
  typeof navigator !== 'undefined' && navigator.platform.toLowerCase().includes('mac')

const HOTKEY_OPTIONS: import('@/hooks/use-hotkey').HotkeyOptions = {
  modifiers: [IS_MAC ? 'meta' : 'ctrl'],
}

// ─── Kbd Component ───────────────────────────────────────────

function Kbd({ children }: { children: string }) {
  return (
    <kbd className="bg-muted text-muted-foreground pointer-events-none inline-flex h-5 items-center gap-1 rounded border px-1.5 font-mono text-[10px] font-medium select-none">
      {children}
    </kbd>
  )
}

// ─── Shortcut Row ────────────────────────────────────────────

function ShortcutRow({ entry, thenLabel }: { entry: ShortcutEntry; thenLabel: string }) {
  const keys = entry.keys.split(/(?<=\S)\s+(?=\S)/)

  return (
    <div className="flex items-center justify-between py-1.5">
      <span className="text-sm">{entry.description}</span>
      <div className="flex items-center gap-1">
        {keys.map((k, i) => (
          <span key={i} className="flex items-center gap-1">
            {i > 0 && <span className="text-muted-foreground text-xs">{thenLabel}</span>}
            <Kbd>{k}</Kbd>
          </span>
        ))}
      </div>
    </div>
  )
}

// ─── Shortcuts Help Dialog ───────────────────────────────────

export function ShortcutsHelp() {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)

  useHotkey('/', () => setOpen((prev) => !prev), HOTKEY_OPTIONS)

  // Register self in registry
  useEffect(() => {
    return registerShortcut({
      keys: '⌘/',
      description: 'Show keyboard shortcuts',
      group: 'General',
    })
  }, [])

  const grouped = useMemo(() => {
    const entries = getShortcutRegistry()
    const groups: Record<string, ShortcutEntry[]> = {}
    for (const entry of entries) {
      const list = groups[entry.group]
      if (list) {
        list.push(entry)
      } else {
        groups[entry.group] = [entry]
      }
    }
    return groups
    // Re-compute when dialog opens
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  const groupOrder = ['Navigation', 'Tables', 'General']
  const sortedGroups = Object.keys(grouped).sort((a, b) => {
    const ai = groupOrder.indexOf(a)
    const bi = groupOrder.indexOf(b)
    return (ai === -1 ? 999 : ai) - (bi === -1 ? 999 : bi)
  })

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{t('shortcuts.title')}</DialogTitle>
          <DialogDescription>{t('shortcuts.description')}</DialogDescription>
        </DialogHeader>
        <div className="max-h-[60vh] space-y-4 overflow-y-auto">
          {sortedGroups.map((group) => (
            <div key={group}>
              <h4 className="text-muted-foreground mb-2 text-xs font-semibold tracking-wider uppercase">
                {group}
              </h4>
              <div className="divide-border divide-y">
                {grouped[group]?.map((entry) => (
                  <ShortcutRow
                    key={entry.keys}
                    entry={entry}
                    thenLabel={t('shortcuts.then')}
                  />
                ))}
              </div>
            </div>
          ))}
        </div>
      </DialogContent>
    </Dialog>
  )
}
