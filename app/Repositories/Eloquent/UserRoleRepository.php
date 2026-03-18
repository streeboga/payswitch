<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\UserRole;
use App\Repositories\Contracts\UserRoleRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

final readonly class UserRoleRepository implements UserRoleRepositoryInterface
{
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

    public function update(UserRole $role, string $newRole): void
    {
        $role->update(['role' => $newRole]);
    }

    public function delete(UserRole $role): void
    {
        $role->delete();
    }
}
