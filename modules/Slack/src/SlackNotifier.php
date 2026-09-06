<?php

namespace Modules\Slack;

use Modules\Core\Support\WebhookChatNotifier;

/**
 * Slack incoming-webhook chat notifications. All the actual behavior (the
 * 14 ChatNotifier event methods, the webhook POST, the per-event toggle
 * mechanism) lives in the shared Modules\Core\Support\WebhookChatNotifier
 * base — this class only says which config namespace and settings-key
 * prefix belong to Slack specifically. See Modules\Mattermost\
 * MattermostNotifier for the sibling.
 */
class SlackNotifier extends WebhookChatNotifier
{
    /** Read directly by SlackSettingsController — must match settingsKey()'s value. */
    public const SETTINGS_KEY = 'notifications.slack.events';

    protected function configNamespace(): string
    {
        return 'slack';
    }

    protected function channelLabel(): string
    {
        return 'Slack';
    }
}
