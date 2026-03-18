import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'

export interface PaginationState {
  currentPage: number
  lastPage: number
  perPage: number
  total: number
}

interface TablePaginationProps {
  pagination: PaginationState
  onPageChange: (page: number) => void
  onPerPageChange: (perPage: number) => void
  perPageOptions?: number[]
}

export function TablePagination({
  pagination,
  onPageChange,
  onPerPageChange,
  perPageOptions = [20, 50, 100],
}: TablePaginationProps) {
  const { t } = useTranslation()
  const { currentPage, lastPage, perPage, total } = pagination
  const from = (currentPage - 1) * perPage + 1
  const to = Math.min(currentPage * perPage, total)

  return (
    <div className="flex flex-col items-center justify-between gap-4 sm:flex-row">
      <p className="text-muted-foreground text-sm">
        {total > 0 ? t('table.showingResults', { from, to, total }) : t('table.noResults')}
      </p>

      <div className="flex items-center gap-4">
        <div className="flex items-center gap-2">
          <span className="text-muted-foreground text-sm">{t('table.perPage')}</span>
          <Select
            value={String(perPage)}
            onValueChange={(v) => onPerPageChange(Number(v))}
          >
            <SelectTrigger size="sm" className="w-[70px]">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {perPageOptions.map((opt) => (
                <SelectItem key={opt} value={String(opt)}>
                  {opt}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="flex items-center gap-1">
          <Button
            variant="outline"
            size="icon-sm"
            onClick={() => onPageChange(1)}
            disabled={currentPage <= 1}
            aria-label={t('table.firstPage')}
          >
            <ChevronsLeft className="h-4 w-4" />
          </Button>
          <Button
            variant="outline"
            size="icon-sm"
            onClick={() => onPageChange(currentPage - 1)}
            disabled={currentPage <= 1}
            aria-label={t('table.previousPage')}
          >
            <ChevronLeft className="h-4 w-4" />
          </Button>

          <span className="text-muted-foreground px-2 text-sm">
            {currentPage} / {lastPage || 1}
          </span>

          <Button
            variant="outline"
            size="icon-sm"
            onClick={() => onPageChange(currentPage + 1)}
            disabled={currentPage >= lastPage}
            aria-label={t('table.nextPage')}
          >
            <ChevronRight className="h-4 w-4" />
          </Button>
          <Button
            variant="outline"
            size="icon-sm"
            onClick={() => onPageChange(lastPage)}
            disabled={currentPage >= lastPage}
            aria-label={t('table.lastPage')}
          >
            <ChevronsRight className="h-4 w-4" />
          </Button>
        </div>
      </div>
    </div>
  )
}
