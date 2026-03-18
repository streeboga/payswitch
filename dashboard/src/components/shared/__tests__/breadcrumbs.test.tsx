import { describe, it, expect, afterEach, vi } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'
import { Breadcrumbs } from '../breadcrumbs'
import type { BreadcrumbItem } from '../breadcrumbs'

vi.mock('@tanstack/react-router', () => ({
  Link: ({
    children,
    to,
    className,
  }: {
    children: React.ReactNode
    to: string
    className?: string
  }) => (
    <a href={to} className={className}>
      {children}
    </a>
  ),
}))

function renderBreadcrumbs(items: BreadcrumbItem[]) {
  return render(<Breadcrumbs items={items} />)
}

describe('Breadcrumbs', () => {
  afterEach(cleanup)

  it('renders items with links for non-last items', () => {
    renderBreadcrumbs([{ label: 'Платежи', href: '/payments' }, { label: 'pay_abc123' }])

    const link = screen.getByRole('link', { name: 'Платежи' })
    expect(link).toBeDefined()
    expect(link.getAttribute('href')).toBe('/payments')
    expect(screen.getByText('pay_abc123')).toBeDefined()
  })

  it('renders last item as non-link with font-medium class', () => {
    renderBreadcrumbs([{ label: 'Платежи', href: '/payments' }, { label: 'pay_abc123' }])

    const lastItem = screen.getByText('pay_abc123')
    expect(lastItem.tagName).not.toBe('A')
    expect(lastItem.className).toContain('font-medium')
  })

  it('renders separator between items', () => {
    renderBreadcrumbs([{ label: 'Платежи', href: '/payments' }, { label: 'pay_abc123' }])

    expect(screen.getByText('/')).toBeDefined()
  })

  it('renders nothing when fewer than 2 items', () => {
    const { container } = renderBreadcrumbs([{ label: 'Обзор' }])
    expect(container.innerHTML).toBe('')
  })

  it('renders nothing when items array is empty', () => {
    const { container } = renderBreadcrumbs([])
    expect(container.innerHTML).toBe('')
  })

  it('renders multiple breadcrumb levels', () => {
    renderBreadcrumbs([
      { label: 'Коннекторы', href: '/connectors' },
      { label: 'Настройки', href: '/connectors/settings' },
      { label: 'stripe' },
    ])

    const links = screen.getAllByRole('link')
    expect(links).toHaveLength(2)
    expect(screen.getByText('stripe')).toBeDefined()
    expect(screen.getAllByText('/')).toHaveLength(2)
  })
})
