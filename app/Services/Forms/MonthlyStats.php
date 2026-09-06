<?php

namespace App\Services\Forms;

if (! class_exists(MonthlyStats::class, false)) {
    class_alias(\Modules\ContactForms\MonthlyStats::class, MonthlyStats::class);
}
