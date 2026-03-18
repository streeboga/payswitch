import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  ResponsiveContainer,
} from 'recharts'
import type { ConnectorComparison } from '@/api/endpoints/dashboard-connector-health'

interface ComparisonBarChartProps {
  data: ConnectorComparison[]
}

export default function ComparisonBarChart({ data }: ComparisonBarChartProps) {
  if (data.length === 0) {
    return (
      <p className="text-muted-foreground flex h-64 items-center justify-center text-sm">
        Нет данных для сравнения
      </p>
    )
  }

  return (
    <ResponsiveContainer width="100%" height={256}>
      <BarChart data={data}>
        <CartesianGrid strokeDasharray="3 3" />
        <XAxis dataKey="connector_name" fontSize={12} />
        <YAxis yAxisId="rate" unit="%" domain={[0, 100]} fontSize={12} />
        <YAxis yAxisId="latency" orientation="right" unit=" мс" fontSize={12} />
        <Tooltip />
        <Legend />
        <Bar
          yAxisId="rate"
          dataKey="success_rate"
          name="Успешность (%)"
          fill="hsl(152, 69%, 40%)"
          radius={[4, 4, 0, 0]}
        />
        <Bar
          yAxisId="latency"
          dataKey="avg_latency_ms"
          name="Задержка (мс)"
          fill="hsl(var(--primary))"
          radius={[4, 4, 0, 0]}
        />
      </BarChart>
    </ResponsiveContainer>
  )
}
