<?php

namespace App\Core\Services;

use App\Core\Models\Setting;
use App\Core\Models\User;
use App\Core\Models\OAuthProvider;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class OAuthService
{
    /**
     * Supported OAuth providers
     */
    protected array $supportedProviders = [
        'discord',
        'google',
        'github',
        'twitter',
        'facebook',
    ];

    /**
     * Apply DB-stored OAuth credentials into runtime config so Socialite can use them.
     */
    protected function applyProviderConfig(string $provider): void
    {
        $flat = Setting::whereIn('key', [
            "oauth_{$provider}_client_id",
            "oauth_{$provider}_client_secret",
            "oauth_{$provider}_enabled",
        ])->pluck('value', 'key');

        $clientId     = $flat["oauth_{$provider}_client_id"]     ?? '';
        $clientSecret = $flat["oauth_{$provider}_client_secret"] ?? '';

        if (!empty($clientId)) {
            config([
                "services.{$provider}.client_id"     => $clientId,
                "services.{$provider}.client_secret" => $clientSecret,
                "services.{$provider}.redirect"      => config("services.{$provider}.redirect")
                    ?: url("/api/v1/oauth/{$provider}/callback"),
            ]);
        }
    }

    /**
     * Get redirect URL for OAuth provider
     */
    public function getRedirectUrl(string $provider): string
    {
        $this->validateProvider($provider);
        $this->applyProviderConfig($provider);

        /** @var \Laravel\Socialite\Two\AbstractProvider $driver */
        $driver = Socialite::driver($provider);

        return $driver
            ->stateless()
            ->redirect()
            ->getTargetUrl();
    }

    /**
     * Handle OAuth callback and return or create user
     */
    public function handleCallback(string $provider): array
    {
        $this->validateProvider($provider);
        $this->applyProviderConfig($provider);

        /** @var \Laravel\Socialite\Two\AbstractProvider $driver */
        $driver = Socialite::driver($provider);
        $socialiteUser = $driver->stateless()->user();

        // Check if OAuth account is already linked
        $oauthProvider = OAuthProvider::where('provider', $provider)
            ->where('provider_id', $socialiteUser->getId())
            ->first();

        if ($oauthProvider) {
            // Update OAuth token info
            $oauthProvider->update([
                'token' => $socialiteUser->token,
                'refresh_token' => $socialiteUser->refreshToken ?? null,
                'expires_at' => isset($socialiteUser->expiresIn)
                    ? now()->addSeconds($socialiteUser->expiresIn)
                    : null,
            ]);

            return [
                'user' => $oauthProvider->user,
                'is_new' => false,
            ];
        }

        // Check if user exists with same email
        $user = User::where('email', $socialiteUser->getEmail())->first();

        if ($user) {
            // Link OAuth provider to existing user
            $this->linkProvider($user, $provider, $socialiteUser);

            return [
                'user' => $user,
                'is_new' => false,
            ];
        }

        // For Discord we want the frontend to allow the user to choose a username
        // rather than auto-using their Discord nickname. Instead of creating the
        // user immediately, store a short-lived pending payload in cache and
        // return a pending token so the frontend can complete registration.
        if ($provider === 'discord') {
            $pending = [
                'provider' => $provider,
                'provider_id' => $socialiteUser->getId(),
                'email' => $socialiteUser->getEmail(),
                'name' => $socialiteUser->getName(),
                'nickname' => $socialiteUser->getNickname(),
                'avatar' => $socialiteUser->getAvatar(),
                'token' => $socialiteUser->token ?? null,
                'refresh_token' => $socialiteUser->refreshToken ?? null,
                'expires_in' => $socialiteUser->expiresIn ?? null,
            ];

            $pendingToken = 'oauth_pending_' . Str::random(40);
            \Illuminate\Support\Facades\Cache::put($pendingToken, $pending, now()->addMinutes(10));

            return [
                'user' => null,
                'is_new' => true,
                'pending_token' => $pendingToken,
                'socialite' => $pending,
            ];
        }

        // Create new user for other providers
        $user = $this->createUserFromSocialite($provider, $socialiteUser);

        return [
            'user' => $user,
            'is_new' => true,
        ];
    }

    /**
     * Complete a pending OAuth registration using a chosen username.
     * The $pending array is the data previously stored in cache by handleCallback.
     */
    public function createUserFromPending(array $pending, string $username): User
    {
        $firstRank = null;
        $firstLocation = null;
        try {
            if (\Schema::hasTable('ranks')) {
                $firstRank = \App\Core\Models\Rank::orderBy('required_exp')->first();
            }
        } catch (\Exception $e) {
            // ranks table not available
        }
        try {
            if (\Schema::hasTable('locations')) {
                $firstLocation = \App\Core\Models\Location::orderBy('id')->first();
            }
        } catch (\Exception $e) {
            // locations table not available
        }

        $user = \Illuminate\Support\Facades\DB::transaction(function () use ($pending, $username, $firstRank, $firstLocation) {
            $user = User::create([
                'name' => $pending['name'] ?? $username,
                'username' => $username,
                'email' => $pending['email'] ?? null,
                'password' => Hash::make(Str::random(40)),
                'email_verified_at' => now(),
            ]);

            $user->profile()->update([
                'rank_id'     => $firstRank?->id,
                'rank'        => $firstRank?->name ?? 'Thug',
                'location_id' => $firstLocation?->id,
                'location'    => $firstLocation?->name ?? 'Detroit',
                'level'       => 1,
                'experience'  => 0,
                'energy'      => 100,
                'max_energy'  => 100,
                'health'      => $firstRank?->max_health ?? 100,
                'max_health'  => $firstRank?->max_health ?? 100,
                'cash'        => 1000,
                'bank'        => 0,
                'bullets'     => 50,
            ]);

            if (\Spatie\Permission\Models\Role::where('name', 'user')->exists()) {
                $user->assignRole('user');
            }

            // Link OAuth provider
            $user->oauthProviders()->create([
                'provider' => $pending['provider'],
                'provider_id' => $pending['provider_id'],
                'token' => $pending['token'] ?? null,
                'refresh_token' => $pending['refresh_token'] ?? null,
                'expires_at' => isset($pending['expires_in']) ? now()->addSeconds($pending['expires_in']) : null,
                'avatar' => $pending['avatar'] ?? null,
                'nickname' => $pending['nickname'] ?? null,
            ]);

            return $user;
        });

        return $user;
    }

    /**
     * Link OAuth provider to existing user
     */
    public function linkProvider(User $user, string $provider, SocialiteUser $socialiteUser): OAuthProvider
    {
        $this->validateProvider($provider);

        // Check if already linked
        $existing = $user->oauthProviders()
            ->where('provider', $provider)
            ->first();

        if ($existing) {
            $existing->update([
                'provider_id' => $socialiteUser->getId(),
                'token' => $socialiteUser->token,
                'refresh_token' => $socialiteUser->refreshToken ?? null,
                'expires_at' => isset($socialiteUser->expiresIn)
                    ? now()->addSeconds($socialiteUser->expiresIn)
                    : null,
                'avatar' => $socialiteUser->getAvatar(),
                'nickname' => $socialiteUser->getNickname(),
            ]);

            return $existing;
        }

        return $user->oauthProviders()->create([
            'provider' => $provider,
            'provider_id' => $socialiteUser->getId(),
            'token' => $socialiteUser->token,
            'refresh_token' => $socialiteUser->refreshToken ?? null,
            'expires_at' => isset($socialiteUser->expiresIn)
                ? now()->addSeconds($socialiteUser->expiresIn)
                : null,
            'avatar' => $socialiteUser->getAvatar(),
            'nickname' => $socialiteUser->getNickname(),
        ]);
    }

    /**
     * Unlink OAuth provider from user
     */
    public function unlinkProvider(User $user, string $provider): bool
    {
        // Ensure user has password or another OAuth provider
        if (!$user->password && $user->oauthProviders()->count() <= 1) {
            return false;
        }

        return $user->oauthProviders()
            ->where('provider', $provider)
            ->delete() > 0;
    }

    /**
     * Get linked OAuth providers for user
     */
    public function getLinkedProviders(User $user): array
    {
        return $user->oauthProviders()
            ->get()
            ->map(fn($p) => [
                'provider' => $p->provider,
                'nickname' => $p->nickname,
                'avatar' => $p->avatar,
                'linked_at' => $p->created_at,
            ])
            ->toArray();
    }

    /**
     * Get all supported providers
     */
    public function getSupportedProviders(): array
    {
        return collect($this->supportedProviders)
            ->filter(fn($provider) => $this->isProviderConfigured($provider))
            ->values()
            ->toArray();
    }

    /**
     * Check if provider is configured (checks DB settings as well as config)
     */
    public function isProviderConfigured(string $provider): bool
    {
        if (!empty(config("services.{$provider}.client_id"))) {
            return true;
        }

        // Fall back to DB settings
        $clientId = Setting::where('key', "oauth_{$provider}_client_id")->value('value');
        $enabled  = Setting::where('key', "oauth_{$provider}_enabled")->value('value');

        return !empty($clientId) && $enabled === '1';
    }

    /**
     * Validate provider is supported
     */
    protected function validateProvider(string $provider): void
    {
        if (!in_array($provider, $this->supportedProviders)) {
            throw new \InvalidArgumentException("Unsupported OAuth provider: {$provider}");
        }

        if (!$this->isProviderConfigured($provider)) {
            throw new \InvalidArgumentException("OAuth provider not configured: {$provider}");
        }
    }

    /**
     * Create new user from Socialite user
     */
    protected function createUserFromSocialite(string $provider, SocialiteUser $socialiteUser): User
    {
        // Generate unique username
        $baseUsername = $socialiteUser->getNickname()
            ?? Str::slug(explode('@', $socialiteUser->getEmail())[0]);
        $username = $this->generateUniqueUsername($baseUsername);

        $firstRank = null;
        $firstLocation = null;
        try {
            if (\Schema::hasTable('ranks')) {
                $firstRank = \App\Core\Models\Rank::orderBy('required_exp')->first();
            }
        } catch (\Exception $e) {
            // ranks table not available
        }
        try {
            if (\Schema::hasTable('locations')) {
                $firstLocation = \App\Core\Models\Location::orderBy('id')->first();
            }
        } catch (\Exception $e) {
            // locations table not available
        }

        $user = \Illuminate\Support\Facades\DB::transaction(function () use ($socialiteUser, $username, $provider, $firstRank, $firstLocation) {
            // Identity-only — game stats live on the profile
            $user = User::create([
                'name'              => $socialiteUser->getName() ?? $username,
                'username'          => $username,
                'email'             => $socialiteUser->getEmail(),
                'password'          => Hash::make(Str::random(40)), // Unusable random password — login only via OAuth
                'email_verified_at' => now(), // Trust OAuth email verification
            ]);

            // User::booted() auto-creates the profile; seed starting game values
            $user->profile()->update([
                'rank_id'     => $firstRank?->id,
                'rank'        => $firstRank?->name ?? 'Thug',
                'location_id' => $firstLocation?->id,
                'location'    => $firstLocation?->name ?? 'Detroit',
                'level'       => 1,
                'experience'  => 0,
                'energy'      => 100,
                'max_energy'  => 100,
                'health'      => $firstRank?->max_health ?? 100,
                'max_health'  => $firstRank?->max_health ?? 100,
                'cash'        => 1000,
                'bank'        => 0,
                'bullets'     => 50,
            ]);

            // Assign default role
            if (\Spatie\Permission\Models\Role::where('name', 'user')->exists()) {
                $user->assignRole('user');
            }

            // Link OAuth provider
            $user->oauthProviders()->create([
                'provider'      => $provider,
                'provider_id'   => $socialiteUser->getId(),
                'token'         => $socialiteUser->token,
                'refresh_token' => $socialiteUser->refreshToken ?? null,
                'expires_at'    => isset($socialiteUser->expiresIn)
                    ? now()->addSeconds($socialiteUser->expiresIn)
                    : null,
                'avatar'        => $socialiteUser->getAvatar(),
                'nickname'      => $socialiteUser->getNickname(),
            ]);

            return $user;
        });

        return $user;
    }

    /**
     * Generate unique username
     */
    protected function generateUniqueUsername(string $base): string
    {
        $username = Str::slug($base, '_');
        $original = $username;
        $counter = 1;

        while (User::where('username', $username)->exists()) {
            $username = $original . '_' . $counter;
            $counter++;
        }

        return $username;
    }
}
