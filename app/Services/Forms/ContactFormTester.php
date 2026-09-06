<?php

namespace App\Services\Forms;

if (! class_exists(ContactFormTester::class, false)) {
    class_alias(\Modules\ContactForms\ContactFormTester::class, ContactFormTester::class);
}
