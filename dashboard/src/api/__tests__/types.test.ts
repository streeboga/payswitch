import { describe, it, expect } from 'vitest'
import { extractAttributes, extractCollectionAttributes } from '../types/json-api'
import type { JsonApiResource } from '../types/json-api'
import {
  TERMINAL_PAYMENT_STATUSES,
  PAYMENT_STATUS_LABELS,
  REFUND_STATUS_LABELS,
} from '../types/enums'

describe('JSON:API type helpers', () => {
  it('extractAttributes returns attributes with id', () => {
    const resource: JsonApiResource<{ name: string }> = {
      type: 'organizations',
      id: 'org_123',
      attributes: { name: 'Test Org' },
    }

    const result = extractAttributes(resource)

    expect(result).toEqual({ id: 'org_123', name: 'Test Org' })
  })

  it('extractCollectionAttributes maps all resources', () => {
    const resources: JsonApiResource<{ name: string }>[] = [
      { type: 'organizations', id: 'org_1', attributes: { name: 'Org 1' } },
      { type: 'organizations', id: 'org_2', attributes: { name: 'Org 2' } },
    ]

    const result = extractCollectionAttributes(resources)

    expect(result).toHaveLength(2)
    expect(result[0]).toEqual({ id: 'org_1', name: 'Org 1' })
    expect(result[1]).toEqual({ id: 'org_2', name: 'Org 2' })
  })

  it('extractAttributes preserves all attribute fields', () => {
    const resource: JsonApiResource<{
      amount: number
      currency: string
      status: string
    }> = {
      type: 'payments',
      id: 'pay_abc',
      attributes: { amount: 1000, currency: 'USD', status: 'succeeded' },
      relationships: { merchant: { data: { type: 'merchants', id: 'm_1' } } },
      links: { self: '/api/v1/payments/pay_abc' },
    }

    const result = extractAttributes(resource)

    expect(result.id).toBe('pay_abc')
    expect(result.amount).toBe(1000)
    expect(result.currency).toBe('USD')
    expect(result.status).toBe('succeeded')
  })
})

describe('Enum constants', () => {
  it('TERMINAL_PAYMENT_STATUSES contains expected values', () => {
    expect(TERMINAL_PAYMENT_STATUSES).toContain('succeeded')
    expect(TERMINAL_PAYMENT_STATUSES).toContain('failed')
    expect(TERMINAL_PAYMENT_STATUSES).toContain('cancelled')
    expect(TERMINAL_PAYMENT_STATUSES).toContain('expired')
    expect(TERMINAL_PAYMENT_STATUSES).not.toContain('processing')
  })

  it('PAYMENT_STATUS_LABELS covers all statuses', () => {
    expect(Object.keys(PAYMENT_STATUS_LABELS)).toHaveLength(12)
    expect(PAYMENT_STATUS_LABELS.succeeded).toBe('Succeeded')
    expect(PAYMENT_STATUS_LABELS.processing).toBe('Processing')
  })

  it('REFUND_STATUS_LABELS covers all statuses', () => {
    expect(Object.keys(REFUND_STATUS_LABELS)).toHaveLength(4)
    expect(REFUND_STATUS_LABELS.pending).toBe('Pending')
  })
})
