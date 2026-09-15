<?php

namespace App\Http\Controllers;

use App\Services\Companion\CompanionTarballBuilder;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CompanionDownloadController extends Controller
{
    /**
     * Display the download hub for Clockwork WordPress plugins (Companion and Renegade).
     */
    public function index(): View
    {
        $companionPath = (string) config('clockwork.companion.local_path');
        $renegadePath = (string) config('clockwork.renegade.local_path');

        $companion = [
            'name' => 'Clockwork Companion',
            'edition' => 'Private Edition (Mu-Plugin / Standard Plugin)',
            'slug' => 'clockwork-companion',
            'version' => (string) config('clockwork.companion.version', '1.37.1'),
            'available' => is_dir($companionPath) && is_file($companionPath.'/clockwork-companion.php'),
            'download_url' => route('companion.download'),
            'tag' => 'Agency Fleet & Managed Hosting',
            'tag_color' => 'blue',
            'license' => 'Proprietary / Internal Agency',
            'route_namespace' => '/wp-json/clockwork/v1/',
            'pairing_screen' => 'Tools → Clockwork Control',
        ];

        $renegade = [
            'name' => 'Clockwork Renegade',
            'edition' => 'Official WordPress.org Directory Edition',
            'slug' => 'clockwork-renegade',
            'version' => (string) config('clockwork.renegade.version', '1.0.0'),
            'available' => is_dir($renegadePath) && is_file($renegadePath.'/clockwork-renegade.php'),
            'download_url' => route('renegade.download'),
            'tag' => 'WordPress.org Directory / Open Source',
            'tag_color' => 'green',
            'license' => 'GPL-2.0-or-later',
            'route_namespace' => '/wp-json/clockwork-renegade/v1/',
            'pairing_screen' => 'Clockwork → Connection',
        ];

        return view('downloads.index', [
            'companion' => $companion,
            'renegade' => $renegade,
        ]);
    }

    /**
     * Generate and stream a standard WordPress plugin zip package (clockwork-companion-v*.zip)
     * ready to be uploaded directly via wp-admin -> Plugins -> Add New -> Upload Plugin.
     */
    public function downloadZip(CompanionTarballBuilder $builder): Response
    {
        try {
            $bytes = $builder->buildPluginZipBytes();
            $version = (string) config('clockwork.companion.version', '1.37.1');
            $filename = "clockwork-companion-{$version}.zip";

            return response($bytes, 200, [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Content-Length' => strlen($bytes),
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        } catch (Throwable $e) {
            report($e);
            abort(500, 'Could not generate companion package.');
        }
    }

    /**
     * Generate and stream the official WordPress.org plugin zip package (clockwork-renegade-v*.zip)
     * ready to be uploaded directly via wp-admin -> Plugins -> Add New -> Upload Plugin.
     */
    public function downloadRenegadeZip(CompanionTarballBuilder $builder): Response
    {
        try {
            $bytes = $builder->buildRenegadeZipBytes();
            $version = (string) config('clockwork.renegade.version', '1.0.0');
            $filename = "clockwork-renegade-{$version}.zip";

            return response($bytes, 200, [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Content-Length' => strlen($bytes),
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        } catch (Throwable $e) {
            report($e);
            abort(500, 'Could not generate renegade package.');
        }
    }
}
