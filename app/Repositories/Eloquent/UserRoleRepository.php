<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Enums\UserRole as UserRoleEnum;
use App\Models\UserRole;
use App\Repositories\Contracts\UserRoleRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

final readonly class UserRoleRepository implements UserRoleRepositoryInterface
{
    /**
     * @return Collection<int, UserRole>
     */
    public function getRolesForMerchant(int|string $merchantId): Collection
    {
        return UserRole::with('user')
            ->whereHas('organization', function ($q) use ($merchantId) {
                $q->whereHas('merchantAccounts', function ($q2) use ($merchantId) {
                    $q2->where('id', $merchantId);
                });
            })
            ->get();
    }

    /**
     * @param  array{user_id: int|string, organization_id: int|string, role: string}  $attributes
     */
    public function updateOrCreate(array $attributes): UserRole
    {
        return UserRole::updateOrCreate(
            ['user_id' => $attributes['user_id'], 'organization_id' => $attributes['organization_id']],
            ['role' => $attributes['role']],
        );
    }

    public function findOrFail(string $roleId): UserRole
    {
        return UserRole::findOrFail($roleId);
    }

    public function findByUserAndOrganization(int|string $userId, int|string $organizationId): ?UserRole
    {
        return UserRole::where('user_id', $userId)->where('organization_id', $organizationId)->first();
    }

    public function countAdmins(int|string $organizationId): int
    {
        return UserRole::where('organization_id', $organizationId)->where('role', UserRoleEnum::Admin)->count();
    }

    public function update(UserRole $role, string $newRole): void
    {
        $role->update(['role' => $newRole]);
    }

    public function delete(UserRole $role): void
    {
        $role->delete();
    }
}
