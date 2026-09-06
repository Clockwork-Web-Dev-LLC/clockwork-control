<?php

namespace App\Console\Commands;

if (! class_exists(WPEngineTest::class, false)) {
    class_alias(\Modules\WPEngine\Commands\WPEngineTest::class, WPEngineTest::class);
}
