import { describe, it, expect, afterEach, vi } from 'vitest'
import { render, screen, cleanup, fireEvent } from '@testing-library/react'
import { ConfirmDialog } from '../confirm-dialog'

afterEach(cleanup)

describe('ConfirmDialog', () => {
  const defaultProps = {
    open: true,
    onConfirm: vi.fn(),
    onCancel: vi.fn(),
    title: 'Delete payment',
    description: 'Are you sure you want to delete this payment?',
  }

  it('renders title and description when open', () => {
    render(<ConfirmDialog {...defaultProps} />)
    expect(screen.getByText('Delete payment')).toBeDefined()
    expect(
      screen.getByText('Are you sure you want to delete this payment?'),
    ).toBeDefined()
  })

  it('does not render content when closed', () => {
    render(<ConfirmDialog {...defaultProps} open={false} />)
    expect(screen.queryByText('Delete payment')).toBeNull()
  })

  it('calls onConfirm when confirm button clicked', () => {
    const onConfirm = vi.fn()
    render(<ConfirmDialog {...defaultProps} onConfirm={onConfirm} />)
    fireEvent.click(screen.getByRole('button', { name: /confirm/i }))
    expect(onConfirm).toHaveBeenCalledOnce()
  })

  it('calls onCancel when cancel button clicked', () => {
    const onCancel = vi.fn()
    render(<ConfirmDialog {...defaultProps} onCancel={onCancel} />)
    fireEvent.click(screen.getByRole('button', { name: /cancel/i }))
    expect(onCancel).toHaveBeenCalledOnce()
  })

  it('renders destructive variant with red confirm button', () => {
    render(<ConfirmDialog {...defaultProps} destructive />)
    const confirmBtn = screen.getByRole('button', { name: /confirm/i })
    expect(confirmBtn.className).toContain('destructive')
  })

  it('renders custom confirm and cancel labels', () => {
    render(
      <ConfirmDialog
        {...defaultProps}
        confirmLabel="Yes, delete"
        cancelLabel="No, keep"
      />,
    )
    expect(screen.getByText('Yes, delete')).toBeDefined()
    expect(screen.getByText('No, keep')).toBeDefined()
  })

  it('disables confirm when requireInput is set and input is empty', () => {
    render(<ConfirmDialog {...defaultProps} requireInput="DELETE" />)
    const confirmBtn = screen.getByRole('button', { name: /confirm/i })
    expect(confirmBtn.hasAttribute('disabled')).toBe(true)
  })

  it('enables confirm when requireInput matches', () => {
    render(<ConfirmDialog {...defaultProps} requireInput="DELETE" />)
    const input = screen.getByRole('textbox')
    fireEvent.change(input, { target: { value: 'DELETE' } })
    const confirmBtn = screen.getByRole('button', { name: /confirm/i })
    expect(confirmBtn.hasAttribute('disabled')).toBe(false)
  })
})
