<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\UserRole;
use Illuminate\Database\Eloquent\Collection;

interface UserRoleRepositoryInterface
{
    /**
     * @return Collection<int, UserRole>
     */
    public function getRolesForMerchant(int|string $merchantId): Collection;

    /**
     * @param  array{user_id: int|string, organization_id: int|string, role: string}  $attributes
     */
    public function updateOrCreate(array $attributes): UserRole;

    public function findOrFail(string $roleId): UserRole;

    public function update(UserRole $role, string $newRole): void;

    public function delete(UserRole $role): void;
}
