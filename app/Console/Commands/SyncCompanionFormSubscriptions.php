<?php

namespace App\Console\Commands;

if (! class_exists(SyncCompanionFormSubscriptions::class, false)) {
    class_alias(\Modules\ContactForms\Commands\SyncCompanionFormSubscriptions::class, SyncCompanionFormSubscriptions::class);
}
