<?php

namespace Modules\Core;

/**
 * A module's self-description. Feeds the /settings/integrations page (Phase
 * 1's IntegrationCredentialsController merges module-contributed credential
 * fields alongside its own hardcoded core integrations) and, from Phase 8
 * onward, the setup checklist page.
 */
final readonly class ModuleManifest
{
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_LOOKING_FOR_TESTERS = 'looking_for_testers';

    /**
     * @param  array<string, array{label: string, secret: bool}>  $credentialFields
     *                                                                               Keyed by the field name under this module's id in
     *                                                                               integration_credentials (e.g. 'token', 'client_secret').
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public array $credentialFields = [],
        public string $status = self::STATUS_VERIFIED,
        public ?string $statusNote = null,
    ) {}

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    public function isLookingForTesters(): bool
    {
        return $this->status === self::STATUS_LOOKING_FOR_TESTERS;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_LOOKING_FOR_TESTERS => 'Looking for Testers',
            default => 'Verified in Production',
        };
    }
}
