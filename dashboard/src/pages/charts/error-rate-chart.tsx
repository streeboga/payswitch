import {
  AreaChart,
  Area,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from 'recharts'
import { format } from 'date-fns'
import type { ConnectorHealthHistoryPoint } from '@/api/endpoints/dashboard-connector-health'

interface ErrorRateChartProps {
  data: ConnectorHealthHistoryPoint[]
}

function formatTick(timestamp: string): string {
  return format(new Date(timestamp), 'MMM d HH:mm')
}

export default function ErrorRateChart({ data }: ErrorRateChartProps) {
  if (data.length === 0) {
    return (
      <p className="text-muted-foreground flex h-64 items-center justify-center text-sm">
        Нет данных за выбранный период
      </p>
    )
  }

  return (
    <ResponsiveContainer width="100%" height={256}>
      <AreaChart data={data}>
        <CartesianGrid strokeDasharray="3 3" />
        <XAxis dataKey="timestamp" tickFormatter={formatTick} fontSize={12} />
        <YAxis unit="%" fontSize={12} />
        <Tooltip
          labelFormatter={(label) => format(new Date(String(label)), 'MMM d, yyyy HH:mm')}
          formatter={(value) => [`${Number(value).toFixed(2)}%`, 'Ошибки']}
        />
        <Area
          type="monotone"
          dataKey="error_rate_percent"
          stroke="hsl(0, 84%, 60%)"
          fill="hsl(0, 84%, 60%)"
          fillOpacity={0.15}
          strokeWidth={2}
        />
      </AreaChart>
    </ResponsiveContainer>
  )
}
