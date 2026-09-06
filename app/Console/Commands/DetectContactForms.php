<?php

namespace App\Console\Commands;

if (! class_exists(DetectContactForms::class, false)) {
    class_alias(\Modules\ContactForms\Commands\DetectContactForms::class, DetectContactForms::class);
}
