import { useMemo } from 'react'
import { cn } from '@/lib/utils'

// Currencies with 0 decimal places
const ZERO_DECIMAL_CURRENCIES = new Set([
  'BIF',
  'CLP',
  'DJF',
  'GNF',
  'JPY',
  'KMF',
  'KRW',
  'MGA',
  'PYG',
  'RWF',
  'UGX',
  'VND',
  'VUV',
  'XAF',
  'XOF',
  'XPF',
])

function getDecimalPlaces(currency: string): number {
  return ZERO_DECIMAL_CURRENCIES.has(currency.toUpperCase()) ? 0 : 2
}

interface MoneyFormatProps {
  amount: number
  currency: string
  className?: string
}

export function MoneyFormat({ amount, currency, className }: MoneyFormatProps) {
  const formatted = useMemo(() => {
    const decimals = getDecimalPlaces(currency)
    const major = decimals > 0 ? amount / 10 ** decimals : amount

    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: currency.toUpperCase(),
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    }).format(major)
  }, [amount, currency])

  return <span className={cn('tabular-nums', className)}>{formatted}</span>
}
