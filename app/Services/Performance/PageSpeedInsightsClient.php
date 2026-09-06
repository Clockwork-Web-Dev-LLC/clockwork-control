<?php

namespace App\Services\Performance;

if (! class_exists(PageSpeedInsightsClient::class, false)) {
    class_alias(\Modules\PageSpeedInsights\PageSpeedInsightsClient::class, PageSpeedInsightsClient::class);
}
