<?php

namespace App\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Only keep raw attributes for identity fields that truly live on users.
        $attrs = $this->resource->getAttributes();

        $profileLoaded = $this->resource->relationLoaded('profile');
        $profile = $profileLoaded ? $this->resource->profile : null;

        // Use the profile's currentRank/currentLocation relations (plugin-owned)
        $rankLoaded = $profileLoaded && $profile && method_exists($profile, 'relationLoaded') && $profile->relationLoaded('currentRank');
        $locationLoaded = $profileLoaded && $profile && method_exists($profile, 'relationLoaded') && $profile->relationLoaded('currentLocation');

        return [
            // Identity (Core-owned)
            'id'       => $this->id,
            'name'     => $this->name,
            'username' => $attrs['username'] ?? $this->name ?? null,
            'email'    => $this->email,

            // Game/plugin stats (Progression-owned) — only if profile eager-loaded
            'level'        => $profileLoaded ? ($profile?->level ?? null) : null,
            'experience'   => $profileLoaded ? ($profile?->experience ?? null) : null,
            'exp_progress' => $profileLoaded ? ($this->exp_progress ?? null) : null,

            // next_rank is a User accessor (delegates to progression.service) — not a PlayerProfile accessor
            'next_rank'    => $profileLoaded
                ? (is_object($this->next_rank) ? $this->next_rank->only(['name', 'required_exp']) : null)
                : null,

            'strength'     => $profileLoaded ? ($profile?->strength ?? null) : null,
            'defense'      => $profileLoaded ? ($profile?->defense ?? null) : null,
            'speed'        => $profileLoaded ? ($profile?->speed ?? null) : null,
            'health'       => $profileLoaded ? ($profile?->health ?? null) : null,
            'max_health'   => $profileLoaded ? ($profile?->max_health ?? null) : null,
            'energy'       => $profileLoaded ? ($profile?->energy ?? null) : null,
            'max_energy'   => $profileLoaded ? ($profile?->max_energy ?? null) : null,
            'cash'         => $profileLoaded ? ($profile?->cash ?? null) : null,
            'bank'         => $profileLoaded ? ($profile?->bank ?? null) : null,
            'respect'      => $profileLoaded ? ($profile?->respect ?? null) : null,
            'bullets'      => $profileLoaded ? ($profile?->bullets ?? null) : null,

            // Rank & location (plugin-owned) — core provides raw fields only.
            'rank_id'      => $profileLoaded ? ($profile?->rank_id ?? null) : null,
            'rank'         => $profileLoaded ? ($profile?->rank ?? null) : null,

            'location_id'  => $profileLoaded ? ($profile?->location_id ?? null) : null,
            'location'     => $profileLoaded ? ($profile?->location ?? null) : null,

            // Cooldowns (likely profile-owned now) — guard behind profileLoaded
            'last_crime_at' => $profileLoaded ? ($profile?->last_crime_at?->toISOString() ?? null) : null,
            'last_gta_at'   => $profileLoaded ? ($profile?->last_gta_at?->toISOString() ?? null) : null,
            'jail_until'    => $profileLoaded ? ($profile?->jail_until?->toISOString() ?? null) : null,

            // Activity (Core-owned identity fields) — read only from raw attributes when present
            'last_active'   => array_key_exists('last_active', $attrs)
                ? ($attrs['last_active'] ? \Illuminate\Support\Carbon::parse($attrs['last_active'])->toISOString() : null)
                : null,
            'last_login_at' => array_key_exists('last_login_at', $attrs)
                ? ($attrs['last_login_at'] ? \Illuminate\Support\Carbon::parse($attrs['last_login_at'])->toISOString() : null)
                : null,

            // Account flags (Core-owned)
            'force_password_change' => array_key_exists('force_password_change', $attrs)
                ? ($attrs['force_password_change'] ?? false)
                : null,
            'email_verified_at'     => array_key_exists('email_verified_at', $attrs)
                ? ($attrs['email_verified_at'] ? \Illuminate\Support\Carbon::parse($attrs['email_verified_at'])->toISOString() : null)
                : null,
            'two_factor_enabled'    => $this->hasTwoFactorEnabled(),

            // Relationships (only when eager-loaded)
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')),
            'oauth_providers' => $this->whenLoaded('oauthProviders', fn () => $this->oauthProviders->pluck('provider')),

            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
