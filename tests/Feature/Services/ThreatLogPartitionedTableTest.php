<?php

use App\Services\Logs\ThreatLogPartitionedTable;

describe('ThreatLogPartitionedTable', function () {
    it('builds monthly UNIX_TIMESTAMP RANGE partitions covering the window plus pmax', function () {
        $sql = app(ThreatLogPartitionedTable::class)->createTableSql(
            'threat_logs_rebuilt',
            now()->startOfMonth()->subMonths(1),
            now()->startOfMonth()->addMonth(),
        )[0];

        expect($sql)->toContain('PARTITION BY RANGE (UNIX_TIMESTAMP(`event_at`))')
            ->and($sql)->toContain('PRIMARY KEY (`id`, `event_at`)')
            ->and($sql)->toContain('PARTITION pmax VALUES LESS THAN MAXVALUE')
            ->and($sql)->not->toContain('FOREIGN KEY');
    });

    it('returns empty/unsupported status when connection is not mysql', function () {
        $status = app(ThreatLogPartitionedTable::class)->status();

        expect($status)->toBeArray()
            ->and($status['supported'])->toBeFalse()
            ->and($status['partitioned'])->toBeFalse()
            ->and($status['partitions'])->toBeEmpty();
    });
});
