import { Link, useMatches } from '@tanstack/react-router'
import { Badge } from '@/components/ui/badge'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import type { NavItem as NavItemType } from './nav-config'

interface NavItemProps {
  item: NavItemType
  collapsed: boolean
}

export function NavItem({ item, collapsed }: NavItemProps) {
  const matches = useMatches()
  const isActive = matches.some((m) => m.pathname === item.path)

  const Icon = item.icon

  const content = (
    <Link
      to={item.path}
      className={`flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors ${
        isActive
          ? 'bg-accent text-accent-foreground'
          : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground'
      } ${collapsed ? 'justify-center px-2' : ''}`}
      aria-current={isActive ? 'page' : undefined}
    >
      <Icon className="size-4 shrink-0" />
      {!collapsed && (
        <>
          <span className="flex-1 truncate">{item.label}</span>
          {item.badge != null && item.badge > 0 ? (
            <Badge variant="secondary" className="ml-auto h-5 min-w-5 px-1">
              {item.badge}
            </Badge>
          ) : null}
        </>
      )}
    </Link>
  )

  if (collapsed) {
    return (
      <Tooltip>
        <TooltipTrigger asChild>{content}</TooltipTrigger>
        <TooltipContent side="right" sideOffset={8}>
          {item.label}
        </TooltipContent>
      </Tooltip>
    )
  }

  return content
}
