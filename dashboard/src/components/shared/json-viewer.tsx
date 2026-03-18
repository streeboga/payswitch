import { useCallback, useState } from 'react'
import { ChevronRight, Copy, Check } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'

interface JsonViewerProps {
  data: unknown
  className?: string
  defaultExpanded?: boolean
}

export function JsonViewer({ data, className, defaultExpanded = true }: JsonViewerProps) {
  const [copied, setCopied] = useState(false)

  const handleCopy = useCallback(async () => {
    await navigator.clipboard.writeText(JSON.stringify(data, null, 2))
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }, [data])

  return (
    <div
      className={cn(
        'bg-muted relative overflow-auto rounded-md border p-3 font-mono text-sm',
        className,
      )}
    >
      <Button
        type="button"
        variant="ghost"
        size="icon-xs"
        aria-label="Copy JSON"
        onClick={handleCopy}
        className="absolute top-2 right-2"
      >
        {copied ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
      </Button>
      <JsonNode value={data} defaultExpanded={defaultExpanded} />
    </div>
  )
}

interface JsonNodeProps {
  value: unknown
  name?: string
  defaultExpanded: boolean
  depth?: number
}

function JsonNode({ value, name, defaultExpanded, depth = 0 }: JsonNodeProps) {
  const [expanded, setExpanded] = useState(defaultExpanded)

  if (value === null) {
    return (
      <span>
        {name !== undefined && <JsonKey name={name} />}
        <span className="text-muted-foreground">null</span>
      </span>
    )
  }

  if (typeof value === 'boolean') {
    return (
      <span>
        {name !== undefined && <JsonKey name={name} />}
        <span className="text-amber-600 dark:text-amber-400">{String(value)}</span>
      </span>
    )
  }

  if (typeof value === 'number') {
    return (
      <span>
        {name !== undefined && <JsonKey name={name} />}
        <span className="text-blue-600 dark:text-blue-400">{value}</span>
      </span>
    )
  }

  if (typeof value === 'string') {
    return (
      <span>
        {name !== undefined && <JsonKey name={name} />}
        <span className="text-emerald-600 dark:text-emerald-400">
          &quot;{value}&quot;
        </span>
      </span>
    )
  }

  const isArray = Array.isArray(value)
  const entries = isArray
    ? (value as unknown[]).map((v, i) => [String(i), v] as const)
    : Object.entries(value as Record<string, unknown>)

  const bracket = isArray ? ['[', ']'] : ['{', '}']

  return (
    <Collapsible open={expanded} onOpenChange={setExpanded}>
      <div style={{ paddingLeft: depth > 0 ? 16 : 0 }}>
        <CollapsibleTrigger className="cursor-pointer select-none">
          <ChevronRight
            className={cn(
              'mr-1 inline h-3 w-3 transition-transform',
              expanded && 'rotate-90',
            )}
          />
          {name !== undefined && <JsonKey name={name} />}
          <span className="text-muted-foreground">
            {bracket[0]}
            {!expanded && ` ... ${entries.length} items ${bracket[1]}`}
          </span>
        </CollapsibleTrigger>
        <CollapsibleContent>
          {entries.map(([key, val]) => (
            <div key={key}>
              <JsonNode
                name={isArray ? undefined : key}
                value={val}
                defaultExpanded={defaultExpanded}
                depth={depth + 1}
              />
            </div>
          ))}
          <span className="text-muted-foreground" style={{ paddingLeft: 0 }}>
            {bracket[1]}
          </span>
        </CollapsibleContent>
      </div>
    </Collapsible>
  )
}

function JsonKey({ name }: { name: string }) {
  return (
    <span className="text-purple-600 dark:text-purple-400">&quot;{name}&quot;: </span>
  )
}
