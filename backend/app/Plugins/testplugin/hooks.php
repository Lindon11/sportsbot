<?php

/**
 * TestPlugin Plugin Hooks
 *
 * Register hooks for plugin integration with the core system.
 *
 * Available hook types:
 * - Action hooks (side-effects): Run code when events occur
 * - Filter hooks (transform): Modify data and return modified values
 *
 * Hooks are fired from the Hook facade:
 *   Hook::fire('hook.name', ['key' => 'value']);
 */

use App\Facades\Hook;

// Example: Initialize plugin data when a new user is created
// Hook::register('user.created', function ($user) {
//     $user->setManyPluginMeta('testplugin', [
//         'some_data' => 'default_value',
//     ]);
// }, 10);

// Example: Add navigation menu items
// Hook::register('customMenus', function ($user) {
//     if (!$user) return [];
//
//     return [
//         'testplugin' => [
//             'title' => 'TestPlugin',
//             'items' => [
//                 [
//                     'url' => '/testplugin',
//                     'text' => 'Dashboard',
//                     'icon' => '📦',
//                     'sort' => 50,
//                 ],
//             ],
//         ],
//     ];
// }, 10);

// Example: Add widget to user profile
// Hook::register('user.profile.widgets', function ($widgets) {
//     $widgets['testplugin'] = [
//         'title' => 'TestPlugin',
//         'component' => 'TestPluginWidget.vue',
//         'order' => 20,
//     ];
//     return $widgets;
// }, 10);

// Example: Modify data (filter hook)
// Hook::register('some.filter', function ($data) {
//     $data['modified'] = true;
//     return $data;
// }, 10);