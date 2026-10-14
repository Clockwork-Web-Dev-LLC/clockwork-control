<?php

namespace Modules\TurnkeyWp;

use Modules\Core\Exceptions\ReadOnlyModeException;

class TurnkeyWpReadOnlyException extends ReadOnlyModeException
{
    // Thrown when an API mutation is attempted while TurnkeyWP is in View-Only mode.
}
