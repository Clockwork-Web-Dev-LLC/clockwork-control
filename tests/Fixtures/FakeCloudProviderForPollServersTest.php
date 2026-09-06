<?php

namespace Tests\Fixtures;

use App\Models\Server;
use Modules\Core\Contracts\CloudProvider;
use RuntimeException;

class FakeCloudProviderForPollServersTest implements CloudProvider
{
    public function __construct(
        private readonly string $providerId,
        private readonly array $metricsOrException,
    ) {}

    public function id(): string
    {
        return $this->providerId;
    }

    public function label(): string
    {
        return 'Fake provider';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function instanceNoun(): string
    {
        return 'instance';
    }

    public function iconClass(): string
    {
        return 'fa-solid fa-circle-question';
    }

    public function iconColor(): ?string
    {
        return null;
    }

    public function sizeTier(?string $sizeSlug): ?string
    {
        return $sizeSlug;
    }

    public function metrics(Server $server, int $start, int $end): array
    {
        if (isset($this->metricsOrException['throw'])) {
            throw new RuntimeException($this->metricsOrException['throw']);
        }

        return $this->metricsOrException;
    }

    public function aliveProviderIds(): ?array
    {
        return null;
    }

    public function isDeletedAtProvider(Server $server, ?array $aliveIds): bool
    {
        return false;
    }

    public function instancesByIp(): array
    {
        return [];
    }
}
