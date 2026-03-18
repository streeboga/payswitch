import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from 'recharts'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import type { ChartPoint } from '@/api/endpoints/analytics'

const TOOLTIP_CONTENT_STYLE = {
  backgroundColor: 'hsl(var(--color-card))',
  borderColor: 'hsl(var(--color-border))',
  borderRadius: '8px',
} as const

const TOOLTIP_LABEL_STYLE = {
  color: 'hsl(var(--color-foreground))',
} as const

interface VolumeChartProps {
  data: ChartPoint[]
}

function formatVolume(value: number): string {
  if (value >= 100_000_00) {
    return `${(value / 100_000_00).toFixed(0)}K`
  }
  return new Intl.NumberFormat('ru-RU', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(value / 100)
}

export default function VolumeChart({ data }: VolumeChartProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Объём по дням</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="h-[300px]">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={data}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
              <XAxis
                dataKey="date"
                className="text-muted-foreground"
                fontSize={12}
                tickLine={false}
                axisLine={false}
              />
              <YAxis
                className="text-muted-foreground"
                fontSize={12}
                tickLine={false}
                axisLine={false}
                tickFormatter={formatVolume}
              />
              <Tooltip
                contentStyle={TOOLTIP_CONTENT_STYLE}
                labelStyle={TOOLTIP_LABEL_STYLE}
                formatter={(value) => [formatVolume(Number(value)), 'Объём']}
              />
              <Bar
                dataKey="volume"
                name="Объём"
                fill="hsl(var(--color-primary))"
                radius={[4, 4, 0, 0]}
              />
            </BarChart>
          </ResponsiveContainer>
        </div>
      </CardContent>
    </Card>
  )
}
