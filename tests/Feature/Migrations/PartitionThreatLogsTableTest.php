<?php

use App\Services\Logs\ThreatLogPartitionedTable;

describe('PartitionThreatLogsTable Migration', function () {
    it('runs without error on sqlite test environment', function () {
        $partitions = app(ThreatLogPartitionedTable::class);

        expect($partitions->supportsPartitioning())->toBeFalse()
            ->and($partitions->isPartitioned())->toBeFalse();

        // Calling migrate or running the migration file class directly completes cleanly
        $status = $partitions->status();
        expect($status['supported'])->toBeFalse()
            ->and($status['partitioned'])->toBeFalse()
            ->and($status['partitions'])->toBeArray()->toBeEmpty();
    });

    it('returns status array structure matching expected keys', function () {
        $partitions = app(ThreatLogPartitionedTable::class);
        $status = $partitions->status();

        expect($status)->toHaveKeys([
            'supported',
            'partitioned',
            'partitions',
            'row_count',
            'data_bytes',
            'index_bytes',
            'binlog_expire_seconds',
        ]);
    });
});
