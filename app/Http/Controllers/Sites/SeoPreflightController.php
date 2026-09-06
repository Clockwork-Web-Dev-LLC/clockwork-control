<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Seo\IndexabilityChecker;
use Illuminate\Http\JsonResponse;

class SeoPreflightController extends Controller
{
    public function preflight(Site $site, IndexabilityChecker $checker): JsonResponse
    {
        $result = $checker->preflightCheck($site);

        return response()->json($result);
    }
}
