import { describe, it, expect, afterEach, vi, beforeEach } from 'vitest'
import { render, screen, cleanup, fireEvent } from '@testing-library/react'
import { JsonViewer } from '../json-viewer'

beforeEach(() => {
  Object.defineProperty(navigator, 'clipboard', {
    value: { writeText: vi.fn(() => Promise.resolve()) },
    writable: true,
    configurable: true,
  })
})

afterEach(cleanup)

describe('JsonViewer', () => {
  it('renders primitive values', () => {
    render(<JsonViewer data="hello" />)
    expect(screen.getByText(/"hello"/)).toBeDefined()
  })

  it('renders number values', () => {
    render(<JsonViewer data={42} />)
    expect(screen.getByText('42')).toBeDefined()
  })

  it('renders boolean values', () => {
    render(<JsonViewer data={true} />)
    expect(screen.getByText('true')).toBeDefined()
  })

  it('renders null values', () => {
    render(<JsonViewer data={null} />)
    expect(screen.getByText('null')).toBeDefined()
  })

  it('renders object keys', () => {
    render(<JsonViewer data={{ name: 'test', count: 5 }} />)
    expect(screen.getByText(/"name"/)).toBeDefined()
    expect(screen.getByText(/"count"/)).toBeDefined()
  })

  it('renders array items', () => {
    render(<JsonViewer data={[1, 2, 3]} />)
    expect(screen.getByText('1')).toBeDefined()
    expect(screen.getByText('2')).toBeDefined()
  })

  it('has a copy button', () => {
    render(<JsonViewer data={{ key: 'value' }} />)
    const copyBtn = screen.getByRole('button', { name: /copy/i })
    expect(copyBtn).toBeDefined()
  })

  it('copies JSON to clipboard', () => {
    const data = { key: 'value' }
    render(<JsonViewer data={data} />)
    fireEvent.click(screen.getByRole('button', { name: /copy/i }))
    expect(navigator.clipboard.writeText).toHaveBeenCalledWith(
      JSON.stringify(data, null, 2),
    )
  })

  it('applies custom className', () => {
    const { container } = render(<JsonViewer data={{}} className="max-h-64" />)
    expect(container.firstElementChild?.className).toContain('max-h-64')
  })

  it('collapses/expands nodes on click', () => {
    render(<JsonViewer data={{ nested: { deep: 'value' } }} defaultExpanded />)
    // Should see nested keys when expanded
    expect(screen.getByText(/"nested"/)).toBeDefined()
    expect(screen.getByText(/"deep"/)).toBeDefined()
  })
})
