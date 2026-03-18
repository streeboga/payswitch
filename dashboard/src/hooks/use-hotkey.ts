import { useEffect, useRef } from 'react'

// ─── Types ───────────────────────────────────────────────────

export type Modifier = 'meta' | 'ctrl' | 'shift' | 'alt'

export interface HotkeyOptions {
  modifiers?: Modifier[]
  disabled?: boolean
}

export interface ShortcutEntry {
  keys: string
  description: string
  group: string
}

// ─── Central Registry ────────────────────────────────────────

const registrySet = new Set<ShortcutEntry>()

export function getShortcutRegistry(): ShortcutEntry[] {
  return [...registrySet]
}

export function registerShortcut(entry: ShortcutEntry): () => void {
  registrySet.add(entry)
  return () => {
    registrySet.delete(entry)
  }
}

// ─── Input Guard ─────────────────────────────────────────────

function isEditableElement(target: EventTarget | null): boolean {
  if (!target || !(target instanceof HTMLElement)) return false

  const tagName = target.tagName.toLowerCase()
  if (tagName === 'input' || tagName === 'textarea' || tagName === 'select') return true
  if (target.isContentEditable) return true
  if (target.getAttribute('role') === 'textbox') return true

  return false
}

// ─── Modifier Matching ──────────────────────────────────────

function modifiersMatch(
  event: KeyboardEvent,
  modifiers: Modifier[] | undefined,
): boolean {
  const mods = modifiers ?? []
  if (event.metaKey !== mods.includes('meta')) return false
  if (event.ctrlKey !== mods.includes('ctrl')) return false
  if (event.shiftKey !== mods.includes('shift')) return false
  if (event.altKey !== mods.includes('alt')) return false
  return true
}

// ─── Sequence Buffer (global) ────────────────────────────────

let sequenceBuffer = ''
let sequenceTimer: ReturnType<typeof setTimeout> | null = null
const SEQUENCE_TIMEOUT = 1000

function resetSequence() {
  sequenceBuffer = ''
  if (sequenceTimer) {
    clearTimeout(sequenceTimer)
    sequenceTimer = null
  }
}

// ─── Hook ────────────────────────────────────────────────────

export function useHotkey(
  key: string,
  callback: () => void,
  options?: HotkeyOptions,
): void {
  const callbackRef = useRef(callback)
  useEffect(() => {
    callbackRef.current = callback
  }, [callback])

  const optionsRef = useRef(options)
  useEffect(() => {
    optionsRef.current = options
  }, [options])

  const keyRef = useRef(key)
  useEffect(() => {
    keyRef.current = key
  }, [key])

  useEffect(() => {
    function handler(event: KeyboardEvent) {
      if (optionsRef.current?.disabled) return
      if (isEditableElement(event.target)) return

      const currentKey = keyRef.current
      const isSequence = currentKey.includes(' ')
      const eventKey = event.key.toLowerCase()

      if (isSequence) {
        // Sequence mode: e.g. "g p"
        const parts = currentKey.split(' ')
        const hasModifiers =
          optionsRef.current?.modifiers && optionsRef.current.modifiers.length > 0

        // For sequences, modifiers should not be required
        if (hasModifiers) return

        // Don't match modifier keys themselves
        if (event.metaKey || event.ctrlKey || event.altKey || event.shiftKey) return

        if (sequenceBuffer === '' && eventKey === parts[0]) {
          sequenceBuffer = eventKey
          if (sequenceTimer) clearTimeout(sequenceTimer)
          sequenceTimer = setTimeout(resetSequence, SEQUENCE_TIMEOUT)
          return
        }

        if (sequenceBuffer === parts[0] && eventKey === parts[1]) {
          event.preventDefault()
          resetSequence()
          callbackRef.current()
          return
        }

        // Wrong second key — reset
        if (sequenceBuffer !== '') {
          resetSequence()
        }
      } else {
        // Single key mode
        if (!modifiersMatch(event, optionsRef.current?.modifiers)) return
        if (eventKey !== currentKey.toLowerCase()) return

        event.preventDefault()
        callbackRef.current()
      }
    }

    document.addEventListener('keydown', handler)
    return () => {
      document.removeEventListener('keydown', handler)
    }
  }, [])
}
