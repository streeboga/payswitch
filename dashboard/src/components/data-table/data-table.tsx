import { Fragment, type ReactNode } from 'react'
import {
  flexRender,
  type ColumnDef,
  type Row,
  type Table as TanstackTable,
} from '@tanstack/react-table'
import { cn } from '@/lib/utils'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Skeleton } from '@/components/ui/skeleton'
import { EmptyState } from '@/components/shared/empty-state'
import { ErrorState } from '@/components/shared/error-state'
import { TablePagination, type PaginationState } from './table-pagination'
import { TableSelection } from './table-selection'
import { TableDensityToggle } from './table-density'
import type { Density } from '@/stores/preferences'

// ─── Constants ──────────────────────────────────────────────

const DENSITY_PADDING: Record<Density, string> = {
  compact: 'py-1',
  comfortable: 'py-2',
  spacious: 'py-3',
}

// ─── Types ───────────────────────────────────────────────────

export interface DataTableProps<TData> {
  table: TanstackTable<TData>
  columns: ColumnDef<TData, unknown>[]
  isLoading?: boolean
  isError?: boolean
  errorStatus?: number
  errorMessage?: string
  onRetry?: () => void
  emptyTitle?: string
  emptyDescription?: string
  pagination?: PaginationState
  onPageChange?: (page: number) => void
  onPerPageChange?: (perPage: number) => void
  density?: Density
  selectionActions?: ReactNode
  toolbar?: ReactNode
  mobileCardRenderer?: (row: TData) => ReactNode
  renderExpandedRow?: (row: Row<TData>) => ReactNode
  onRowClick?: (row: TData) => void
}

// ─── Component ───────────────────────────────────────────────

export function DataTable<TData>({
  table,
  columns,
  isLoading = false,
  isError = false,
  errorStatus = 500,
  errorMessage,
  onRetry,
  emptyTitle = 'No results',
  emptyDescription = 'No data to display.',
  pagination,
  onPageChange,
  onPerPageChange,
  density = 'comfortable',
  selectionActions,
  toolbar,
  mobileCardRenderer,
  renderExpandedRow,
  onRowClick,
}: DataTableProps<TData>) {
  const selectedCount = table.getFilteredSelectedRowModel().rows.length
  const hasSelection = selectedCount > 0

  // Loading skeleton
  if (isLoading) {
    return (
      <div className="space-y-4">
        {toolbar}
        <div className="rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                {columns.map((_col, i) => (
                  <TableHead key={i}>
                    <Skeleton className="h-4 w-20" />
                  </TableHead>
                ))}
              </TableRow>
            </TableHeader>
            <TableBody>
              {Array.from({ length: 5 }).map((_, rowIdx) => (
                <TableRow key={rowIdx}>
                  {columns.map((_, colIdx) => (
                    <TableCell key={colIdx}>
                      <Skeleton className="h-4 w-full" />
                    </TableCell>
                  ))}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      </div>
    )
  }

  // Error state
  if (isError) {
    return (
      <div className="space-y-4">
        {toolbar}
        <ErrorState status={errorStatus} message={errorMessage} onRetry={onRetry} />
      </div>
    )
  }

  const rows = table.getRowModel().rows
  const isEmpty = rows.length === 0

  return (
    <div className="space-y-4">
      {toolbar}

      {hasSelection && selectionActions && (
        <TableSelection
          selectedCount={selectedCount}
          totalCount={table.getFilteredRowModel().rows.length}
          onClearSelection={() => table.toggleAllRowsSelected(false)}
        >
          {selectionActions}
        </TableSelection>
      )}

      {/* Mobile card view */}
      {mobileCardRenderer && (
        <div className="space-y-3 md:hidden">
          {isEmpty ? (
            <EmptyState title={emptyTitle} description={emptyDescription} />
          ) : (
            rows.map((row) => <div key={row.id}>{mobileCardRenderer(row.original)}</div>)
          )}
        </div>
      )}

      {/* Desktop table view */}
      <div className={cn('rounded-md border', mobileCardRenderer && 'hidden md:block')}>
        <Table>
          <TableHeader className="bg-muted/50 sticky top-0 z-10">
            {table.getHeaderGroups().map((headerGroup) => (
              <TableRow key={headerGroup.id}>
                {headerGroup.headers.map((header) => (
                  <TableHead
                    key={header.id}
                    className={cn(
                      DENSITY_PADDING[density],
                      header.column.getCanHide() &&
                        header.column.columnDef.meta &&
                        'hiddenOnMobile' in (header.column.columnDef.meta as object) &&
                        (
                          header.column.columnDef.meta as {
                            hiddenOnMobile: boolean
                          }
                        ).hiddenOnMobile &&
                        'hidden lg:table-cell',
                    )}
                    style={{
                      width: header.getSize(),
                    }}
                  >
                    {header.isPlaceholder
                      ? null
                      : flexRender(header.column.columnDef.header, header.getContext())}
                  </TableHead>
                ))}
              </TableRow>
            ))}
          </TableHeader>
          <TableBody>
            {isEmpty ? (
              <TableRow>
                <TableCell colSpan={columns.length} className="h-48">
                  <EmptyState title={emptyTitle} description={emptyDescription} />
                </TableCell>
              </TableRow>
            ) : (
              rows.map((row) => (
                <Fragment key={row.id}>
                  <TableRow
                    data-state={row.getIsSelected() ? 'selected' : undefined}
                    className={cn(onRowClick && 'cursor-pointer')}
                    onClick={onRowClick ? () => onRowClick(row.original) : undefined}
                    style={{ contentVisibility: 'auto', containIntrinsicSize: '0 40px' }}
                  >
                    {row.getVisibleCells().map((cell) => (
                      <TableCell
                        key={cell.id}
                        className={cn(
                          DENSITY_PADDING[density],
                          cell.column.columnDef.meta &&
                            'hiddenOnMobile' in (cell.column.columnDef.meta as object) &&
                            (
                              cell.column.columnDef.meta as {
                                hiddenOnMobile: boolean
                              }
                            ).hiddenOnMobile &&
                            'hidden lg:table-cell',
                        )}
                      >
                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                      </TableCell>
                    ))}
                  </TableRow>
                  {renderExpandedRow && row.getIsExpanded() && (
                    <TableRow>
                      <TableCell colSpan={columns.length} className="bg-muted/30 p-0">
                        {renderExpandedRow(row)}
                      </TableCell>
                    </TableRow>
                  )}
                </Fragment>
              ))
            )}
          </TableBody>
        </Table>
      </div>

      {pagination && onPageChange && onPerPageChange && (
        <TablePagination
          pagination={pagination}
          onPageChange={onPageChange}
          onPerPageChange={onPerPageChange}
        />
      )}
    </div>
  )
}

// Re-export density toggle for toolbar composition
export { TableDensityToggle }
