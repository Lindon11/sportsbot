<?php

namespace App\Core\Admin;

class CoreAdminMenu
{
    /**
     * Get the default admin sidebar items.
     *
     * Only includes items that have complete backend implementations.
     * See ADMIN_MENU_AUDIT.md for tracking missing features.
     */
    public static function items(): array
    {
        return [
            [
                'id' => 'user-management',
                'label' => 'User Management',
                'icon' => 'UsersIcon',
                'order' => 1,
                'children' => [
                    [ 'route' => '/users',      'label' => 'Users',               'icon' => 'UsersIcon' ],
                    [ 'route' => '/user-tools', 'label' => 'User Tools',          'icon' => 'WrenchScrewdriverIcon' ],
                    [ 'route' => '/roles',      'label' => 'Roles & Permissions', 'icon' => 'ShieldCheckIcon' ],
                    [ 'route' => '/ip-bans',    'label' => 'IP Bans',             'icon' => 'NoSymbolIcon' ],
                ],
            ],
            [
                'id' => 'configuration',
                'label' => 'Configuration',
                'icon' => 'Cog6ToothIcon',
                'order' => 2,
                'children' => [
                    [ 'route' => '/settings',       'label' => 'Settings',    'icon' => 'Cog6ToothIcon' ],
                    [ 'route' => '/live-env',       'label' => 'Live Env',    'icon' => 'ServerIcon' ],
                    [ 'route' => '/email-settings', 'label' => 'Email',       'icon' => 'EnvelopeIcon' ],
                    [ 'route' => '/plugin-settings','label' => 'Plugins',     'icon' => 'PuzzlePieceIcon' ],
                    [ 'route' => '/license',        'label' => 'License',     'icon' => 'KeyIcon' ],
                ],
            ],
            // Communication section removed - no backend implementations
            // See ADMIN_MENU_AUDIT.md for tracking
            // Support section removed - no backend implementations
            // See ADMIN_MENU_AUDIT.md for tracking
            [
                'id' => 'system',
                'label' => 'System',
                'icon' => 'ServerIcon',
                'order' => 90,
                'children' => [
                    [ 'route' => '/system-health',  'label' => 'System Health',   'icon' => 'ServerIcon' ],
                    [ 'route' => '/error-logs',     'label' => 'Error Logs',      'icon' => 'ExclamationTriangleIcon' ],
                    [ 'route' => '/activity-logs',  'label' => 'Activity Logs',   'icon' => 'ClipboardDocumentListIcon' ],
                    [ 'route' => '/security',       'label' => 'Security',        'icon' => 'ShieldCheckIcon' ],
                    [ 'route' => '/backups',        'label' => 'Backups',         'icon' => 'CircleStackIcon' ],
                    [ 'route' => '/webhooks',       'label' => 'Webhooks',        'icon' => 'ArrowTopRightOnSquareIcon' ],
                    [ 'route' => '/api-keys',       'label' => 'API Keys',        'icon' => 'KeyIcon' ],
                    [ 'route' => '/notifications',  'label' => 'Notifications',   'icon' => 'BellIcon' ],
                    // Calendar and Tasks removed - no backend implementations
                    // See ADMIN_MENU_AUDIT.md for tracking
                ],
            ],
        ];
    }
}
