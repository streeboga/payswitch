import {
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from 'recharts'
import { format } from 'date-fns'
import type { ConnectorHealthHistoryPoint } from '@/api/endpoints/dashboard-connector-health'

interface LatencyChartProps {
  data: ConnectorHealthHistoryPoint[]
}

function formatTick(timestamp: string): string {
  return format(new Date(timestamp), 'MMM d HH:mm')
}

export default function LatencyChart({ data }: LatencyChartProps) {
  if (data.length === 0) {
    return (
      <p className="text-muted-foreground flex h-64 items-center justify-center text-sm">
        Нет данных за выбранный период
      </p>
    )
  }

  return (
    <ResponsiveContainer width="100%" height={256}>
      <LineChart data={data}>
        <CartesianGrid strokeDasharray="3 3" />
        <XAxis dataKey="timestamp" tickFormatter={formatTick} fontSize={12} />
        <YAxis unit=" мс" fontSize={12} />
        <Tooltip
          labelFormatter={(label) => format(new Date(String(label)), 'MMM d, yyyy HH:mm')}
          formatter={(value) => [`${Number(value)} мс`, 'Задержка']}
        />
        <Line
          type="monotone"
          dataKey="avg_latency_ms"
          stroke="hsl(var(--primary))"
          strokeWidth={2}
          dot={false}
        />
      </LineChart>
    </ResponsiveContainer>
  )
}
