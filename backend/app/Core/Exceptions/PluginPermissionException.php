<?php

namespace App\Core\Exceptions;

use RuntimeException;

class PluginPermissionException extends RuntimeException
{
    public function __construct(
        public readonly string $pluginSlug,
        public readonly string $permission
    ) {
        parent::__construct(
            "Plugin '{$pluginSlug}' attempted '{$permission}' without declaring it in plugin.json permissions."
        );
    }
}
