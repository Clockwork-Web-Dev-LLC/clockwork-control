<?php

namespace Modules\GridPane;

use Modules\Core\Exceptions\ReadOnlyModeException;

class GridPaneReadOnlyException extends ReadOnlyModeException
{
    // Thrown when an API mutation or remote modification is attempted while GridPane is in View-Only mode.
}
