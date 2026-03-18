import { useEffect, useCallback, useMemo } from 'react'
import { Building2, Store, Briefcase } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { useContextStore } from '@/stores/context'
import { usePreferencesStore } from '@/stores/preferences'
import { useOrganizations, useMerchants, useProfiles } from '@/hooks/use-context-data'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'

const ALL_PROFILES_VALUE = '__all__'

// Stable empty arrays to avoid creating new references on each render
const EMPTY_ARRAY: never[] = []

export function ContextSwitcher() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const sidebarCollapsed = usePreferencesStore((s) => s.sidebarCollapsed)
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

  const orgMap = useMemo(() => new Map(organizations.map((o) => [o.key, o])), [organizations])
  const merchantMap = useMemo(() => new Map(merchants.map((m) => [m.key, m])), [merchants])
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
  // Use primitive deps (length + first key) to avoid referential instability from EMPTY_ARRAY fallback
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

  if (sidebarCollapsed) {
    return (
      <div className="space-y-1 px-2 py-2" data-testid="context-switcher">
        <Tooltip>
          <TooltipTrigger asChild>
            <div className="flex items-center justify-center py-1">
              <Building2 className="text-muted-foreground size-4" />
            </div>
          </TooltipTrigger>
          <TooltipContent side="right" sideOffset={8}>
            {orgMap.get(currentOrgKey ?? '')?.name ?? t('contextSwitcher.orgDefault')}
          </TooltipContent>
        </Tooltip>
        <Tooltip>
          <TooltipTrigger asChild>
            <div className="flex items-center justify-center py-1">
              <Store className="text-muted-foreground size-4" />
            </div>
          </TooltipTrigger>
          <TooltipContent side="right" sideOffset={8}>
            {merchantMap.get(currentMerchantKey ?? '')?.name ?? t('contextSwitcher.merchantDefault')}
          </TooltipContent>
        </Tooltip>
        <Tooltip>
          <TooltipTrigger asChild>
            <div className="flex items-center justify-center py-1">
              <Briefcase className="text-muted-foreground size-4" />
            </div>
          </TooltipTrigger>
          <TooltipContent side="right" sideOffset={8}>
            {currentProfileKey
              ? (profileMap.get(currentProfileKey)?.name ?? t('contextSwitcher.profileDefault'))
              : t('contextSwitcher.allProfiles')}
          </TooltipContent>
        </Tooltip>
      </div>
    )
  }

  return (
    <div className="space-y-2 px-3 py-2" data-testid="context-switcher">
      <div>
        <label className="text-muted-foreground mb-1 block text-[10px] font-medium tracking-wider uppercase">
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
        <label className="text-muted-foreground mb-1 block text-[10px] font-medium tracking-wider uppercase">
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
        <label className="text-muted-foreground mb-1 block text-[10px] font-medium tracking-wider uppercase">
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
    </div>
  )
}
