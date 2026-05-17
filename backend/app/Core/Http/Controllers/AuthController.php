<?php

namespace App\Core\Http\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Http\Requests\ChangePasswordRequest;
use App\Core\Http\Requests\LoginRequest;
use App\Core\Http\Requests\RegisterRequest;
use App\Core\Http\Resources\UserResource;
use App\Core\Models\EmailSetting;
use App\Core\Models\User;
use App\Mail\DynamicEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    /**
     * Register a new user
     */
    public function register(RegisterRequest $request)
    {
        $validated = $request->validated();

        // Look up first rank and location dynamically — never hardcode IDs.
        // These models live in plugins (Progression, Travel) but are accessible via Core shims.
        // Gracefully handle core-only installs where these tables may not exist.
        $firstRank     = null;
        $firstLocation = null;

        try {
            if (Schema::hasTable('ranks')) {
                $firstRank = \App\Core\Models\Rank::orderBy('required_exp')->first();
            }
        } catch (\Exception $e) {
            // Rank table not available - use defaults
        }

        try {
            if (Schema::hasTable('locations')) {
                $firstLocation = \App\Core\Models\Location::orderBy('id')->first();
            }
        } catch (\Exception $e) {
            // Location table not available - use defaults
        }

        // Wrap user creation, profile seeding, and role assignment in a transaction.
        // A partial failure (e.g. role table missing) must not leave a user without a profile.
        $user = DB::transaction(function () use ($validated, $firstRank, $firstLocation) {
            // Create identity-only — no game stats on the users table.
            $user = User::create([
                'name'     => $validated['username'],
                'username' => $validated['username'],
                'email'    => $validated['email'],
                'password' => $validated['password'],
            ]);

            // User::booted() auto-creates a profile with column defaults.
            // Seed the game-specific starting values on top of those defaults.
            $profileValues = [
                'level'       => 1,
                'experience'  => 0,
                'energy'      => 100,
                'max_energy'  => 100,
                'health'      => $firstRank?->max_health ?? 100,
                'max_health'  => $firstRank?->max_health ?? 100,
                'cash'        => 1000,
                'bank'        => 0,
                'bullets'     => 50,
                'respect'     => 0,
            ];

            if (Schema::hasColumn('player_profiles', 'rank_id')) {
                $profileValues['rank_id'] = $firstRank?->id;
            }

            if (Schema::hasColumn('player_profiles', 'rank')) {
                $profileValues['rank'] = $firstRank?->name ?? 'Thug';
            }

            if (Schema::hasColumn('player_profiles', 'location_id')) {
                $profileValues['location_id'] = $firstLocation?->id;
            }

            if (Schema::hasColumn('player_profiles', 'location')) {
                $profileValues['location'] = $firstLocation?->name ?? 'Detroit';
            }

            $user->profile()->update($profileValues);

            // Assign default player role
            if (\Spatie\Permission\Models\Role::where('name', 'user')->exists()) {
                $user->assignRole('user');
            }

            return $user;
        });

        // Send welcome email using dynamic template
        try {
            $emailSettings = EmailSetting::getActive();
            if ($emailSettings) {
                $emailSettings->applyToConfig();

                Mail::to($user)->send(new DynamicEmail('welcome', [
                    'app_name' => config('app.name'),
                    'username' => $user->username,
                    'email' => $user->email,
                    'login_url' => config('app.frontend_url', config('app.url')) . '/login',
                ]));
            }
        } catch (\Exception $e) {
            // Log but don't fail registration if email fails
            Log::warning('Failed to send welcome email: ' . $e->getMessage());
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user->load(['profile', 'roles'])),
            'token' => $token,
        ], 201);
    }

    /**
     * Login user
     */
    public function login(LoginRequest $request)
    {
        $validated = $request->validated();

        $user = User::where('email', $validated['login'])
            ->orWhere('username', $validated['login'])
            ->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Update last login timestamp and IP
        $user->update([
            'last_active' => now(),
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        // Enforce 2FA: if enabled, do not issue token yet
        if (method_exists($user, 'hasTwoFactorEnabled') && $user->hasTwoFactorEnabled()) {
            $challengeToken = bin2hex(random_bytes(32));
            // Store challenge token in cache for 5 minutes
            \Cache::put('2fa_challenge_' . $challengeToken, [
                'user_id' => $user->id,
                'attempts' => 0,
                'expires_at' => now()->addMinutes(5)
            ], now()->addMinutes(5));
            return response()->json([
                'two_factor_required' => true,
                'challenge_token' => $challengeToken,
                'user' => new UserResource($user->load(['profile', 'roles'])),
            ], 200);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user->load(['profile', 'roles'])),
            'token' => $token,
        ]);
    }

    /**
     * Get authenticated user
     */
    public function user(Request $request)
    {
        $user = $request->user()->load(['profile', 'roles', 'permissions', 'oauthProviders']);

        return response()->json(new UserResource($user)); // Return user directly for frontend compatibility
    }

    /**
     * Logout user (revoke token)
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Logout from all devices
     */
    public function logoutAll(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Logged out from all devices',
        ]);
    }

    /**
     * Change password
     */
    public function changePassword(ChangePasswordRequest $request)
    {
        $validated = $request->validated();

        $user = $request->user();

        // If not a forced change, verify current password
        if (!($validated['force_change'] ?? false) || !$user->force_password_change) {
            if (!isset($validated['current_password']) || !Hash::check($validated['current_password'], $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['The current password is incorrect.'],
                ]);
            }
        }

        // Update password and remove force flag
        $user->update([
            'password' => $validated['new_password'],
            'force_password_change' => false,
        ]);

        return response()->json([
            'message' => 'Password changed successfully',
        ]);
    }

    /**
     * Update authenticated user's username
     */
    public function updateUsername(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')->ignore($user->id)],
        ]);

        $user->update([
            'username' => $validated['username'],
        ]);

        return response()->json([
            'message' => 'Username updated successfully',
            'user' => new UserResource($user->load(['profile', 'roles'])),
        ]);
    }
}
