import { PieChart, Pie, Cell, Tooltip, ResponsiveContainer, Legend } from 'recharts'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import type { PaymentMethodStat } from '@/api/endpoints/analytics'

interface PaymentMethodsChartProps {
  data: PaymentMethodStat[]
}

const TOOLTIP_CONTENT_STYLE = {
  backgroundColor: 'hsl(var(--color-card))',
  borderColor: 'hsl(var(--color-border))',
  borderRadius: '8px',
} as const

const COLORS = [
  'hsl(var(--color-primary))',
  'hsl(var(--color-destructive))',
  'hsl(221 83% 53%)',
  'hsl(142 71% 45%)',
  'hsl(38 92% 50%)',
  'hsl(262 83% 58%)',
]

export default function PaymentMethodsChart({ data }: PaymentMethodsChartProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Методы оплаты</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="h-[300px]">
          <ResponsiveContainer width="100%" height="100%">
            <PieChart>
              <Pie
                data={data}
                dataKey="count"
                nameKey="method"
                cx="50%"
                cy="50%"
                innerRadius={60}
                outerRadius={100}
                paddingAngle={2}
              >
                {data.map((_, index) => (
                  <Cell key={index} fill={COLORS[index % COLORS.length]} />
                ))}
              </Pie>
              <Tooltip contentStyle={TOOLTIP_CONTENT_STYLE} />
              <Legend />
            </PieChart>
          </ResponsiveContainer>
        </div>
      </CardContent>
    </Card>
  )
}
