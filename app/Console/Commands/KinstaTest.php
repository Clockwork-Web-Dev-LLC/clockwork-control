<?php

namespace App\Console\Commands;

if (! class_exists(KinstaTest::class, false)) {
    class_alias(\Modules\Kinsta\Commands\KinstaTest::class, KinstaTest::class);
}
