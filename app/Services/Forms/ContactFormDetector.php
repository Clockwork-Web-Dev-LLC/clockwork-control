<?php

namespace App\Services\Forms;

if (! class_exists(ContactFormDetector::class, false)) {
    class_alias(\Modules\ContactForms\ContactFormDetector::class, ContactFormDetector::class);
}
