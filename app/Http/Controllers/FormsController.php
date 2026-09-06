<?php

namespace App\Http\Controllers;

if (! class_exists(FormsController::class, false)) {
    class_alias(\Modules\ContactForms\FormsController::class, FormsController::class);
}
