import { describe, it, expect } from 'vitest'
import { buildSearchParams } from '../endpoints/params'

describe('buildSearchParams', () => {
  it('returns empty params when no args', () => {
    const sp = buildSearchParams()
    expect(sp.toString()).toBe('')
  })

  it('builds filter params in JSON:API format', () => {
    const sp = buildSearchParams({
      filter: { status: 'active', test_mode: true },
    })

    expect(sp.get('filter[status]')).toBe('active')
    expect(sp.get('filter[test_mode]')).toBe('true')
  })

  it('builds sort param', () => {
    const sp = buildSearchParams({ sort: '-created_at,name' })
    expect(sp.get('sort')).toBe('-created_at,name')
  })

  it('builds page params in JSON:API format', () => {
    const sp = buildSearchParams({
      page: { number: 2, size: 20 },
    })

    expect(sp.get('page[number]')).toBe('2')
    expect(sp.get('page[size]')).toBe('20')
  })

  it('builds include param', () => {
    const sp = buildSearchParams({ include: 'merchant,profile' })
    expect(sp.get('include')).toBe('merchant,profile')
  })

  it('combines all params', () => {
    const sp = buildSearchParams({
      filter: { status: 'active' },
      sort: '-created_at',
      page: { number: 1, size: 10 },
      include: 'merchant',
    })

    expect(sp.get('filter[status]')).toBe('active')
    expect(sp.get('sort')).toBe('-created_at')
    expect(sp.get('page[number]')).toBe('1')
    expect(sp.get('page[size]')).toBe('10')
    expect(sp.get('include')).toBe('merchant')
  })
})
