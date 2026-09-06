<?php

namespace Modules\Core\Contracts;

use App\Models\Server;

interface CloudProvider
{
    public function id(): string;

    public function label(): string;

    public function isConfigured(): bool;

    public function instanceNoun(): string;

    public function iconClass(): string;

    public function iconColor(): ?string;

    public function sizeTier(?string $sizeSlug): ?string;

    /**
     * @return array{cpu_pct: ?float, memory_pct: ?float, disk_pct: ?float, load_1: ?float}
     */
    public function metrics(Server $server, int $start, int $end): array;

    /**
     * @return array<int, string>|null
     */
    public function aliveProviderIds(): ?array;

    public function isDeletedAtProvider(Server $server, ?array $aliveIds): bool;

    /**
     * @return array<string, array{id: string, size_slug: ?string, vcpus: ?int, memory_mb: ?int, disk_gb: ?int}>
     */
    public function instancesByIp(): array;
}
