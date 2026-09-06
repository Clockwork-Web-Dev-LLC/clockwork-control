<?php

namespace Modules\SpinupWp;

use Modules\Core\Exceptions\ReadOnlyModeException;

class SpinupWpReadOnlyException extends ReadOnlyModeException
{
    // Thrown when an API mutation or remote modification is attempted while SpinupWP is in View-Only mode.
}
