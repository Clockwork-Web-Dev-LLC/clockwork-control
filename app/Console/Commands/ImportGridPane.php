<?php

namespace App\Console\Commands;

if (! class_exists(ImportGridPane::class, false)) {
    class_alias(\Modules\GridPane\Commands\ImportGridPane::class, ImportGridPane::class);
}
