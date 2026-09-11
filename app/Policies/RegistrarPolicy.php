<?php

namespace App\Policies;

use App\Models\Registrar;
use App\Models\User;

class RegistrarPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('admin.registrars.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Registrar $registrar): bool
    {
        return $user->hasPermission('admin.registrars.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('admin.registrars.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Registrar $registrar): bool
    {
        return $user->hasPermission('admin.registrars.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Registrar $registrar): bool
    {
        return $user->hasPermission('admin.registrars.delete');
    }

    /**
     * Determine whether the user can delete any models.
     */
    public function deleteAny(User $user): bool
    {
        return $user->hasPermission('admin.registrars.deleteAny');
    }
}
