import { useMemo } from 'react'
import { format, formatDistanceToNow } from 'date-fns'
import { cn } from '@/lib/utils'

interface DateFormatProps {
  date: string | Date
  variant?: 'absolute' | 'relative'
  className?: string
}

export function DateFormat({ date, variant = 'absolute', className }: DateFormatProps) {
  const dateObj = useMemo(() => (date instanceof Date ? date : new Date(date)), [date])

  const absolute = useMemo(() => format(dateObj, 'MMM d, yyyy HH:mm'), [dateObj])

  const relative = useMemo(
    () => formatDistanceToNow(dateObj, { addSuffix: true }),
    [dateObj],
  )

  if (variant === 'relative') {
    return (
      <span className={cn('tabular-nums', className)} title={absolute}>
        {relative}
      </span>
    )
  }

  return (
    <span className={cn('tabular-nums', className)} title={relative}>
      {absolute}
    </span>
  )
}
