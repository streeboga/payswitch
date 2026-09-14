<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole as UserRoleEnum;
use App\Models\UserRole;
use App\Repositories\Contracts\UserRoleRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

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

    public function findRole(string $roleId): UserRole
    {
        return $this->roles->findOrFail($roleId);
    }

    /**
     * Assign or update a role for a user in an organization.
     *
     * @param  array{user_id: int|string, organization_id: int|string, role: string}  $attributes
     */
    public function assignRole(array $attributes): UserRole
    {
        $existing = $this->roles->findByUserAndOrganization($attributes['user_id'], $attributes['organization_id']);
        if ($existing !== null && $attributes['role'] !== UserRoleEnum::Admin->value) {
            $this->assertNotLastAdmin($existing);
        }

        return $this->roles->updateOrCreate($attributes);
    }

    /**
     * Update an existing role.
     */
    public function updateRole(UserRole $userRole, string $role): UserRole
    {
        if ($role !== UserRoleEnum::Admin->value) {
            $this->assertNotLastAdmin($userRole);
        }

        $this->roles->update($userRole, $role);

        return $userRole;
    }

    /**
     * Delete a role.
     */
    public function deleteRole(UserRole $userRole): void
    {
        $this->assertNotLastAdmin($userRole);
        $this->roles->delete($userRole);
    }

    /**
     * Организация без admin остаётся без управления ролями навсегда.
     *
     * ponytail: проверка без блокировки — два одновременных понижения двух
     * последних admin пройдут оба; нужен lockForUpdate, если это случится.
     */
    private function assertNotLastAdmin(UserRole $userRole): void
    {
        if ($userRole->role !== UserRoleEnum::Admin) {
            return;
        }

        if ($this->roles->countAdmins($userRole->organization_id) <= 1) {
            throw ValidationException::withMessages([
                'role' => 'Нельзя понизить или удалить последнего администратора организации.',
            ]);
        }
    }
}
