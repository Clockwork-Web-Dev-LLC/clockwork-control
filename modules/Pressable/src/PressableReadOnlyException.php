<?php

namespace Modules\Pressable;

use Modules\Core\Exceptions\ReadOnlyModeException;

class PressableReadOnlyException extends ReadOnlyModeException
{
    // Thrown when an API mutation or remote modification is attempted while Pressable is in View-Only mode.
}
