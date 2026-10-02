<?php

use App\Mail\SiteDownClientMail;
use App\Mail\SiteUpClientMail;
use App\Models\NotificationLog;
use App\Models\NotificationRecipient;
use App\Models\Site;
use App\Services\Chat\ChatNotifier;
use App\Services\Twilio\OnCallResolver;
use Illuminate\Support\Facades\Mail;
use Modules\Twilio\TwilioClient;
use Modules\Twilio\TwilioSmsNotifier;

describe('TwilioSmsNotifier Client Alerts', function () {
    beforeEach(function () {
        Mail::fake();
    });

    it('dispatches client SMS and email when a monitored site goes down, even without care plan', function () {
        $site = Site::factory()->create([
            'domain' => 'client-monitored.test',
            'care_plan_enabled' => false,
        ]);

        $clientBoth = NotificationRecipient::factory()->client()->create([
            'name' => 'Full Client',
            'phone' => '+15555550101',
            'email' => 'both@client.test',
            'notify_sms' => true,
            'notify_email' => true,
            'enabled' => true,
        ]);

        $clientEmailOnly = NotificationRecipient::factory()->client()->emailOnly()->create([
            'name' => 'Email Client',
            'email' => 'emailonly@client.test',
            'notify_email' => true,
            'enabled' => true,
        ]);

        $site->notificationRecipients()->attach([$clientBoth->id, $clientEmailOnly->id]);

        $twilio = Mockery::mock(TwilioClient::class);
        $twilio->shouldReceive('isConfigured')->andReturn(true);
        $twilio->shouldReceive('sms')
            ->once()
            ->with('+15555550101', Mockery::on(fn ($body) => str_contains($body, 'client-monitored.test') && str_contains($body, 'Clockwork')))
            ->andReturn(['sid' => 'SM12345', 'status' => 'queued']);

        $resolver = app(OnCallResolver::class);
        $chat = Mockery::mock(ChatNotifier::class);

        $notifier = new TwilioSmsNotifier($twilio, $resolver, $chat);

        $sent = $notifier->siteWentDown($site, 500, 'HTTP 500 Internal Server Error');

        expect($sent)->toBeTrue();

        Mail::assertSent(SiteDownClientMail::class, function ($mail) {
            return $mail->hasTo('both@client.test');
        });

        Mail::assertSent(SiteDownClientMail::class, function ($mail) {
            return $mail->hasTo('emailonly@client.test');
        });

        // Verify logs created
        $this->assertDatabaseHas('notification_log', [
            'recipient_id' => $clientBoth->id,
            'site_id' => $site->id,
            'event' => NotificationLog::EVENT_SITE_DOWN,
            'ok' => true,
        ]);

        $this->assertDatabaseHas('notification_log', [
            'recipient_id' => $clientBoth->id,
            'site_id' => $site->id,
            'event' => NotificationLog::EVENT_SITE_DOWN.'_email',
            'ok' => true,
        ]);

        $this->assertDatabaseHas('notification_log', [
            'recipient_id' => $clientEmailOnly->id,
            'site_id' => $site->id,
            'event' => NotificationLog::EVENT_SITE_DOWN.'_email',
            'ok' => true,
        ]);
    });

    it('dispatches client recovery alerts when a site recovers', function () {
        $site = Site::factory()->create([
            'domain' => 'client-recovery.test',
            'care_plan_enabled' => false,
        ]);

        $client = NotificationRecipient::factory()->client()->create([
            'name' => 'Recovery Client',
            'phone' => '+15555550102',
            'email' => 'recovery@client.test',
            'notify_sms' => true,
            'notify_email' => true,
            'enabled' => true,
        ]);

        $site->notificationRecipients()->attach($client->id);

        $twilio = Mockery::mock(TwilioClient::class);
        $twilio->shouldReceive('isConfigured')->andReturn(true);
        $twilio->shouldReceive('sms')
            ->once()
            ->with('+15555550102', Mockery::on(fn ($body) => str_contains($body, 'client-recovery.test') && str_contains($body, 'back online')))
            ->andReturn(['sid' => 'SM67890', 'status' => 'queued']);

        $resolver = app(OnCallResolver::class);
        $chat = Mockery::mock(ChatNotifier::class);

        $notifier = new TwilioSmsNotifier($twilio, $resolver, $chat);

        $sent = $notifier->siteWentUp($site, 300);

        expect($sent)->toBeTrue();

        Mail::assertSent(SiteUpClientMail::class, function ($mail) {
            return $mail->hasTo('recovery@client.test');
        });

        $this->assertDatabaseHas('notification_log', [
            'recipient_id' => $client->id,
            'site_id' => $site->id,
            'event' => NotificationLog::EVENT_SITE_UP,
            'ok' => true,
        ]);

        $this->assertDatabaseHas('notification_log', [
            'recipient_id' => $client->id,
            'site_id' => $site->id,
            'event' => NotificationLog::EVENT_SITE_UP.'_email',
            'ok' => true,
        ]);
    });

    it('does not send alerts to client recipients subscribed to a different site', function () {
        $siteA = Site::factory()->create(['domain' => 'site-a.test']);
        $siteB = Site::factory()->create(['domain' => 'site-b.test']);

        $clientA = NotificationRecipient::factory()->client()->create([
            'email' => 'client-a@example.test',
            'phone' => '+15555550103',
        ]);
        $clientB = NotificationRecipient::factory()->client()->create([
            'email' => 'client-b@example.test',
            'phone' => '+15555550104',
        ]);

        $siteA->notificationRecipients()->attach($clientA->id);
        $siteB->notificationRecipients()->attach($clientB->id);

        $twilio = Mockery::mock(TwilioClient::class);
        $twilio->shouldReceive('isConfigured')->andReturn(true);
        $twilio->shouldReceive('sms')
            ->once()
            ->with('+15555550103', Mockery::any())
            ->andReturn(['sid' => 'SM999', 'status' => 'queued']);

        $resolver = app(OnCallResolver::class);
        $chat = Mockery::mock(ChatNotifier::class);

        $notifier = new TwilioSmsNotifier($twilio, $resolver, $chat);

        // Site A goes down: only Client A receives alert
        $notifier->siteWentDown($siteA, 503, 'Service Unavailable');

        Mail::assertSent(SiteDownClientMail::class, function ($mail) {
            return $mail->hasTo('client-a@example.test');
        });

        Mail::assertNotSent(SiteDownClientMail::class, function ($mail) {
            return $mail->hasTo('client-b@example.test');
        });
    });
});
