import {
  LineChart,
  Line,
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

interface PaymentsChartProps {
  data: ChartPoint[]
}

export default function PaymentsChart({ data }: PaymentsChartProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Платежи по дням</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="h-[300px]">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={data}>
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
              />
              <Tooltip
                contentStyle={TOOLTIP_CONTENT_STYLE}
                labelStyle={TOOLTIP_LABEL_STYLE}
              />
              <Line
                type="monotone"
                dataKey="payments"
                name="Платежи"
                stroke="hsl(var(--color-primary))"
                strokeWidth={2}
                dot={false}
                activeDot={{ r: 4 }}
              />
            </LineChart>
          </ResponsiveContainer>
        </div>
      </CardContent>
    </Card>
  )
}
