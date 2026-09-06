<?php

namespace Modules\Cloudways;

use Modules\Core\Exceptions\ReadOnlyModeException;

class CloudwaysReadOnlyException extends ReadOnlyModeException
{
    // Thrown when an API mutation or remote modification is attempted while Cloudways is in View-Only mode.
}
