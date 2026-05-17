<?php

namespace App\Core\Http\Controllers\Admin;

use App\Core\Http\Controllers\Controller;
use App\Core\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UserManagementController extends Controller
{
    /** Game-stat fields that live on player_profiles, not users. */
    private const PROFILE_FIELDS = [
        'cash', 'bank', 'bullets', 'experience', 'level', 'respect',
        'points', 'strength', 'defense', 'speed',
        'health', 'max_health', 'energy', 'max_energy', 'nerve', 'max_nerve',
        'rank_id', 'rank', 'location_id', 'location', 'status', 'jail_until',
    ];

    /**
     * List all users with filters and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $query = User::with(['profile', 'roles']);

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('username', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%")
                  ->orWhere('name', 'like', "%{$request->search}%");
            });
        }

        if ($request->rank_id) {
            $query->whereHas('profile', fn($q) => $q->where('rank_id', $request->rank_id));
        }

        if ($request->role) {
            $query->whereHas('roles', fn($q) => $q->where('name', $request->role));
        }

        if ($request->status === 'banned') {
            $query->whereNotNull('banned_until');
        } elseif ($request->status === 'active') {
            $query->whereNull('banned_until');
        }

        $sortBy  = $request->get('sort_by', 'created_at');
        $sortDir = $request->get('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        return response()->json($query->paginate($request->get('per_page', 25)));
    }

    /**
     * Get single user details.
     */
    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return response()->json(
            $user->load(['profile', 'roles.permissions', 'permissions'])
        );
    }

    /**
     * Create new user.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $validated = $request->validate([
            'username'    => 'required|string|max:255|unique:users',
            'email'       => 'required|string|email|max:255|unique:users',
            'name'        => 'required|string|max:255',
            'password'    => 'required|string|min:8',
            'rank_id'     => 'nullable|exists:ranks,id',
            'location_id' => 'nullable|exists:locations,id',
        ]);

        $user = User::create([
            'username' => $validated['username'],
            'email'    => $validated['email'],
            'name'     => $validated['name'],
            'password' => Hash::make($validated['password']),
        ]);

        // Game stats live on the profile (created automatically by User::booted())
        $profileOverrides = array_filter([
            'rank_id'     => $validated['rank_id'] ?? null,
            'location_id' => $validated['location_id'] ?? null,
        ]);

        if ($profileOverrides) {
            $user->profile->update($profileOverrides);
        }

        Log::info('Admin created user', [
            'actor'    => $request->user()->username,
            'new_user' => $user->username,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'user'    => $user->load(['profile']),
        ], 201);
    }

    /**
     * Update user identity and/or game stats.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $identityRules = [
            'username'        => 'sometimes|string|max:255|unique:users,username,' . $user->id,
            'email'           => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            'name'            => 'sometimes|string|max:255',
            'password'        => 'sometimes|string|min:8',
            'bio'             => 'sometimes|string|nullable',
            'profile_picture' => 'sometimes|string|nullable',
        ];

        $statsRules = [
            'cash'        => 'sometimes|numeric|min:0',
            'bank'        => 'sometimes|numeric|min:0',
            'bullets'     => 'sometimes|integer|min:0',
            'experience'  => 'sometimes|integer|min:0',
            'level'       => 'sometimes|integer|min:1',
            'respect'     => 'sometimes|integer|min:0',
            'strength'    => 'sometimes|integer|min:0',
            'defense'     => 'sometimes|integer|min:0',
            'speed'       => 'sometimes|integer|min:0',
            'health'      => 'sometimes|integer|min:0',
            'max_health'  => 'sometimes|integer|min:0',
            'energy'      => 'sometimes|integer|min:0',
            'max_energy'  => 'sometimes|integer|min:0',
            'nerve'       => 'sometimes|integer|min:0',
            'max_nerve'   => 'sometimes|integer|min:0',
            'rank_id'     => 'sometimes|exists:ranks,id',
            'location_id' => 'sometimes|exists:locations,id',
            'status'      => 'sometimes|in:alive,dead,hospitalized',
            'jail_until'  => 'sometimes|date|nullable',
        ];

        // Only admins may change game stats
        $hasStatChanges = count(array_intersect(array_keys($request->all()), self::PROFILE_FIELDS)) > 0;
        if ($hasStatChanges) {
            $this->authorize('manageGameStats', User::class);
        }

        $validated = $request->validate(array_merge($identityRules, $statsRules));

        // Split: identity updates go to users table, game stats go to player_profiles
        $identityData = array_diff_key($validated, array_flip(self::PROFILE_FIELDS));
        $profileData  = array_intersect_key($validated, array_flip(self::PROFILE_FIELDS));

        if (isset($identityData['password'])) {
            $identityData['password'] = Hash::make($identityData['password']);
        }

        if ($identityData) {
            $user->update($identityData);
        }

        if ($profileData) {
            $user->profile->update($profileData);
        }

        Log::info('Admin updated user', [
            'actor'          => $request->user()->username,
            'target'         => $user->username,
            'identity_fields'=> array_keys($identityData),
            'profile_fields' => array_keys($profileData),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'user'    => $user->fresh()->load(['profile']),
        ]);
    }

    /**
     * Delete user.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        Log::warning('Admin deleted user', [
            'actor'   => $request->user()->username,
            'deleted' => $user->username,
            'email'   => $user->email,
        ]);

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
        ]);
    }

    /**
     * Ban user.
     */
    public function ban(Request $request, User $user): JsonResponse
    {
        $this->authorize('ban', $user);

        $validated = $request->validate([
            'reason'   => 'required|string',
            'duration' => 'required|string|in:1day,3days,7days,30days,permanent',
            'notes'    => 'nullable|string',
        ]);

        $bannedUntil = match ($validated['duration']) {
            '1day'     => now()->addDay(),
            '3days'    => now()->addDays(3),
            '7days'    => now()->addDays(7),
            '30days'   => now()->addDays(30),
            'permanent'=> now()->addYears(100),
        };

        $user->update([
            'banned_until' => $bannedUntil,
            'ban_reason'   => $validated['reason'],
        ]);

        Log::info('Admin banned user', [
            'actor'    => $request->user()->username,
            'target'   => $user->username,
            'duration' => $validated['duration'],
            'reason'   => $validated['reason'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User banned successfully',
            'user'    => $user->fresh(),
        ]);
    }

    /**
     * Unban user.
     */
    public function unban(Request $request, User $user): JsonResponse
    {
        $this->authorize('ban', $user);  // same gate — can ban → can unban

        $user->update([
            'banned_until' => null,
            'ban_reason'   => null,
        ]);

        Log::info('Admin unbanned user', [
            'actor'  => $request->user()->username,
            'target' => $user->username,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User unbanned successfully',
            'user'    => $user->fresh(),
        ]);
    }

    /**
     * Get user statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $stats = [
            'total_users'  => User::count(),
            'active_today' => User::where('last_active', '>=', now()->subDay())->count(),
            'active_week'  => User::where('last_active', '>=', now()->subWeek())->count(),
            'banned'       => User::whereNotNull('banned_until')->count(),
            'new_today'    => User::whereDate('created_at', today())->count(),
            'new_week'     => User::where('created_at', '>=', now()->subWeek())->count(),
        ];

        return response()->json($stats);
    }
}
