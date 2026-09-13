<?php

namespace App\Http\Controllers;

use App\Services\Companion\CompanionTarballBuilder;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CompanionDownloadController extends Controller
{
    /**
     * Generate and stream a standard WordPress plugin zip package (clockwork-companion-v*.zip)
     * ready to be uploaded directly via wp-admin -> Plugins -> Add New -> Upload Plugin.
     */
    public function downloadZip(CompanionTarballBuilder $builder): Response
    {
        try {
            $bytes = $builder->buildPluginZipBytes();
            $version = (string) config('clockwork.companion.version', '1.35.0');
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
}
