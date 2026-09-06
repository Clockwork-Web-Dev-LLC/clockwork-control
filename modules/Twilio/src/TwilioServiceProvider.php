<?php

namespace Modules\Twilio;

use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Modules\Core\Contracts\SmsNotifier;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;
use Modules\Core\NavItem;

/**
 * Twilio SMS on-call paging, as a real, independently installable module
 * — an agency that doesn't use Twilio can leave this out of their
 * composer.json entirely and the core app binds NullSmsNotifier instead.
 *
 * Unlike Slack/Mattermost (which fan-out in parallel via the
 * 'clockwork.notifiers' tag), SMS has a single-vendor model: at most one
 * SmsNotifier is active fleet-wide. This module contributes itself via
 * smsNotifier() rather than container-tagging, and CoreServiceProvider's
 * lazy binding picks up whichever module returns non-null first.
 */
class TwilioServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->singleton(TwilioClient::class, function ($app) {
            $r = $app->make(CredentialResolver::class);

            return new TwilioClient(
                accountSid: $r->get('twilio.account_sid'),
                authToken: $r->get('twilio.auth_token'),
                fromNumber: $r->get('twilio.from'),
                enabled: (bool) $r->get('twilio.enabled', false),
            );
        });
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'twilio',
            name: 'Twilio SMS',
            description: 'On-call SMS paging for care-plan site downtime via Twilio. Storm circuit-breaker, on-call off-window scheduling, email + chat fallback when no recipient is reachable.',
            credentialFields: [
                'account_sid' => ['label' => 'Account SID', 'secret' => false],
                'auth_token' => ['label' => 'Auth Token', 'secret' => true],
                'from' => ['label' => 'From Number', 'secret' => false],
            ],
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Verified SMS downtime alerting and on-call notification delivery.',
        );
    }

    public function diagnosticCheck(): ?DiagnosticCheck
    {
        return $this->app->make(TwilioCheck::class);
    }

    public function smsNotifier(): ?SmsNotifier
    {
        return $this->app->make(TwilioSmsNotifier::class);
    }

    public function navItems(): array
    {
        $r = $this->app->make(CredentialResolver::class);

        if (! (bool) $r->get('twilio.enabled', false)) {
            return [];
        }

        return [
            new NavItem(
                label: 'SMS notifications',
                icon: 'fa-solid fa-comment-sms',
                route: 'settings.notifications.index',
            ),
        ];
    }
}
