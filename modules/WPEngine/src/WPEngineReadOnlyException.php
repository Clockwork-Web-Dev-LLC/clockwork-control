<?php

namespace Modules\WPEngine;

use Modules\Core\Exceptions\ReadOnlyModeException;

class WPEngineReadOnlyException extends ReadOnlyModeException
{
    // Thrown when an API mutation or remote modification is attempted while WP Engine is in View-Only mode.
}
