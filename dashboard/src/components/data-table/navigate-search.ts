import type { NavigateFn } from '@tanstack/react-router'

/**
 * Helper to navigate with arbitrary search params.
 * TanStack Router's typed navigate doesn't accept dynamic search keys,
 * but our data-table hooks need to set arbitrary URL search params.
 */
export function navigateWithSearch(
  navigate: NavigateFn,
  updater: (prev: Record<string, unknown>) => Record<string, unknown>,
  replace = true,
) {
  // We cast the search updater to work around TanStack Router's strict typing,
  // since data-table hooks operate on arbitrary search param keys.
  void (navigate as (opts: { search: unknown; replace: boolean }) => void)({
    search: updater,
    replace,
  })
}
