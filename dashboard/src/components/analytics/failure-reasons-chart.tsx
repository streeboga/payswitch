import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer } from 'recharts'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import type { FailureReasonStat } from '@/api/endpoints/analytics'

const TOOLTIP_CONTENT_STYLE = {
  backgroundColor: 'hsl(var(--color-card))',
  borderColor: 'hsl(var(--color-border))',
  borderRadius: '8px',
} as const

interface FailureReasonsChartProps {
  data: FailureReasonStat[]
}

export default function FailureReasonsChart({ data }: FailureReasonsChartProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Причины отказов</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="h-[300px]">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={data} layout="vertical">
              <XAxis
                type="number"
                className="text-muted-foreground"
                fontSize={12}
                tickLine={false}
                axisLine={false}
              />
              <YAxis
                type="category"
                dataKey="reason"
                className="text-muted-foreground"
                fontSize={12}
                tickLine={false}
                axisLine={false}
                width={150}
              />
              <Tooltip contentStyle={TOOLTIP_CONTENT_STYLE} />
              <Bar
                dataKey="count"
                name="Количество"
                fill="hsl(var(--color-destructive))"
                radius={[0, 4, 4, 0]}
              />
            </BarChart>
          </ResponsiveContainer>
        </div>
      </CardContent>
    </Card>
  )
}
