<?php

namespace Tests\Unit\Modules;

use Modules\DigitalOcean\DigitalOceanMetricsParser;
use PHPUnit\Framework\TestCase;

class DigitalOceanMetricsParserTest extends TestCase
{
    private DigitalOceanMetricsParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new DigitalOceanMetricsParser;
    }

    public function test_percent_cpu_used_in_steady_state(): void
    {
        $payload = [
            'result' => [
                [
                    'metric' => ['mode' => 'idle'],
                    'values' => [
                        [1000, '5000'],
                        [1060, '5900'],
                    ],
                ],
                [
                    'metric' => ['mode' => 'user'],
                    'values' => [
                        [1000, '1000'],
                        [1060, '1100'],
                    ],
                ],
            ],
        ];

        // idle delta = 900, user delta = 100, total delta = 1000
        // (1 - 900/1000) * 100 = 10.0%
        $cpu = $this->parser->percentCpuUsed($payload);

        $this->assertNotNull($cpu);
        $this->assertEqualsWithDelta(10.0, $cpu, 0.001);
    }

    public function test_percent_cpu_used_handles_mid_window_reboot_counter_reset(): void
    {
        // Simulate a reboot at t = 1030 where counters reset back near 0
        $payload = [
            'result' => [
                [
                    'metric' => ['mode' => 'idle'],
                    'values' => [
                        [1000, '500000'],
                        [1010, '500500'],
                        [1020, '501000'],
                        // Reboot occurred: counter resets to 100
                        [1030, '100'],
                        [1040, '550'],
                        [1050, '1000'],
                    ],
                ],
                [
                    'metric' => ['mode' => 'user'],
                    'values' => [
                        [1000, '200000'],
                        [1010, '200100'],
                        [1020, '200200'],
                        // Reboot occurred: counter resets to 20
                        [1030, '20'],
                        [1040, '120'],
                        [1050, '220'],
                    ],
                ],
            ],
        ];

        // Post-reboot window: t=1030 to t=1050
        // idle: 1000 - 100 = 900
        // user: 220 - 20 = 200
        // total delta = 1100
        // cpu = (1 - 900 / 1100) * 100 = 18.1818%
        $cpu = $this->parser->percentCpuUsed($payload);

        $this->assertNotNull($cpu);
        $this->assertEqualsWithDelta(18.1818, $cpu, 0.001);
    }

    public function test_percent_cpu_used_returns_null_when_insufficient_samples_post_reboot(): void
    {
        // Reboot happened at the very last sample (only 1 post-reboot point)
        $payload = [
            'result' => [
                [
                    'metric' => ['mode' => 'idle'],
                    'values' => [
                        [1000, '500000'],
                        [1010, '500500'],
                        [1020, '100'], // Reset, only 1 sample
                    ],
                ],
                [
                    'metric' => ['mode' => 'user'],
                    'values' => [
                        [1000, '200000'],
                        [1010, '200100'],
                        [1020, '20'],
                    ],
                ],
            ],
        ];

        $cpu = $this->parser->percentCpuUsed($payload);

        $this->assertNull($cpu);
    }

    public function test_percent_cpu_used_returns_null_on_empty_payload(): void
    {
        $this->assertNull($this->parser->percentCpuUsed([]));
        $this->assertNull($this->parser->percentCpuUsed(['result' => []]));
    }

    public function test_latest_single_value_extracts_last_sample(): void
    {
        $payload = [
            'result' => [
                [
                    'values' => [
                        [1000, '42.5'],
                        [1060, '88.3'],
                    ],
                ],
            ],
        ];

        $this->assertEquals(88.3, $this->parser->latestSingleValue($payload));
    }

    public function test_percent_used_computes_ratio(): void
    {
        $free = [
            'result' => [
                ['values' => [[1000, '200']]],
            ],
        ];
        $total = [
            'result' => [
                ['values' => [[1000, '1000']]],
            ],
        ];

        // (1 - 200/1000) * 100 = 80.0%
        $pct = $this->parser->percentUsed($free, $total);
        $this->assertEquals(80.0, $pct);
    }
}
