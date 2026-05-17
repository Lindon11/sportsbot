<?php

namespace App\Core\Http\Controllers\Admin;

use App\Core\Http\Controllers\Controller;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Http\Request;

class RolePermissionController extends Controller
{
    /**
     * List all roles
     */
    public function indexRoles()
    {
        $roles = Role::with('permissions')->get();
        return response()->json($roles);
    }

    /**
     * List all permissions
     */
    public function indexPermissions()
    {
        $permissions = Permission::where('guard_name', 'sanctum')
            ->get()
            ->groupBy(function ($permission) {
                // Group by first word (e.g. "manage", "view", "moderate")
                return explode(' ', $permission->name)[0] ?? 'other';
            });

        return response()->json($permissions);
    }

    /**
     * Create role
     */
    public function storeRole(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:roles',
            'permissions' => 'array',
        ]);

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => 'sanctum',
        ]);

        if (isset($validated['permissions'])) {
            // Filter to only valid sanctum guard permissions
            $validPermissions = collect($validated['permissions'])->filter(function ($perm) {
                if (is_numeric($perm)) {
                    return Permission::where('guard_name', 'sanctum')->where('id', $perm)->exists();
                }
                return Permission::where('guard_name', 'sanctum')->where('name', $perm)->exists();
            });

            $permissionNames = $validPermissions->map(function ($perm) {
                if (is_numeric($perm)) {
                    return Permission::where('guard_name', 'sanctum')->where('id', $perm)->value('name');
                }
                return $perm;
            })->filter()->values();

            $role->syncPermissions($permissionNames);
        }

        return response()->json([
            'success' => true,
            'message' => 'Role created successfully',
            'role' => $role->load('permissions')
        ], 201);
    }

    /**
     * Update role
     */
    public function updateRole(Request $request, $id)
    {
        $role = Role::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|unique:roles,name,' . $id,
            'permissions' => 'array',
        ]);

        if (isset($validated['name'])) {
            $role->update(['name' => $validated['name']]);
        }

        if (isset($validated['permissions'])) {
            // Filter to only valid sanctum guard permissions
            // Support both IDs and names for backwards compatibility
            $validPermissions = collect($validated['permissions'])->filter(function ($perm) {
                // Check if it's a numeric ID
                if (is_numeric($perm)) {
                    return Permission::where('guard_name', 'sanctum')->where('id', $perm)->exists();
                }
                // Check if it's a permission name
                return Permission::where('guard_name', 'sanctum')->where('name', $perm)->exists();
            });

            // Convert IDs to names if needed
            $permissionNames = $validPermissions->map(function ($perm) {
                if (is_numeric($perm)) {
                    return Permission::where('guard_name', 'sanctum')->where('id', $perm)->value('name');
                }
                return $perm;
            })->filter()->values();

            $role->syncPermissions($permissionNames);
        }

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully',
            'role' => $role->fresh()->load('permissions')
        ]);
    }

    /**
     * Delete role
     */
    public function destroyRole($id)
    {
        $role = Role::findOrFail($id);
        $role->delete();

        return response()->json([
            'success' => true,
            'message' => 'Role deleted successfully'
        ]);
    }

    /**
     * Assign role to user
     */
    public function assignRoleToUser(Request $request, $userId)
    {
        $validated = $request->validate([
            'role' => 'required|exists:roles,name'
        ]);

        $user = \App\Core\Models\User::findOrFail($userId);
        $user->assignRole($validated['role']);

        return response()->json([
            'success' => true,
            'message' => 'Role assigned successfully',
            'user' => $user->fresh()->load('roles')
        ]);
    }

    /**
     * Remove role from user
     */
    public function removeRoleFromUser(Request $request, $userId)
    {
        $validated = $request->validate([
            'role' => 'required|exists:roles,name'
        ]);

        $user = \App\Core\Models\User::findOrFail($userId);
        $user->removeRole($validated['role']);

        return response()->json([
            'success' => true,
            'message' => 'Role removed successfully',
            'user' => $user->fresh()->load('roles')
        ]);
    }
}
