<?php

namespace App\Core\Policies;

use App\Core\Models\User;

/**
 * Authorization rules for admin user-management actions.
 *
 * Route middleware already enforces `role:admin|moderator`, so these
 * policies provide a finer-grained second layer:
 *   - Moderators cannot modify/delete/ban other admin-level users.
 *   - Only admins can create users or change game stats.
 *   - Nobody can edit or delete themselves via the admin panel.
 */
class UserPolicy
{
    /** Admins and moderators can list users. */
    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyRole(['admin', 'moderator']);
    }

    /** Admins and moderators can view any user detail. */
    public function view(User $actor, User $target): bool
    {
        return $actor->hasAnyRole(['admin', 'moderator']);
    }

    /** Only admins can create users from the admin panel. */
    public function create(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    /**
     * Admins can update any non-self user.
     * Moderators cannot update admin-role users.
     */
    public function update(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return false; // Use profile settings instead
        }
        if ($target->hasRole('admin') && ! $actor->hasRole('admin')) {
            return false; // Moderators cannot touch admin accounts
        }
        return $actor->hasAnyRole(['admin', 'moderator']);
    }

    /**
     * Only admins can delete users.
     * Nobody can delete themselves or another admin.
     */
    public function delete(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return false;
        }
        if ($target->hasRole('admin')) {
            return false; // Nobody can delete an admin
        }
        return $actor->hasRole('admin');
    }

    /**
     * Admins and moderators can ban non-admin players.
     * Nobody can ban themselves or another admin.
     */
    public function ban(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return false;
        }
        if ($target->hasRole('admin')) {
            return false;
        }
        return $actor->hasAnyRole(['admin', 'moderator']);
    }

    /** Only admins can directly edit a player's game stats. */
    public function manageGameStats(User $actor): bool
    {
        return $actor->hasRole('admin');
    }
}
