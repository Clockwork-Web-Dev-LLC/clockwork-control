<?php

namespace Modules\Core\Contracts;

use App\Models\NotificationRecipient;
use App\Models\Site;

/**
 * A single, fleet-wide SMS vendor for site-down/up paging on care-plan
 * sites — unlike ChatNotifier (every registered channel fans out, since an
 * agency might reasonably want both Mattermost and Slack at once), realistic
 * SMS setups have exactly one vendor active. Modules\Core\ModuleRegistry
 * resolves whichever module contributes a non-null smsNotifier(); the
 * container binds this interface to that instance, or to NullSmsNotifier if
 * no SMS module is installed at all — the same shape CloudProvider's
 * NullCloudProvider already established.
 *
 * References App\Models\Site / App\Models\NotificationRecipient — the same
 * deliberate, narrow "module code may depend on core domain types" exception
 * CloudProvider's own docblock documents for App\Models\Server.
 */
interface SmsNotifier
{
    /** Are real credentials configured AND the channel enabled? */
    public function isConfigured(): bool;

    /** The number SMS is sent from, or '' if unconfigured — shown on /settings/notifications. */
    public function fromNumber(): string;

    public function siteWentDown(Site $site, ?int $statusCode, ?string $error): bool;

    public function siteWentUp(Site $site, ?int $downtimeSec): bool;

    /**
     * Send a one-off test message to a specific recipient. Used by the
     * "Test SMS" button on /settings/notifications.
     */
    public function test(NotificationRecipient $recipient, string $body = 'Clockwork test message — your phone is configured correctly.'): bool;
}
