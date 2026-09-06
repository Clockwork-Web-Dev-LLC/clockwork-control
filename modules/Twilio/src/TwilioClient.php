<?php

namespace Modules\Twilio;

use RuntimeException;
use Throwable;
use Twilio\Rest\Client;

/**
 * Thin wrapper around Twilio's PHP SDK. Mirrors the constructor +
 * isConfigured shape used by DigitalOceanClient / HetznerClient so the
 * mental model stays consistent — config-driven defaults, easy to
 * stub in tests with a custom auth.
 *
 * Auth model: Twilio's per-message API takes (Account SID, Auth Token,
 * From Number). We don't authenticate the receiver — the SMS goes to
 * whatever E.164 number we hand in, and the recipient identifies us by
 * the From number. A2P 10DLC registration is a Twilio-side concern,
 * not visible to this client.
 */
class TwilioClient
{
    public function __construct(
        protected ?string $accountSid = null,
        protected ?string $authToken = null,
        protected ?string $fromNumber = null,
        protected ?bool $enabled = null,
    ) {
        $this->accountSid ??= (string) config('clockwork.twilio.account_sid');
        $this->authToken ??= (string) config('clockwork.twilio.auth_token');
        $this->fromNumber ??= (string) config('clockwork.twilio.from');
        $this->enabled ??= (bool) config('clockwork.twilio.enabled', false);
    }

    public function isConfigured(): bool
    {
        return $this->enabled
            && $this->accountSid !== ''
            && $this->authToken !== ''
            && $this->fromNumber !== '';
    }

    /**
     * Send one SMS. Returns the Twilio message resource as an array
     * (sid, status, etc.). Throws on transport / API errors so the
     * caller can record + fall back.
     *
     * @return array{sid: ?string, status: ?string, to: string, body: string}
     */
    public function sms(string $to, string $body): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Twilio is not configured (TWILIO_ENABLED + TWILIO_ACCOUNT_SID + TWILIO_AUTH_TOKEN + TWILIO_FROM_NUMBER required).');
        }

        try {
            $client = new Client($this->accountSid, $this->authToken);
            $message = $client->messages->create($to, [
                'from' => $this->fromNumber,
                'body' => $body,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException("Twilio SMS to {$to} failed: ".$e->getMessage(), previous: $e);
        }

        return [
            'sid' => $message->sid ?? null,
            'status' => $message->status ?? null,
            'to' => $to,
            'body' => $body,
        ];
    }

    public function fromNumber(): string
    {
        return $this->fromNumber ?: '';
    }
}
