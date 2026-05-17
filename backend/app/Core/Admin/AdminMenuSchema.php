<?php

namespace App\Core\Admin;

class AdminMenuSchema
{
    /**
     * Returns the strict schema for admin sidebar menu items.
     */
    public static function schema(): array
    {
        return [
            'id' => 'string|required',
            'label' => 'string|required',
            'icon' => 'string|required',
            'route' => 'string|required',
            'permission' => 'string|required',
            'order' => 'integer|optional',
        ];
    }
}
