<?php

namespace Modules\Mattermost;

use Modules\Core\Support\WebhookChatNotifier;

/**
 * Mattermost incoming-webhook chat notifications. All the actual behavior
 * (the 14 ChatNotifier event methods, the webhook POST, the per-event
 * toggle mechanism) lives in the shared Modules\Core\Support\
 * WebhookChatNotifier base — this class only says which config namespace
 * and settings-key prefix belong to Mattermost specifically. See
 * Modules\Slack\SlackNotifier for the sibling.
 */
class MattermostNotifier extends WebhookChatNotifier
{
    /** Read directly by MattermostSettingsController — must match settingsKey()'s value. */
    public const SETTINGS_KEY = 'notifications.mattermost.events';

    protected function configNamespace(): string
    {
        return 'mattermost';
    }

    protected function channelLabel(): string
    {
        return 'Mattermost';
    }
}
