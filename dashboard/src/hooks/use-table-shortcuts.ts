import { useEffect, useCallback, useRef } from 'react'
import type { Table as TanstackTable } from '@tanstack/react-table'
import { registerShortcut } from '@/hooks/use-hotkey'

interface UseTableShortcutsOptions<TData> {
  table: TanstackTable<TData>
  containerRef: React.RefObject<HTMLElement | null>
  onRowOpen?: (row: TData) => void
}

export function useTableShortcuts<TData>({
  table,
  containerRef,
  onRowOpen,
}: UseTableShortcutsOptions<TData>): {
  focusedRowIndexRef: React.RefObject<number>
  setFocusedRowIndex: (index: number) => void
} {
  const focusedRowIndexRef = useRef(0)

  const setFocusedRowIndex = useCallback((index: number) => {
    focusedRowIndexRef.current = index
  }, [])

  const isContainerFocused = useCallback((): boolean => {
    if (!containerRef.current) return false
    return containerRef.current.contains(document.activeElement)
  }, [containerRef])

  const highlightRow = useCallback(
    (index: number) => {
      if (!containerRef.current) return
      const rows = containerRef.current.querySelectorAll('tbody tr')
      rows.forEach((row) => row.removeAttribute('data-highlighted'))
      const targetRow = rows[index]
      if (targetRow) {
        targetRow.setAttribute('data-highlighted', 'true')
        targetRow.scrollIntoView({ block: 'nearest' })
      }
    },
    [containerRef],
  )

  const handler = useCallback(
    (event: KeyboardEvent) => {
      if (!isContainerFocused()) return

      const rows = table.getRowModel().rows
      if (rows.length === 0) return

      const key = event.key

      if (key === 'ArrowDown') {
        event.preventDefault()
        const next = Math.min(focusedRowIndexRef.current + 1, rows.length - 1)
        focusedRowIndexRef.current = next
        highlightRow(next)
        return
      }

      if (key === 'ArrowUp') {
        event.preventDefault()
        const prev = Math.max(focusedRowIndexRef.current - 1, 0)
        focusedRowIndexRef.current = prev
        highlightRow(prev)
        return
      }

      if (key === 'Enter') {
        event.preventDefault()
        const row = rows[focusedRowIndexRef.current]
        if (row && onRowOpen) {
          onRowOpen(row.original)
        }
        return
      }

      if (key === ' ') {
        event.preventDefault()
        const row = rows[focusedRowIndexRef.current]
        if (row) {
          row.toggleSelected(!row.getIsSelected())
        }
        return
      }

      if ((event.metaKey || event.ctrlKey) && key.toLowerCase() === 'a') {
        event.preventDefault()
        table.toggleAllRowsSelected(true)
        return
      }
    },
    [table, isContainerFocused, highlightRow, onRowOpen],
  )

  useEffect(() => {
    document.addEventListener('keydown', handler)
    return () => {
      document.removeEventListener('keydown', handler)
    }
  }, [handler])

  // Register shortcuts in central registry
  useEffect(() => {
    const cleanups = [
      registerShortcut({
        keys: '↑↓',
        description: 'Navigate table rows',
        group: 'Tables',
      }),
      registerShortcut({
        keys: 'Enter',
        description: 'Open selected row',
        group: 'Tables',
      }),
      registerShortcut({
        keys: 'Space',
        description: 'Toggle row selection',
        group: 'Tables',
      }),
      registerShortcut({
        keys: '⌘A',
        description: 'Select all rows',
        group: 'Tables',
      }),
    ]
    return () => {
      cleanups.forEach((fn) => fn())
    }
  }, [])

  return { focusedRowIndexRef, setFocusedRowIndex }
}
