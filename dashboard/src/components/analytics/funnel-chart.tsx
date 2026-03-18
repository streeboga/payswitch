import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import type { FunnelData } from '@/api/endpoints/analytics'

interface FunnelChartProps {
  data: FunnelData
}

function formatNumber(value: number): string {
  return new Intl.NumberFormat('ru-RU').format(value)
}

function getDropout(from: number, to: number): string {
  if (from === 0) return '0%'
  const rate = ((from - to) / from) * 100
  return `−${rate.toFixed(1)}%`
}

const STAGE_COLORS = ['bg-primary', 'bg-primary/70', 'bg-primary/40'] as const

const STAGE_LABELS = ['Созданы', 'Подтверждены', 'Успешны'] as const

export default function FunnelChart({ data }: FunnelChartProps) {
  const stages = [
    { label: STAGE_LABELS[0], value: data.created },
    { label: STAGE_LABELS[1], value: data.confirmed },
    { label: STAGE_LABELS[2], value: data.succeeded },
  ]

  const maxValue = Math.max(data.created, 1)

  return (
    <Card>
      <CardHeader>
        <CardTitle>Воронка платежей</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        {stages.map((stage, i) => {
          const widthPercent = (stage.value / maxValue) * 100
          const prev = stages[i - 1]

          return (
            <div key={stage.label} className="space-y-1">
              <div className="flex items-center justify-between text-sm">
                <span>{stage.label}</span>
                <div className="flex items-center gap-2">
                  <span className="font-medium tabular-nums">
                    {formatNumber(stage.value)}
                  </span>
                  {prev ? (
                    <span className="text-muted-foreground text-xs">
                      {getDropout(prev.value, stage.value)}
                    </span>
                  ) : null}
                </div>
              </div>
              <div className="bg-muted h-6 w-full overflow-hidden rounded">
                <div
                  className={`h-full rounded ${STAGE_COLORS[i]}`}
                  style={{ width: `${widthPercent}%` }}
                />
              </div>
            </div>
          )
        })}
      </CardContent>
    </Card>
  )
}
