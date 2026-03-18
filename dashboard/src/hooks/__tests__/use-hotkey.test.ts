import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest'
import { renderHook, cleanup, act } from '@testing-library/react'
import { useHotkey, getShortcutRegistry, registerShortcut } from '../use-hotkey'

afterEach(cleanup)

function fireKey(
  key: string,
  options: Partial<KeyboardEventInit> = {},
  target?: HTMLElement,
) {
  const event = new KeyboardEvent('keydown', {
    key,
    bubbles: true,
    ...options,
  })
  ;(target ?? document).dispatchEvent(event)
}

describe('useHotkey', () => {
  describe('single key shortcuts', () => {
    it('fires callback on matching key', () => {
      const cb = vi.fn()
      renderHook(() => useHotkey('a', cb))
      fireKey('a')
      expect(cb).toHaveBeenCalledTimes(1)
    })

    it('does not fire on non-matching key', () => {
      const cb = vi.fn()
      renderHook(() => useHotkey('a', cb))
      fireKey('b')
      expect(cb).not.toHaveBeenCalled()
    })

    it('is case-insensitive', () => {
      const cb = vi.fn()
      renderHook(() => useHotkey('a', cb))
      fireKey('A')
      expect(cb).toHaveBeenCalledTimes(1)
    })
  })

  describe('modifier keys', () => {
    it('fires with correct modifier', () => {
      const cb = vi.fn()
      renderHook(() => useHotkey('k', cb, { modifiers: ['meta'] }))
      fireKey('k', { metaKey: true })
      expect(cb).toHaveBeenCalledTimes(1)
    })

    it('does not fire without required modifier', () => {
      const cb = vi.fn()
      renderHook(() => useHotkey('k', cb, { modifiers: ['meta'] }))
      fireKey('k')
      expect(cb).not.toHaveBeenCalled()
    })

    it('does not fire with extra modifiers', () => {
      const cb = vi.fn()
      renderHook(() => useHotkey('k', cb, { modifiers: ['meta'] }))
      fireKey('k', { metaKey: true, shiftKey: true })
      expect(cb).not.toHaveBeenCalled()
    })

    it('handles multiple modifiers', () => {
      const cb = vi.fn()
      renderHook(() => useHotkey('k', cb, { modifiers: ['meta', 'shift'] }))
      fireKey('k', { metaKey: true, shiftKey: true })
      expect(cb).toHaveBeenCalledTimes(1)
    })
  })

  describe('key sequences', () => {
    beforeEach(() => {
      vi.useFakeTimers()
    })

    afterEach(() => {
      vi.useRealTimers()
    })

    it('fires on two-key sequence', () => {
      const cb = vi.fn()
      renderHook(() => useHotkey('g p', cb))

      fireKey('g')
      expect(cb).not.toHaveBeenCalled()

      fireKey('p')
      expect(cb).toHaveBeenCalledTimes(1)
    })

    it('does not fire if second key is wrong', () => {
      const cb = vi.fn()
      renderHook(() => useHotkey('g p', cb))

      fireKey('g')
      fireKey('x')
      expect(cb).not.toHaveBeenCalled()
    })

    it('resets sequence after timeout', () => {
      const cb = vi.fn()
      renderHook(() => useHotkey('g p', cb))

      fireKey('g')
      act(() => {
        vi.advanceTimersByTime(1100)
      })
      fireKey('p')
      expect(cb).not.toHaveBeenCalled()
    })

    it('fires when sequence completed within timeout', () => {
      const cb = vi.fn()
      renderHook(() => useHotkey('g o', cb))

      fireKey('g')
      act(() => {
        vi.advanceTimersByTime(500)
      })
      fireKey('o')
      expect(cb).toHaveBeenCalledTimes(1)
    })
  })

  describe('input guard', () => {
    it('does not fire when an input is focused', () => {
      const cb = vi.fn()
      renderHook(() => useHotkey('a', cb))

      const input = document.createElement('input')
      document.body.appendChild(input)
      fireKey('a', {}, input)

      expect(cb).not.toHaveBeenCalled()
      document.body.removeChild(input)
    })

    it('does not fire when a textarea is focused', () => {
      const cb = vi.fn()
      renderHook(() => useHotkey('a', cb))

      const textarea = document.createElement('textarea')
      document.body.appendChild(textarea)
      fireKey('a', {}, textarea)

      expect(cb).not.toHaveBeenCalled()
      document.body.removeChild(textarea)
    })

    it('does not fire on contenteditable elements', () => {
      const cb = vi.fn()
      renderHook(() => useHotkey('a', cb))

      const div = document.createElement('div')
      div.contentEditable = 'true'
      document.body.appendChild(div)
      fireKey('a', {}, div)

      expect(cb).not.toHaveBeenCalled()
      document.body.removeChild(div)
    })

    it('does not fire on role="textbox" elements', () => {
      const cb = vi.fn()
      renderHook(() => useHotkey('a', cb))

      const div = document.createElement('div')
      div.setAttribute('role', 'textbox')
      document.body.appendChild(div)
      fireKey('a', {}, div)

      expect(cb).not.toHaveBeenCalled()
      document.body.removeChild(div)
    })
  })

  describe('disabled option', () => {
    it('does not fire when disabled', () => {
      const cb = vi.fn()
      renderHook(() => useHotkey('a', cb, { disabled: true }))
      fireKey('a')
      expect(cb).not.toHaveBeenCalled()
    })
  })

  describe('cleanup on unmount', () => {
    it('removes listener on unmount', () => {
      const cb = vi.fn()
      const { unmount } = renderHook(() => useHotkey('a', cb))

      fireKey('a')
      expect(cb).toHaveBeenCalledTimes(1)

      unmount()
      fireKey('a')
      expect(cb).toHaveBeenCalledTimes(1)
    })
  })

  describe('central registry', () => {
    it('registers and unregisters shortcuts', () => {
      const unregister = registerShortcut({
        keys: 'g t',
        description: 'Test shortcut',
        group: 'Test',
      })

      const entries = getShortcutRegistry()
      expect(entries.some((e) => e.keys === 'g t')).toBe(true)

      unregister()
      const afterEntries = getShortcutRegistry()
      expect(afterEntries.some((e) => e.keys === 'g t')).toBe(false)
    })
  })
})
