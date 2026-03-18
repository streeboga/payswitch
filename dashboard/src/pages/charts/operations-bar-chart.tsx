import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from 'recharts'
import type { ConnectorOperationStats } from '@/api/endpoints/dashboard-connector-health'

interface OperationsBarChartProps {
  data: ConnectorOperationStats[]
}

export default function OperationsBarChart({ data }: OperationsBarChartProps) {
  if (data.length === 0) {
    return (
      <p className="text-muted-foreground flex h-64 items-center justify-center text-sm">
        Нет данных по операциям
      </p>
    )
  }

  return (
    <ResponsiveContainer width="100%" height={256}>
      <BarChart data={data}>
        <CartesianGrid strokeDasharray="3 3" />
        <XAxis dataKey="operation" fontSize={12} />
        <YAxis unit="%" domain={[0, 100]} fontSize={12} />
        <Tooltip formatter={(value) => [`${Number(value).toFixed(1)}%`, 'Успешность']} />
        <Bar dataKey="success_rate" fill="hsl(152, 69%, 40%)" radius={[4, 4, 0, 0]} />
      </BarChart>
    </ResponsiveContainer>
  )
}
