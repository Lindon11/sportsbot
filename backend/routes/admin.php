<?php

use Illuminate\Support\Facades\Route;
use App\Core\Admin\AdminSidebarService;

Route::middleware(['auth:sanctum', 'role:admin|moderator', 'verify.license'])
    ->prefix('admin')
    ->group(function () {
        Route::get('/sidebar', function () {
            $user = request()->user();
            $menu = AdminSidebarService::getSidebarItems($user);
            return response()->json(['menu' => $menu]);
        });
        // ...other admin routes
    });
