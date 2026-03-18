import type { ReactNode } from 'react'
import { X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import type { Row, Table } from '@tanstack/react-table'

// ─── Selection Column Helper ─────────────────────────────────

export function createSelectionColumn<TData>() {
  return {
    id: 'select',
    header: ({ table }: { table: Table<TData> }) => (
      <Checkbox
        checked={
          table.getIsAllPageRowsSelected() ||
          (table.getIsSomePageRowsSelected() && 'indeterminate')
        }
        onCheckedChange={(value) => table.toggleAllPageRowsSelected(!!value)}
        aria-label="Select all"
      />
    ),
    cell: ({ row }: { row: Row<TData> }) => (
      <Checkbox
        checked={row.getIsSelected()}
        onCheckedChange={(value) => row.toggleSelected(!!value)}
        aria-label="Select row"
      />
    ),
    enableSorting: false,
    enableHiding: false,
    size: 40,
  }
}

// ─── Selection Toolbar ───────────────────────────────────────

interface TableSelectionProps {
  selectedCount: number
  totalCount: number
  onClearSelection: () => void
  children?: ReactNode
}

export function TableSelection({
  selectedCount,
  totalCount,
  onClearSelection,
  children,
}: TableSelectionProps) {
  return (
    <div className="bg-muted flex items-center justify-between rounded-md px-4 py-2">
      <div className="flex items-center gap-3">
        <span className="text-sm font-medium">
          {selectedCount} of {totalCount} selected
        </span>
        <Button variant="ghost" size="xs" onClick={onClearSelection}>
          <X className="mr-1 h-3 w-3" />
          Clear
        </Button>
      </div>
      <div className="flex items-center gap-2">{children}</div>
    </div>
  )
}
