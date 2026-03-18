import { describe, it, expect, afterEach } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'
import { StatusBadge } from '../status-badge'

describe('StatusBadge', () => {
  afterEach(cleanup)

  it('renders label text', () => {
    render(<StatusBadge status="succeeded" />)
    expect(screen.getByText('Succeeded')).toBeDefined()
  })

  it('renders custom label when provided', () => {
    render(<StatusBadge status="succeeded" label="Completed" />)
    expect(screen.getByText('Completed')).toBeDefined()
  })

  it('applies success variant for succeeded status', () => {
    render(<StatusBadge status="succeeded" />)
    const badge = screen.getByText('Succeeded')
    expect(badge.className).toContain('bg-emerald')
  })

  it('applies destructive variant for failed status', () => {
    render(<StatusBadge status="failed" />)
    const badge = screen.getByText('Failed')
    expect(badge.className).toContain('bg-red')
  })

  it('applies warning variant for processing status', () => {
    render(<StatusBadge status="processing" />)
    const badge = screen.getByText('Processing')
    expect(badge.className).toContain('bg-amber')
  })

  it('applies muted variant for cancelled status', () => {
    render(<StatusBadge status="cancelled" />)
    const badge = screen.getByText('Cancelled')
    expect(badge.className).toContain('bg-gray')
  })

  it('applies info variant for requires_capture status', () => {
    render(<StatusBadge status="requires_capture" />)
    const badge = screen.getByText('Requires Capture')
    expect(badge.className).toContain('bg-blue')
  })

  it('handles refund statuses', () => {
    render(<StatusBadge status="pending" />)
    expect(screen.getByText('Pending')).toBeDefined()
  })

  it('applies custom className', () => {
    render(<StatusBadge status="succeeded" className="ml-2" />)
    const badge = screen.getByText('Succeeded')
    expect(badge.className).toContain('ml-2')
  })

  it('humanizes unknown statuses', () => {
    render(<StatusBadge status={'some_unknown_status' as never} />)
    expect(screen.getByText('Some Unknown Status')).toBeDefined()
  })
})
