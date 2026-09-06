<?php

namespace App\Console\Commands;

use App\Services\DigitalOcean\SpacesClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Quick credential-validation command for DigitalOcean Spaces. Mirrors
 * `clockwork:digitalocean-test` for the Droplets API — drops in the key
 * + secret, runs this, sees a list of objects, knows it works.
 *
 * Run after dropping CLOCKWORK_DO_SPACES_KEY and CLOCKWORK_DO_SPACES_SECRET
 * into .env. Reports configured/connected state + a sample of keys at the
 * bucket root, so you can also confirm the bucket layout matches what the
 * SpacesClient parser expects.
 */
#[Signature('clockwork:do-spaces-test')]
#[Description('Verify the DigitalOcean Spaces credentials by listing a few keys from the configured bucket.')]
class DoSpacesTest extends Command
{
    public function handle(SpacesClient $client): int
    {
        $this->info('Bucket:  '.$client->bucket());
        $this->info('Region:  '.$client->region());
        $this->newLine();

        if (! $client->isConfigured()) {
            $this->error('CLOCKWORK_DO_SPACES_KEY / CLOCKWORK_DO_SPACES_SECRET not set in .env.');
            $this->line('See `config/clockwork.php` → `do_spaces` for all the available env keys.');

            return self::FAILURE;
        }

        $result = $client->smokeTest();

        if (! $result['ok']) {
            $this->error('Spaces smoke test failed: '.($result['message'] ?? 'unknown error'));

            return self::FAILURE;
        }

        $this->info('Authenticated and bucket reachable.');
        if ($result['sample_keys'] === []) {
            $this->line('Bucket appears empty (or all top-level entries are empty prefixes).');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('First few keys at bucket root (use these to confirm SpinupWP filename layout):');
        foreach ($result['sample_keys'] as $key) {
            $this->line('  '.$key);
        }

        return self::SUCCESS;
    }
}
