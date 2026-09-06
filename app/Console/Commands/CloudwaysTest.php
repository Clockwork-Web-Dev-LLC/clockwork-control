<?php

namespace App\Console\Commands;

if (! class_exists(CloudwaysTest::class, false)) {
    class_alias(\Modules\Cloudways\Commands\CloudwaysTest::class, CloudwaysTest::class);
}
