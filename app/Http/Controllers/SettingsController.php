<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Site;
use App\Models\Tag;
use App\Models\User;
use App\Services\Companion\CompanionBrandingManager;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Core\ModuleRegistry;

class SettingsController extends Controller
{
    /**
     * Display the Settings Hub overview.
     */
    public function index(
        Request $request,
        CompanionBrandingManager $brandingManager,
        ModuleRegistry $registry
    ): View {
        $branding = $brandingManager->get();
        $userCount = User::query()->count();
        $tagsCount = Tag::query()->count();
        $sitesCount = Site::query()->count();
        $serversCount = Server::query()->count();
        $moduleNavItems = $registry->navItems();

        return view('settings.index', [
            'branding' => $branding,
            'userCount' => $userCount,
            'tagsCount' => $tagsCount,
            'sitesCount' => $sitesCount,
            'serversCount' => $serversCount,
            'moduleNavItems' => $moduleNavItems,
        ]);
    }
}
