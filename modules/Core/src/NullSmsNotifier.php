<?php

namespace Modules\Core;

use App\Models\NotificationRecipient;
use App\Models\Site;
use Modules\Core\Contracts\SmsNotifier;

/**
 * Bound when no module contributes a real SmsNotifier — mirrors
 * NullCloudProvider's role exactly. Every call site (UptimeStateUpdater,
 * NotificationSettingsController) expects a usable SmsNotifier back, not a
 * nullable one to guard against everywhere; this keeps that contract true
 * with an SMS module uninstalled, rather than every caller needing its own
 * "is SMS even available" check.
 */
class NullSmsNotifier implements SmsNotifier
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function fromNumber(): string
    {
        return '';
    }

    public function siteWentDown(Site $site, ?int $statusCode, ?string $error): bool
    {
        return false;
    }

    public function siteWentUp(Site $site, ?int $downtimeSec): bool
    {
        return false;
    }

    public function test(NotificationRecipient $recipient, string $body = 'Clockwork test message — your phone is configured correctly.'): bool
    {
        return false;
    }
}
