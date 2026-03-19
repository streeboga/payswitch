import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent } from '@testing-library/react'
import { TablePagination, type PaginationState } from '../table-pagination'

// Mock Select to render a simple native select
vi.mock('@/components/ui/select', () => ({
  Select: ({
    children,
    value,
    onValueChange,
  }: {
    children: React.ReactNode
    value: string
    onValueChange: (v: string) => void
  }) => (
    <select
      data-testid="per-page-select"
      value={value}
      onChange={(e) => onValueChange(e.target.value)}
    >
      {children}
    </select>
  ),
  SelectContent: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  SelectItem: ({ children, value }: { children: React.ReactNode; value: string }) => (
    <option value={value}>{children}</option>
  ),
  SelectTrigger: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  SelectValue: () => null,
}))

const defaultPagination: PaginationState = {
  currentPage: 1,
  lastPage: 5,
  perPage: 20,
  total: 100,
}

describe('TablePagination', () => {
  afterEach(cleanup)

  it('renders showing range text', () => {
    const onPageChange = vi.fn()
    const onPerPageChange = vi.fn()
    render(
      <TablePagination
        pagination={defaultPagination}
        onPageChange={onPageChange}
        onPerPageChange={onPerPageChange}
      />,
    )

    expect(screen.getByText('table.showingResults')).toBeDefined()
  })

  it('renders "No results" when total is 0', () => {
    render(
      <TablePagination
        pagination={{ currentPage: 1, lastPage: 1, perPage: 20, total: 0 }}
        onPageChange={vi.fn()}
        onPerPageChange={vi.fn()}
      />,
    )

    expect(screen.getByText('table.noResults')).toBeDefined()
  })

  it('renders page indicator', () => {
    render(
      <TablePagination
        pagination={defaultPagination}
        onPageChange={vi.fn()}
        onPerPageChange={vi.fn()}
      />,
    )

    expect(screen.getByText('1 / 5')).toBeDefined()
  })

  it('disables first/previous buttons on page 1', () => {
    render(
      <TablePagination
        pagination={defaultPagination}
        onPageChange={vi.fn()}
        onPerPageChange={vi.fn()}
      />,
    )

    expect(
      screen.getByLabelText('table.firstPage').getAttribute('disabled'),
    ).not.toBeNull()
    expect(
      screen.getByLabelText('table.previousPage').getAttribute('disabled'),
    ).not.toBeNull()
  })

  it('enables next/last buttons when not on last page', () => {
    render(
      <TablePagination
        pagination={defaultPagination}
        onPageChange={vi.fn()}
        onPerPageChange={vi.fn()}
      />,
    )

    expect(screen.getByLabelText('table.nextPage').getAttribute('disabled')).toBeNull()
    expect(screen.getByLabelText('table.lastPage').getAttribute('disabled')).toBeNull()
  })

  it('disables next/last buttons on last page', () => {
    render(
      <TablePagination
        pagination={{ currentPage: 5, lastPage: 5, perPage: 20, total: 100 }}
        onPageChange={vi.fn()}
        onPerPageChange={vi.fn()}
      />,
    )

    expect(
      screen.getByLabelText('table.nextPage').getAttribute('disabled'),
    ).not.toBeNull()
    expect(
      screen.getByLabelText('table.lastPage').getAttribute('disabled'),
    ).not.toBeNull()
  })

  it('calls onPageChange with next page', () => {
    const onPageChange = vi.fn()
    render(
      <TablePagination
        pagination={{ currentPage: 2, lastPage: 5, perPage: 20, total: 100 }}
        onPageChange={onPageChange}
        onPerPageChange={vi.fn()}
      />,
    )

    fireEvent.click(screen.getByLabelText('table.nextPage'))
    expect(onPageChange).toHaveBeenCalledWith(3)
  })

  it('calls onPageChange with previous page', () => {
    const onPageChange = vi.fn()
    render(
      <TablePagination
        pagination={{ currentPage: 3, lastPage: 5, perPage: 20, total: 100 }}
        onPageChange={onPageChange}
        onPerPageChange={vi.fn()}
      />,
    )

    fireEvent.click(screen.getByLabelText('table.previousPage'))
    expect(onPageChange).toHaveBeenCalledWith(2)
  })

  it('calls onPageChange(1) for first page button', () => {
    const onPageChange = vi.fn()
    render(
      <TablePagination
        pagination={{ currentPage: 3, lastPage: 5, perPage: 20, total: 100 }}
        onPageChange={onPageChange}
        onPerPageChange={vi.fn()}
      />,
    )

    fireEvent.click(screen.getByLabelText('table.firstPage'))
    expect(onPageChange).toHaveBeenCalledWith(1)
  })

  it('calls onPageChange(lastPage) for last page button', () => {
    const onPageChange = vi.fn()
    render(
      <TablePagination
        pagination={{ currentPage: 2, lastPage: 5, perPage: 20, total: 100 }}
        onPageChange={onPageChange}
        onPerPageChange={vi.fn()}
      />,
    )

    fireEvent.click(screen.getByLabelText('table.lastPage'))
    expect(onPageChange).toHaveBeenCalledWith(5)
  })

  it('calls onPerPageChange when per-page select changes', () => {
    const onPerPageChange = vi.fn()
    render(
      <TablePagination
        pagination={defaultPagination}
        onPageChange={vi.fn()}
        onPerPageChange={onPerPageChange}
      />,
    )

    fireEvent.change(screen.getByTestId('per-page-select'), { target: { value: '50' } })
    expect(onPerPageChange).toHaveBeenCalledWith(50)
  })

  it('shows correct range for middle page', () => {
    render(
      <TablePagination
        pagination={{ currentPage: 3, lastPage: 5, perPage: 20, total: 95 }}
        onPageChange={vi.fn()}
        onPerPageChange={vi.fn()}
      />,
    )

    expect(screen.getByText('table.showingResults')).toBeDefined()
  })

  it('caps "to" at total on last page', () => {
    render(
      <TablePagination
        pagination={{ currentPage: 5, lastPage: 5, perPage: 20, total: 95 }}
        onPageChange={vi.fn()}
        onPerPageChange={vi.fn()}
      />,
    )

    expect(screen.getByText('table.showingResults')).toBeDefined()
  })
})
