<?php

namespace Modules\Core\Exceptions;

use RuntimeException;

class ReadOnlyModeException extends RuntimeException
{
    public function __construct(string $providerName, ?string $action = null)
    {
        $message = $action !== null
            ? "{$providerName} integration is in View-Only mode. {$action} is prohibited."
            : $providerName;

        parent::__construct($message);
    }
}
