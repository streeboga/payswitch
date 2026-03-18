/**
 * @vitest-environment happy-dom
 */
import { describe, it, expect, beforeEach, vi } from 'vitest'

// Mock zustand persist to use a simple in-memory storage
vi.mock('zustand/middleware', async () => {
  const actual =
    await vi.importActual<typeof import('zustand/middleware')>('zustand/middleware')
  return {
    ...actual,
    persist: (config: unknown) => config,
  }
})

// Import AFTER mocking
const { useSavedFiltersStore, FILTER_PRESETS } = await import('../saved-filters')

describe('useSavedFiltersStore', () => {
  beforeEach(() => {
    useSavedFiltersStore.setState({ filters: [] })
  })

  it('начальное состояние — пустой список фильтров', () => {
    const state = useSavedFiltersStore.getState()
    expect(state.filters).toEqual([])
  })

  it('save добавляет фильтр с уникальным id', () => {
    useSavedFiltersStore.getState().save({
      name: 'Тестовый фильтр',
      page: 'payments',
      params: { status: 'failed' },
    })

    const state = useSavedFiltersStore.getState()
    expect(state.filters).toHaveLength(1)
    expect(state.filters[0]!.name).toBe('Тестовый фильтр')
    expect(state.filters[0]!.page).toBe('payments')
    expect(state.filters[0]!.params).toEqual({ status: 'failed' })
    expect(state.filters[0]!.id).toMatch(/^sf-/)
  })

  it('save добавляет несколько фильтров', () => {
    const { save } = useSavedFiltersStore.getState()

    save({ name: 'Фильтр 1', page: 'payments', params: { status: 'failed' } })
    save({ name: 'Фильтр 2', page: 'webhooks', params: { delivered: 'false' } })

    const state = useSavedFiltersStore.getState()
    expect(state.filters).toHaveLength(2)
    expect(state.filters[0]!.id).not.toBe(state.filters[1]!.id)
  })

  it('remove удаляет фильтр по id', () => {
    useSavedFiltersStore.getState().save({
      name: 'Удаляемый',
      page: 'payments',
      params: { status: 'failed' },
    })

    const id = useSavedFiltersStore.getState().filters[0]!.id
    useSavedFiltersStore.getState().remove(id)

    expect(useSavedFiltersStore.getState().filters).toHaveLength(0)
  })

  it('remove не удаляет другие фильтры', () => {
    const { save } = useSavedFiltersStore.getState()
    save({ name: 'Первый', page: 'payments', params: {} })
    save({ name: 'Второй', page: 'payments', params: {} })

    const firstId = useSavedFiltersStore.getState().filters[0]!.id
    useSavedFiltersStore.getState().remove(firstId)

    const state = useSavedFiltersStore.getState()
    expect(state.filters).toHaveLength(1)
    expect(state.filters[0]!.name).toBe('Второй')
  })

  it('rename переименовывает фильтр по id', () => {
    useSavedFiltersStore.getState().save({
      name: 'Старое имя',
      page: 'payments',
      params: { status: 'failed' },
    })

    const id = useSavedFiltersStore.getState().filters[0]!.id
    useSavedFiltersStore.getState().rename(id, 'Новое имя')

    expect(useSavedFiltersStore.getState().filters[0]!.name).toBe('Новое имя')
  })

  it('rename не меняет params и page', () => {
    useSavedFiltersStore.getState().save({
      name: 'Исходный',
      page: 'webhooks',
      params: { delivered: 'false' },
    })

    const id = useSavedFiltersStore.getState().filters[0]!.id
    useSavedFiltersStore.getState().rename(id, 'Переименованный')

    const filter = useSavedFiltersStore.getState().filters[0]!
    expect(filter.page).toBe('webhooks')
    expect(filter.params).toEqual({ delivered: 'false' })
  })
})

describe('FILTER_PRESETS', () => {
  it('содержит предустановку «Неуспешные за сегодня»', () => {
    const preset = FILTER_PRESETS.find((p) => p.id === 'preset-failed-today')
    expect(preset).toBeDefined()
    expect(preset!.page).toBe('payments')
    expect(preset!.params.status).toBe('failed')
  })

  it('содержит предустановку «Требуют capture»', () => {
    const preset = FILTER_PRESETS.find((p) => p.id === 'preset-requires-capture')
    expect(preset).toBeDefined()
    expect(preset!.page).toBe('payments')
    expect(preset!.params.status).toBe('requires_capture')
  })

  it('содержит предустановку «Недоставленные вебхуки»', () => {
    const preset = FILTER_PRESETS.find((p) => p.id === 'preset-undelivered-webhooks')
    expect(preset).toBeDefined()
    expect(preset!.page).toBe('webhooks')
    expect(preset!.params.delivered).toBe('false')
  })
})
