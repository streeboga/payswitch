<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UserRole;
use App\Repositories\Contracts\UserRoleRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

final readonly class UserRoleService
{
    public function __construct(
        private UserRoleRepositoryInterface $roles,
    ) {}

    /**
     * Get all user roles for a merchant's organization.
     *
     * @return Collection<int, UserRole>
     */
    public function getRolesForMerchant(int|string $merchantId): Collection
    {
        return $this->roles->getRolesForMerchant($merchantId);
    }

    /**
     * Assign or update a role for a user in an organization.
     *
     * @param  array{user_id: int|string, organization_id: int|string, role: string}  $attributes
     */
    public function assignRole(array $attributes): UserRole
    {
        return $this->roles->updateOrCreate($attributes);
    }

    /**
     * Update an existing role.
     */
    public function updateRole(string $roleId, string $role): UserRole
    {
        $userRole = $this->roles->findOrFail($roleId);
        $this->roles->update($userRole, $role);

        return $userRole;
    }

    /**
     * Delete a role by ID.
     */
    public function deleteRole(string $roleId): void
    {
        $userRole = $this->roles->findOrFail($roleId);
        $this->roles->delete($userRole);
    }
}
