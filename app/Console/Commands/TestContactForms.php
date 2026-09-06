<?php

namespace App\Console\Commands;

if (! class_exists(TestContactForms::class, false)) {
    class_alias(\Modules\ContactForms\Commands\TestContactForms::class, TestContactForms::class);
}
