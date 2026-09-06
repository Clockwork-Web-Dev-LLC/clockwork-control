<?php

namespace Modules\BillCom\Console;

use Illuminate\Console\Command;
use Modules\BillCom\BillComClient;
use Throwable;

/**
 * Verify Bill.com credentials work, then list a sample of customers + items
 * so we can eyeball that the data looks right before running real sync.
 *
 * Modeled after `clockwork:digitalocean-test` and `clockwork:spinupwp-test`.
 */
class BillComTest extends Command
{
    protected $signature = 'clockwork:bill-com-test';

    protected $description = 'Verify Bill.com credentials + list first 5 customers and items.';

    public function handle(BillComClient $client): int
    {
        if (! (bool) config('clockwork.bill_com.enabled')) {
            $this->warn('Bill.com sync is disabled. Set CLOCKWORK_BILL_COM_ENABLED=true to use it.');
            $this->line('(Continuing the test anyway — the disabled flag only gates scheduled sync, not this manual probe.)');
        }

        $this->line('Logging in...');
        try {
            $sessionId = $client->ping();
        } catch (Throwable $e) {
            $this->error("Login failed: {$e->getMessage()}");

            return self::FAILURE;
        }
        $this->info('Login OK. Session: '.substr($sessionId, 0, 8).'…');

        $this->line('');
        $this->line('First 5 customers:');
        $count = 0;
        foreach ($client->customers() as $row) {
            $this->line(sprintf(
                '  %s — %s <%s>',
                (string) ($row['id'] ?? '?'),
                (string) ($row['name'] ?? '(no name)'),
                (string) ($row['email'] ?? '')
            ));
            if (++$count >= 5) {
                break;
            }
        }
        if ($count === 0) {
            $this->warn('  (no customers returned)');
        }

        $this->line('');
        $this->line('First 5 items:');
        $count = 0;
        foreach ($client->items() as $row) {
            $this->line(sprintf(
                '  %s — %s',
                (string) ($row['id'] ?? '?'),
                (string) ($row['name'] ?? '(no name)')
            ));
            if (++$count >= 5) {
                break;
            }
        }
        if ($count === 0) {
            $this->warn('  (no items returned)');
        }

        return self::SUCCESS;
    }
}
