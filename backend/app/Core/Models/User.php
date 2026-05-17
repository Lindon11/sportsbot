<?php

namespace App\Core\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Traits\Macroable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasRoles;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use Notifiable;

    /**
     * Allow plugins to register macros (methods + attributes) on this model
     * via User::macro('methodName', fn() => ...) from their hooks.php.
     *
     * We alias Macroable's __call/__callStatic to avoid conflicts with
     * Eloquent's own dynamic method handling, and fall through to the
     * parent Eloquent implementation when no macro is found.
     */
    use Macroable {
        __call as macroCall;
        __callStatic as macroCallStatic;
    }

    public function __call($method, $parameters)
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }
        return parent::__call($method, $parameters);
    }

    public static function __callStatic($method, $parameters)
    {
        if (static::hasMacro($method)) {
            return static::macroCallStatic($method, $parameters);
        }
        return parent::__callStatic($method, $parameters);
    }

    /**
     * The guard name for Spatie permissions.
     */
    protected $guard_name = 'sanctum';

    /**
     * Only identity, auth and profile fields are mass-assignable.
     *
     * Economic stats (cash, bank, respect, bullets, points), combat stats
     * (strength, defense, speed), resource stats (health, energy, nerve),
     * progression (level, experience, rank), and game state (status,
     * jail_until, location) must be mutated through their respective
     * service classes (WalletService, CombatService, etc.) to ensure
     * business rules are enforced. All game data lives in player_profiles.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        // Identity & auth
        'name',
        'username',
        'email',
        'password',
        'email_verified_at',
        'bio',
        'profile_picture',
        'force_password_change',
        'last_active',
        'last_login_at',
        'last_login_ip',

    ];


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [];

    /**
     * Get the attributes that should be cast.
     * Game-stat casts live on PlayerProfile — only auth/identity casts here.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'last_active'       => 'datetime',
        ];
    }

    // -----------------------------------------------------------------------
    // Lifecycle
    // -----------------------------------------------------------------------

    protected static function booted(): void
    {
        static::created(function (User $user) {
            $user->profile()->create([]);  // all columns use defaults from migration
        });
    }

    /**
     * Flush any dirty profile attributes atomically with the User save.
     */
    public function save(array $options = []): bool
    {
        $result = parent::save($options);

        if ($this->relationLoaded('profile') && $this->profile && $this->profile->isDirty()) {
            $this->profile->save();
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------------

    public function profile(): HasOne
    {
        return $this->hasOne(PlayerProfile::class);
    }

    public function oauthProviders()
    {
        return $this->hasMany(OAuthProvider::class);
    }

    /**
     * Check if user has 2FA enabled
     */
    public function hasTwoFactorEnabled(): bool
    {
        try {
            return !is_null($this->two_factor_secret) && !is_null($this->two_factor_confirmed_at);
        } catch (\Illuminate\Database\Eloquent\MissingAttributeException $e) {
            return false;
        }
    }

    // -----------------------------------------------------------------------
    // Game stat shims
    //
    // Reads come from the loaded PlayerProfile (or fall back to the users
    // column during the Phase 1→6 transition).
    //
    // Writes go to the profile object directly. The save() override above
    // flushes any dirty profile attributes when the User is saved, so the
    // existing pattern "$user->health = X; $user->save()" continues to work
    // and now correctly persists to player_profiles.
    //
    // If the profile is not yet loaded (e.g. inside a lockForUpdate scope),
    // call $user->profile()->update(['stat' => $value]) directly instead of
    // going through the shim.
    // -----------------------------------------------------------------------

    protected function profileValue(string $key): mixed
    {
        if ($this->relationLoaded('profile') && $this->profile) {
            return $this->profile->{$key} ?? null;
        }
        return $this->getRawOriginal($key);
    }

    protected function setProfileValue(string $key, mixed $value): void
    {
        if ($this->relationLoaded('profile') && $this->profile) {
            $this->profile->{$key} = $value;
        } else {
            // Profile not loaded — update the DB row directly so the write is
            // never silently lost. This is a safety net; prefer loading the
            // profile before performing stat mutations.
            $this->profile()->update([$key => $value]);
        }
    }

    // --- Getters ---

    public function getExperienceAttribute(): mixed { return $this->profileValue('experience'); }
    public function getLevelAttribute(): mixed       { return $this->profileValue('level'); }
    public function getRankAttribute(): mixed        { return $this->profileValue('rank'); }
    public function getRankIdAttribute(): mixed      { return $this->profileValue('rank_id'); }
    public function getCashAttribute(): mixed        { return $this->profileValue('cash'); }
    public function getBankAttribute(): mixed        { return $this->profileValue('bank'); }
    public function getBulletsAttribute(): mixed     { return $this->profileValue('bullets'); }
    public function getHealthAttribute(): mixed      { return $this->profileValue('health'); }
    public function getMaxHealthAttribute(): mixed   { return $this->profileValue('max_health'); }
    public function getEnergyAttribute(): mixed      { return $this->profileValue('energy'); }
    public function getMaxEnergyAttribute(): mixed   { return $this->profileValue('max_energy'); }
    public function getRespectAttribute(): mixed     { return $this->profileValue('respect'); }
    public function getStrengthAttribute(): mixed    { return $this->profileValue('strength'); }
    public function getDefenseAttribute(): mixed     { return $this->profileValue('defense'); }
    public function getSpeedAttribute(): mixed       { return $this->profileValue('speed'); }
    public function getStatusAttribute(): mixed      { return $this->profileValue('status'); }
    public function getJailUntilAttribute(): mixed   { return $this->profileValue('jail_until'); }
    public function getLocationAttribute(): mixed    { return $this->profileValue('location'); }
    public function getLocationIdAttribute(): mixed  { return $this->profileValue('location_id'); }

    // Progression accessors — delegate to progression.service when loaded, degrade gracefully otherwise.
    public function getNextRankAttribute(): mixed
    {
        return app()->bound('progression.service')
            ? app('progression.service')->getNextRank($this)
            : null;
    }

    public function getExpProgressAttribute(): float
    {
        return app()->bound('progression.service')
            ? (float) app('progression.service')->getExpProgress($this)
            : 0.0;
    }

    // --- Setters (route to profile so $user->stat = X; $user->save() works) ---

    public function setExperienceAttribute(mixed $v): void { $this->setProfileValue('experience', $v); }
    public function setLevelAttribute(mixed $v): void      { $this->setProfileValue('level', $v); }
    public function setRankAttribute(mixed $v): void       { $this->setProfileValue('rank', $v); }
    public function setRankIdAttribute(mixed $v): void     { $this->setProfileValue('rank_id', $v); }
    public function setCashAttribute(mixed $v): void       { $this->setProfileValue('cash', $v); }
    public function setBankAttribute(mixed $v): void       { $this->setProfileValue('bank', $v); }
    public function setBulletsAttribute(mixed $v): void    { $this->setProfileValue('bullets', $v); }
    public function setHealthAttribute(mixed $v): void     { $this->setProfileValue('health', $v); }
    public function setMaxHealthAttribute(mixed $v): void  { $this->setProfileValue('max_health', $v); }
    public function setEnergyAttribute(mixed $v): void     { $this->setProfileValue('energy', $v); }
    public function setMaxEnergyAttribute(mixed $v): void  { $this->setProfileValue('max_energy', $v); }
    public function setRespectAttribute(mixed $v): void    { $this->setProfileValue('respect', $v); }
    public function setStrengthAttribute(mixed $v): void   { $this->setProfileValue('strength', $v); }
    public function setDefenseAttribute(mixed $v): void    { $this->setProfileValue('defense', $v); }
    public function setSpeedAttribute(mixed $v): void      { $this->setProfileValue('speed', $v); }
    public function setStatusAttribute(mixed $v): void     { $this->setProfileValue('status', $v); }
    public function setJailUntilAttribute(mixed $v): void  { $this->setProfileValue('jail_until', $v); }
    public function setLocationAttribute(mixed $v): void   { $this->setProfileValue('location', $v); }
    public function setLocationIdAttribute(mixed $v): void { $this->setProfileValue('location_id', $v); }

    public function timers()
    {
        return $this->hasMany(UserTimer::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    // -----------------------------------------------------------------------
    // Timer helper methods
    // -----------------------------------------------------------------------

    public function hasTimer(string $timerName): bool
    {
        return $this->timers()
            ->where('timer_name', $timerName)
            ->where('expires_at', '>', now())
            ->exists();
    }

    public function getTimer(string $timerName): ?UserTimer
    {
        return $this->timers()
            ->where('timer_name', $timerName)
            ->where('expires_at', '>', now())
            ->first();
    }

    public function setTimer(string $timerName, int $seconds, array $metadata = []): UserTimer
    {
        return $this->timers()->updateOrCreate(
            ['timer_name' => $timerName],
            [
                'expires_at' => now()->addSeconds($seconds),
                'duration'   => $seconds,
                'metadata'   => $metadata,
            ]
        );
    }

    public function clearTimer(string $timerName): void
    {
        $this->timers()->where('timer_name', $timerName)->delete();
    }
}
