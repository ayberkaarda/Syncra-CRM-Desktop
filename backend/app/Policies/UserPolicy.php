<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the user can view any users.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    /**
     * Determine whether the user can view the given user.
     */
    public function view(User $user, User $target): bool
    {
        return $user->can('users.view');
    }

    /**
     * Determine whether the user can create users.
     */
    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    /**
     * Determine whether the user can update the given user.
     */
    public function update(User $user, User $target): bool
    {
        if (! $user->can('users.update')) {
            return false;
        }

        return $this->allowedToActOnRoles($user, $target);
    }

    /**
     * Determine whether the user can delete the given user.
     */
    public function delete(User $user, User $target): bool
    {
        if (! $user->can('users.delete')) {
            return false;
        }

        // A user can never delete their own account.
        if ($user->id === $target->id) {
            return false;
        }

        if (! $this->allowedToActOnRoles($user, $target)) {
            return false;
        }

        // Never allow deleting the last active Super Admin.
        if ($this->isLastActiveSuperAdmin($target)) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can toggle the given user's active state.
     */
    public function toggleActive(User $user, User $target): bool
    {
        if (! $user->can('users.toggle_active')) {
            return false;
        }

        // A user can never deactivate their own account.
        if ($user->id === $target->id) {
            return false;
        }

        if (! $this->allowedToActOnRoles($user, $target)) {
            return false;
        }

        // Never allow deactivating the last active Super Admin.
        if ($target->is_active && $this->isLastActiveSuperAdmin($target)) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can reset the given user's password.
     */
    public function resetPassword(User $user, User $target): bool
    {
        if (! $user->can('users.reset_password')) {
            return false;
        }

        return $this->allowedToActOnRoles($user, $target);
    }

    /**
     * Determine whether the user can manage the given user's roles.
     */
    public function manageRoles(User $user, User $target): bool
    {
        if (! $user->can('users.manage_roles')) {
            return false;
        }

        return $this->allowedToActOnRoles($user, $target);
    }

    /**
     * A Super Admin target may only be acted upon (updated/deleted/deactivated/
     * reassigned) by someone who holds the `users.manage_roles` permission.
     */
    protected function allowedToActOnRoles(User $user, User $target): bool
    {
        if ($target->hasRole('Super Admin') && ! $user->can('users.manage_roles')) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the given target is the last remaining active
     * Super Admin in the system.
     */
    protected function isLastActiveSuperAdmin(User $target): bool
    {
        if (! $target->hasRole('Super Admin')) {
            return false;
        }

        $activeSuperAdminCount = User::role('Super Admin')
            ->where('is_active', true)
            ->count();

        return $activeSuperAdminCount <= 1;
    }
}
