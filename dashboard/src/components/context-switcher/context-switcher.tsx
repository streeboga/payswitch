import { useEffect, useCallback, useMemo } from 'react'
import { Building2, Store, Briefcase } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { useContextStore } from '@/stores/context'
import { useOrganizations, useMerchants, useProfiles } from '@/hooks/use-context-data'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Avatar, AvatarFallback, AvatarGroup } from '@/components/ui/avatar'

const ALL_PROFILES_VALUE = '__all__'

// Stable empty arrays to avoid creating new references on each render
const EMPTY_ARRAY: never[] = []

export function ContextSwitcher() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const currentOrgKey = useContextStore((s) => s.currentOrgKey)
  const currentMerchantKey = useContextStore((s) => s.currentMerchantKey)
  const currentProfileKey = useContextStore((s) => s.currentProfileKey)
  const setOrg = useContextStore((s) => s.setOrg)
  const setMerchant = useContextStore((s) => s.setMerchant)
  const setProfile = useContextStore((s) => s.setProfile)

  const { data: orgsData, isLoading: orgsLoading } = useOrganizations()
  const { data: merchantsData, isLoading: merchantsLoading } = useMerchants(currentOrgKey)
  const { data: profilesData, isLoading: profilesLoading } =
    useProfiles(currentMerchantKey)

  const organizations = orgsData ?? EMPTY_ARRAY
  const merchants = merchantsData ?? EMPTY_ARRAY
  const profiles = profilesData ?? EMPTY_ARRAY

  const orgMap = useMemo(
    () => new Map(organizations.map((o) => [o.key, o])),
    [organizations],
  )
  const merchantMap = useMemo(
    () => new Map(merchants.map((m) => [m.key, m])),
    [merchants],
  )
  const profileMap = useMemo(() => new Map(profiles.map((p) => [p.key, p])), [profiles])

  const invalidateContextQueries = useCallback(() => {
    void queryClient.invalidateQueries({
      predicate: (query) => {
        const key = query.queryKey[0] as string
        return key !== 'organizations'
      },
    })
  }, [queryClient])

  // Auto-select first org if none selected
  const firstOrgKey = organizations[0]?.key
  useEffect(() => {
    if (!currentOrgKey && firstOrgKey) {
      setOrg(firstOrgKey)
    }
  }, [currentOrgKey, firstOrgKey, setOrg])

  // Auto-select first merchant if none selected
  const firstMerchantKey = merchants[0]?.key
  useEffect(() => {
    if (currentOrgKey && !currentMerchantKey && firstMerchantKey) {
      setMerchant(firstMerchantKey)
    }
  }, [currentOrgKey, currentMerchantKey, firstMerchantKey, setMerchant])

  const handleOrgChange = useCallback(
    (value: string) => {
      setOrg(value)
      invalidateContextQueries()
    },
    [setOrg, invalidateContextQueries],
  )

  const handleMerchantChange = useCallback(
    (value: string) => {
      setMerchant(value)
      invalidateContextQueries()
    },
    [setMerchant, invalidateContextQueries],
  )

  const handleProfileChange = useCallback(
    (value: string) => {
      setProfile(value === ALL_PROFILES_VALUE ? null : value)
      invalidateContextQueries()
    },
    [setProfile, invalidateContextQueries],
  )

  const orgInitial = orgMap.get(currentOrgKey ?? '')?.name?.[0]?.toUpperCase() ?? 'O'
  const merchantInitial =
    merchantMap.get(currentMerchantKey ?? '')?.name?.[0]?.toUpperCase() ?? 'M'
  const profileInitial = currentProfileKey
    ? (profileMap.get(currentProfileKey)?.name?.[0]?.toUpperCase() ?? 'P')
    : '*'

  const summaryText = [
    orgMap.get(currentOrgKey ?? '')?.name,
    merchantMap.get(currentMerchantKey ?? '')?.name,
    currentProfileKey
      ? profileMap.get(currentProfileKey)?.name
      : t('contextSwitcher.allProfiles'),
  ]
    .filter(Boolean)
    .join(' → ')

  return (
    <Popover>
      <Tooltip>
        <TooltipTrigger asChild>
          <PopoverTrigger asChild>
            <button
              className="hover:bg-accent flex items-center rounded-md p-1 transition-colors"
              data-testid="context-switcher"
              aria-label={t('contextSwitcher.orgLabel')}
            >
              <AvatarGroup className="-space-x-1.5">
                <Avatar size="sm" className="border-background border-2">
                  <AvatarFallback className="bg-blue-100 text-[10px] font-semibold text-blue-700 dark:bg-blue-900/40 dark:text-blue-400">
                    {orgInitial}
                  </AvatarFallback>
                </Avatar>
                <Avatar size="sm" className="border-background border-2">
                  <AvatarFallback className="bg-amber-100 text-[10px] font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-400">
                    {merchantInitial}
                  </AvatarFallback>
                </Avatar>
                <Avatar size="sm" className="border-background z-10 border-2">
                  <AvatarFallback className="bg-emerald-100 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400">
                    {profileInitial}
                  </AvatarFallback>
                </Avatar>
              </AvatarGroup>
            </button>
          </PopoverTrigger>
        </TooltipTrigger>
        <TooltipContent side="right" sideOffset={8}>
          {summaryText || t('contextSwitcher.placeholder')}
        </TooltipContent>
      </Tooltip>

      <PopoverContent
        side="right"
        align="start"
        sideOffset={8}
        className="w-64 space-y-3 p-3"
      >
        <div>
          <label className="text-muted-foreground mb-1 flex items-center gap-1.5 text-[10px] font-medium tracking-wider uppercase">
            <Building2 className="size-3" />
            {t('contextSwitcher.orgLabel')}
          </label>
          <Select
            value={currentOrgKey ?? undefined}
            onValueChange={handleOrgChange}
            disabled={orgsLoading || organizations.length === 0}
          >
            <SelectTrigger
              className="h-8 text-xs"
              data-testid="org-select"
              aria-label={t('contextSwitcher.orgLabel')}
            >
              <SelectValue placeholder={t('contextSwitcher.placeholder')} />
            </SelectTrigger>
            <SelectContent>
              {organizations.map((org) => (
                <SelectItem key={org.key} value={org.key} className="text-xs">
                  {org.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div>
          <label className="text-muted-foreground mb-1 flex items-center gap-1.5 text-[10px] font-medium tracking-wider uppercase">
            <Store className="size-3" />
            {t('contextSwitcher.merchantLabel')}
          </label>
          <Select
            value={currentMerchantKey ?? undefined}
            onValueChange={handleMerchantChange}
            disabled={!currentOrgKey || merchantsLoading || merchants.length === 0}
          >
            <SelectTrigger
              className="h-8 text-xs"
              data-testid="merchant-select"
              aria-label={t('contextSwitcher.merchantLabel')}
            >
              <SelectValue placeholder={t('contextSwitcher.placeholder')} />
            </SelectTrigger>
            <SelectContent>
              {merchants.map((merchant) => (
                <SelectItem key={merchant.key} value={merchant.key} className="text-xs">
                  {merchant.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div>
          <label className="text-muted-foreground mb-1 flex items-center gap-1.5 text-[10px] font-medium tracking-wider uppercase">
            <Briefcase className="size-3" />
            {t('contextSwitcher.profileLabel')}
          </label>
          <Select
            value={currentProfileKey ?? ALL_PROFILES_VALUE}
            onValueChange={handleProfileChange}
            disabled={!currentMerchantKey || profilesLoading}
          >
            <SelectTrigger
              className="h-8 text-xs"
              data-testid="profile-select"
              aria-label={t('contextSwitcher.profileLabel')}
            >
              <SelectValue placeholder={t('contextSwitcher.allProfiles')} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL_PROFILES_VALUE} className="text-xs">
                {t('contextSwitcher.allProfiles')}
              </SelectItem>
              {profiles.map((profile) => (
                <SelectItem key={profile.key} value={profile.key} className="text-xs">
                  {profile.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </PopoverContent>
    </Popover>
  )
}
