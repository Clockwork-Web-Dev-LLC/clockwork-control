<?php

namespace Modules\Kinsta;

use Modules\Core\Exceptions\ReadOnlyModeException;

class KinstaReadOnlyException extends ReadOnlyModeException
{
    // Thrown when an API mutation or remote modification is attempted while Kinsta is in View-Only mode.
}
