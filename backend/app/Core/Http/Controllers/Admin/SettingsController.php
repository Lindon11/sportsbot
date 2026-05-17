<?php

namespace App\Core\Http\Controllers\Admin;

use App\Core\Http\Controllers\Controller;
use App\Core\Models\Setting;
use App\Core\Models\User;
use App\Core\Models\InstalledPlugin;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    /**
     * Get all settings as a flat key-value object
     */
    public function index()
    {
        $settings = Setting::all()->pluck('value', 'key')->toArray();

        // Convert boolean strings to actual booleans for frontend
        foreach ($settings as $key => $value) {
            if ($value === '1' || $value === 'true') {
                $settings[$key] = true;
            } elseif ($value === '0' || $value === 'false') {
                $settings[$key] = false;
            } elseif (is_numeric($value) && strpos($value, '.') !== false) {
                $settings[$key] = (float) $value;
            } elseif (is_numeric($value)) {
                $settings[$key] = (int) $value;
            }
        }

        return response()->json($settings);
    }

    /**
     * Update settings
     */
    public function update(Request $request)
    {
        // Check if settings are sent as flat object (from frontend) or array format
        $data = $request->all();

        // If data has a 'settings' array with key/value pairs, use that format
        if (isset($data['settings']) && is_array($data['settings'])) {
            // Check if it's the old format: [{key: 'x', value: 'y'}]
            if (isset($data['settings'][0]['key'])) {
                $validated = $request->validate([
                    'settings' => 'required|array',
                    'settings.*.key' => 'required|string',
                    'settings.*.value' => 'required',
                ]);

                foreach ($validated['settings'] as $setting) {
                    Setting::updateOrCreate(
                        ['key' => $setting['key']],
                        ['value' => $setting['value']]
                    );
                }
            }
        } else {
            // Flat object format from frontend: {game_name: 'x', starting_cash: 1000}
            // Exclude any non-setting fields
            $excludeFields = ['_token', '_method'];

            foreach ($data as $key => $value) {
                if (in_array($key, $excludeFields)) {
                    continue;
                }

                // Convert boolean to string for storage
                if (is_bool($value)) {
                    $value = $value ? '1' : '0';
                }

                // Convert arrays/objects to JSON
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value);
                }

                Setting::updateOrCreate(
                    ['key' => $key],
                    ['value' => (string) $value]
                );
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully'
        ]);
    }

    /**
     * Get specific setting
     */
    public function show($key)
    {
        $setting = Setting::where('key', $key)->firstOrFail();
        return response()->json($setting);
    }

    /**
     * Create or update settings (handles both single setting and bulk update)
     */
    public function store(Request $request)
    {
        $data = $request->all();

        // Check if this is a single setting with 'key' field
        if (isset($data['key'])) {
            $validated = $request->validate([
                'key' => 'required|string',
                'value' => 'required',
                'category' => 'nullable|string',
                'type' => 'nullable|string|in:text,number,boolean,json',
                'description' => 'nullable|string',
            ]);

            $setting = Setting::updateOrCreate(
                ['key' => $validated['key']],
                $validated
            );

            return response()->json([
                'success' => true,
                'message' => 'Setting saved successfully',
                'setting' => $setting
            ]);
        }

        // Otherwise, treat as bulk update with flat object format
        // {game_name: 'x', starting_cash: 1000, ...}
        $excludeFields = ['_token', '_method'];

        foreach ($data as $key => $value) {
            if (in_array($key, $excludeFields)) {
                continue;
            }

            // Convert boolean to string for storage
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            // Convert arrays/objects to JSON
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value);
            }

            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => (string) $value]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully'
        ]);
    }

    /**
     * Get security-specific settings (2FA, OAuth, policies)
     */
    public function securityIndex()
    {
        $flat = Setting::all()->pluck('value', 'key')->toArray();

        $securityKeys = [
            'require_2fa_admin', 'allow_2fa_users', 'recovery_codes_count',
            'totp_window', 'min_password_length', 'require_special_chars',
            'require_mixed_case', 'session_timeout', 'max_login_attempts',
            'lockout_duration',
        ];

        $defaults = [
            'require_2fa_admin'    => false,
            'allow_2fa_users'      => true,
            'recovery_codes_count' => 8,
            'totp_window'          => 30,
            'min_password_length'  => 8,
            'require_special_chars'=> false,
            'require_mixed_case'   => false,
            'session_timeout'      => 120,
            'max_login_attempts'   => 5,
            'lockout_duration'     => 15,
        ];

        $security = [];
        foreach ($securityKeys as $key) {
            $raw = $flat[$key] ?? null;
            if ($raw === null) {
                $security[$key] = $defaults[$key];
            } elseif ($raw === '1' || $raw === 'true') {
                $security[$key] = true;
            } elseif ($raw === '0' || $raw === 'false') {
                $security[$key] = false;
            } elseif (is_numeric($raw)) {
                $security[$key] = (int) $raw;
            } else {
                $security[$key] = $raw;
            }
        }

        // 2FA stats
        $totalUsers   = User::count();
        $with2fa      = User::whereNotNull('two_factor_confirmed_at')->count();
        $adminsWith2fa = User::whereNotNull('two_factor_confirmed_at')
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->count();

        $twoFactorStats = [
            'enabled'        => $with2fa,
            'admins_enabled' => $adminsWith2fa,
            'percentage'     => $totalUsers > 0 ? round(($with2fa / $totalUsers) * 100) : 0,
        ];

        // OAuth providers
        $oauthCounts = \App\Core\Models\OAuthProvider::selectRaw('provider, count(*) as cnt')
            ->groupBy('provider')
            ->pluck('cnt', 'provider');

        $providerNames = ['discord', 'google', 'github', 'twitter', 'facebook'];
        $oauthProviders = [];
        foreach ($providerNames as $name) {
            $clientId     = $flat["oauth_{$name}_client_id"]     ?? '';
            $clientSecret = $flat["oauth_{$name}_client_secret"] ?? '';
            $enabled      = ($flat["oauth_{$name}_enabled"] ?? '0') === '1';
            $oauthProviders[] = [
                'name'          => $name,
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'enabled'       => $enabled,
                'configured'    => !empty($clientId) && !empty($clientSecret),
                'users_count'   => (int) ($oauthCounts[$name] ?? 0),
            ];
        }

        return response()->json([
            'security'         => $security,
            'two_factor_stats' => $twoFactorStats,
            'oauth_providers'  => $oauthProviders,
        ]);
    }

    /**
     * Save OAuth provider credentials
     */
    public function saveOAuthProvider(Request $request, string $provider)
    {
        $validated = $request->validate([
            'client_id'     => 'nullable|string|max:500',
            'client_secret' => 'nullable|string|max:500',
            'enabled'       => 'boolean',
        ]);

        Setting::updateOrCreate(['key' => "oauth_{$provider}_client_id"],     ['value' => $validated['client_id'] ?? '']);
        Setting::updateOrCreate(['key' => "oauth_{$provider}_client_secret"], ['value' => $validated['client_secret'] ?? '']);
        Setting::updateOrCreate(['key' => "oauth_{$provider}_enabled"],       ['value' => ($validated['enabled'] ?? false) ? '1' : '0']);

        return response()->json(['success' => true, 'message' => ucfirst($provider) . ' settings saved']);
    }

    /**
     * Delete setting
     */
    public function destroy($key)
    {
        Setting::where('key', $key)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Setting deleted successfully'
        ]);
    }

    /**
     * Get all plugin-defined admin settings schemas.
     * Returns settings groups from enabled plugins.
     */
    public function pluginSettingsSchema()
    {
        $groups = [];
        $enabledPlugins = InstalledPlugin::where('enabled', true)->get();

        foreach ($enabledPlugins as $pluginRecord) {
            $pluginInstance = $this->getPluginInstance($pluginRecord->slug);

            if (!$pluginInstance) {
                continue;
            }

            $adminSettings = $pluginInstance->getAdminSettings();

            foreach ($adminSettings as $groupId => $groupConfig) {
                // Add plugin info to each group
                $groups[$groupId] = array_merge($groupConfig, [
                    'plugin_name' => $pluginRecord->name,
                    'plugin_slug' => $pluginRecord->slug,
                ]);
            }
        }

        // Sort groups by order
        uasort($groups, function ($a, $b) {
            return ($a['order'] ?? 100) <=> ($b['order'] ?? 100);
        });

        return response()->json([
            'groups' => $groups,
        ]);
    }

    /**
     * Get all settings including plugin defaults.
     * Returns settings with their current values and default values from plugins.
     */
    public function allWithDefaults()
    {
        $settings = Setting::all()->pluck('value', 'key')->toArray();
        $defaults = [];
        $groups = [];

        // Get defaults from core
        $coreDefaults = $this->getCoreDefaults();
        $defaults = array_merge($defaults, $coreDefaults);

        // Get defaults and groups from enabled plugins
        $enabledPlugins = InstalledPlugin::where('enabled', true)->get();

        foreach ($enabledPlugins as $pluginRecord) {
            $pluginInstance = $this->getPluginInstance($pluginRecord->slug);

            if (!$pluginInstance) {
                continue;
            }

            $adminSettings = $pluginInstance->getAdminSettings();

            foreach ($adminSettings as $groupId => $groupConfig) {
                // Add to groups
                $groups[$groupId] = [
                    'label' => $groupConfig['label'] ?? ucfirst($groupId),
                    'icon' => $groupConfig['icon'] ?? 'Cog6ToothIcon',
                    'order' => $groupConfig['order'] ?? 100,
                    'plugin_name' => $pluginRecord->name,
                    'plugin_slug' => $pluginRecord->slug,
                    'settings' => [],
                ];

                // Extract defaults from settings
                if (isset($groupConfig['settings'])) {
                    foreach ($groupConfig['settings'] as $key => $config) {
                        $defaults[$key] = $config['default'] ?? null;
                        $groups[$groupId]['settings'][$key] = [
                            'type' => $config['type'] ?? 'text',
                            'label' => $config['label'] ?? $key,
                            'description' => $config['description'] ?? null,
                            'default' => $config['default'] ?? null,
                            'min' => $config['min'] ?? null,
                            'max' => $config['max'] ?? null,
                            'step' => $config['step'] ?? null,
                            'options' => $config['options'] ?? null,
                            'placeholder' => $config['placeholder'] ?? null,
                            'plugin_id' => $config['plugin_id'] ?? null,
                        ];
                    }
                }
            }
        }

        // Add core general settings group
        $coreGroup = [
            'label' => 'General',
            'icon' => 'Cog6ToothIcon',
            'order' => 0,
            'plugin_name' => 'Core',
            'plugin_slug' => 'core',
            'settings' => [
                'game_name' => [
                    'type' => 'text',
                    'label' => 'Game Name',
                    'description' => 'The name displayed throughout the game',
                    'default' => 'Gangster Legends',
                ],
                'registration_enabled' => [
                    'type' => 'boolean',
                    'label' => 'Registration Status',
                    'description' => 'Allow new user registrations',
                    'default' => true,
                ],
                'maintenance_mode' => [
                    'type' => 'boolean',
                    'label' => 'Maintenance Mode',
                    'description' => 'Only admins can access when enabled',
                    'default' => false,
                ],
            ],
        ];

        // Insert core group at beginning
        $groups = ['general' => $coreGroup] + $groups;

        // Merge settings with defaults
        $result = [];
        foreach ($defaults as $key => $default) {
            $raw = $settings[$key] ?? null;
            if ($raw === null) {
                $result[$key] = $default;
            } elseif ($raw === '1' || $raw === 'true') {
                $result[$key] = true;
            } elseif ($raw === '0' || $raw === 'false') {
                $result[$key] = false;
            } elseif (is_numeric($raw)) {
                $result[$key] = strpos($raw, '.') !== false ? (float) $raw : (int) $raw;
            } else {
                $result[$key] = $raw;
            }
        }

        // Also include existing settings that might not have defaults
        foreach ($settings as $key => $value) {
            if (!isset($result[$key])) {
                if ($value === '1' || $value === 'true') {
                    $result[$key] = true;
                } elseif ($value === '0' || $value === 'false') {
                    $result[$key] = false;
                } elseif (is_numeric($value)) {
                    $result[$key] = strpos($value, '.') !== false ? (float) $value : (int) $value;
                } else {
                    $result[$key] = $value;
                }
            }
        }

        // Sort groups by order
        uasort($groups, function ($a, $b) {
            return ($a['order'] ?? 100) <=> ($b['order'] ?? 100);
        });

        return response()->json([
            'settings' => $result,
            'groups' => $groups,
        ]);
    }

    /**
     * Get core default settings.
     */
    protected function getCoreDefaults(): array
    {
        return [
            'game_name' => 'Gangster Legends',
            'registration_enabled' => true,
            'maintenance_mode' => false,
        ];
    }

    /**
     * Get plugin instance from slug.
     */
    protected function getPluginInstance(string $slug): ?\App\Core\Contracts\PluginInterface
    {
        $pluginPath = app_path('Plugins/' . $slug);

        if (!is_dir($pluginPath)) {
            // Try to find with different casing
            $directories = glob(app_path('Plugins/*'), GLOB_ONLYDIR);
            foreach ($directories as $dir) {
                if (strtolower(basename($dir)) === strtolower($slug)) {
                    $pluginPath = $dir;
                    break;
                }
            }
        }

        // Find the plugin class
        $pluginJsonPath = $pluginPath . '/plugin.json';
        if (!file_exists($pluginJsonPath)) {
            return null;
        }

        $manifest = json_decode(file_get_contents($pluginJsonPath), true);
        $pascal = str_replace('-', '', ucwords($slug, '-'));
        $pluginClass = "App\\Plugins\\{$pascal}\\{$pascal}Plugin";

        if (!class_exists($pluginClass)) {
            return null;
        }

        try {
            return app($pluginClass);
        } catch (\Exception $e) {
            return null;
        }
    }
}
