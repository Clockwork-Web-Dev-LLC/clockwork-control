<?php

namespace App\Console\Commands;

if (! class_exists(GridPaneTest::class, false)) {
    class_alias(\Modules\GridPane\Commands\GridPaneTest::class, GridPaneTest::class);
}
