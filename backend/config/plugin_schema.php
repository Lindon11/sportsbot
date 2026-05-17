<?php
// config/plugin_schema.php

return [
    /*
    |--------------------------------------------------------------------------
    | Required Plugin Fields
    |--------------------------------------------------------------------------
    |
    | These fields must be present in every plugin.json file.
    | The plugin will fail validation if any are missing.
    |
    */
    'required' => [
        'name',
        'slug',
        'version',
        'author',
        'description',
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional Plugin Fields
    |--------------------------------------------------------------------------
    |
    | These fields are optional but recommended for full functionality.
    |
    */
    'optional' => [
        'requires',
        'settings',
        'hooks',
        'routes',
        'permissions',
        'frontend',
        'license_required',
        'admin_settings',
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend Contract Schema
    |--------------------------------------------------------------------------
    |
    | Defines the structure for frontend integration.
    | Plugins can specify routes, components, and UI slots.
    |
    */
    'frontend' => [
        'routes' => [
            'path' => 'required|string',
            'name' => 'nullable|string',
            'component' => 'required|string',
            'meta' => 'nullable|array',
        ],
        'slots' => [
            'dashboard-widget',
            'sidebar-panel',
            'profile-tab',
            'inventory-slot',
            'navigation-item',
            'header-slot',
            'user-menu-item',
        ],
        /*
        |----------------------------------------------------------------------
        | Dashboard Widgets
        |----------------------------------------------------------------------
        |
        | Plugins can register dashboard widgets that appear on the main
        | dashboard. Each widget has a component, width, and optional props.
        |
        */
        'dashboard_widgets' => [
            'name' => 'required|string',
            'component' => 'required|string',
            'width' => 'nullable|string|in:full,half,third,quarter',
            'order' => 'nullable|integer',
            'props' => 'nullable|array',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Settings Schema
    |--------------------------------------------------------------------------
    |
    | Defines the structure for plugin settings in plugin.json.
    |
    */
    'settings' => [
        'icon' => 'nullable|string',
        'color' => 'nullable|string',
        'route' => 'nullable|string',
        'menu' => [
            'enabled' => 'boolean',
            'order' => 'integer',
            'section' => 'string',
            'parent' => 'nullable|string',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin Settings Schema
    |--------------------------------------------------------------------------
    |
    | Defines the structure for plugin admin settings in plugin.json.
    | Plugins can register their own settings tabs/sections that appear
    | in the admin settings page.
    |
    | Example:
    | "admin_settings": {
    |   "combat": {
    |     "label": "Combat",
    |     "icon": "FireIcon",
    |     "order": 10,
    |     "settings": {
    |       "attack_cooldown": {
    |         "type": "number",
    |         "label": "Attack Cooldown (seconds)",
    |         "default": 300,
    |         "description": "Cooldown between attacks",
    |         "min": 0,
    |         "max": 3600
    |       }
    |     }
    |   }
    | }
    |
    */
    'admin_settings' => [
        'label' => 'required|string',
        'icon' => 'nullable|string',
        'order' => 'nullable|integer',
        'settings' => 'required|array',
        'settings.*.type' => 'required|string|in:text,number,boolean,select,json',
        'settings.*.label' => 'required|string',
        'settings.*.default' => 'nullable',
        'settings.*.description' => 'nullable|string',
        'settings.*.min' => 'nullable|numeric',
        'settings.*.max' => 'nullable|numeric',
        'settings.*.step' => 'nullable|numeric',
        'settings.*.options' => 'nullable|array', // For select type
        'settings.*.placeholder' => 'nullable|string',
    ],
];
