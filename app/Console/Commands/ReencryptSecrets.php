<?php

namespace App\Console\Commands;

use App\Models\IntegrationCredential;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Re-encrypts every 'encrypted'-cast column under the CURRENT APP_KEY.
 * Meant to run once, immediately after rotating APP_KEY with the old key
 * still present in APP_PREVIOUS_KEYS — read decrypts via the previous-key
 * fallback, write re-encrypts under the new primary key.
 *
 * Deliberately does NOT use $model->save(): Eloquent's dirty-tracking
 * compares decrypted values, so re-assigning the same plaintext to an
 * 'encrypted'-cast attribute never marks it dirty and save() silently
 * no-ops. Every column here is instead re-encrypted with a direct
 * Crypt::encryptString() + query-builder update, bypassing the cast/dirty
 * check entirely so this can't silently do nothing.
 */
#[Signature('clockwork:reencrypt-secrets')]
#[Description('Re-encrypt every encrypted-cast column under the current APP_KEY. Run once, immediately after rotating APP_KEY, with the old key still in APP_PREVIOUS_KEYS.')]
class ReencryptSecrets extends Command
{
    public function handle(): int
    {
        $targets = [
            ['model' => Server::class, 'table' => 'servers', 'column' => 'ssh_password'],
            ['model' => Server::class, 'table' => 'servers', 'column' => 'ssh_private_key'],
            ['model' => Site::class, 'table' => 'sites', 'column' => 'db_password'],
            ['model' => Site::class, 'table' => 'sites', 'column' => 'companion_secret'],
            ['model' => IntegrationCredential::class, 'table' => 'integration_credentials', 'column' => 'value'],
        ];

        DB::beginTransaction();

        try {
            foreach ($targets as $target) {
                $count = $this->reencryptColumn($target['model'], $target['table'], $target['column']);
                $this->info("{$target['table']}.{$target['column']}: re-encrypted {$count} row(s).");
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("Re-encryption failed, rolled back everything: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Done. Verify decryption works end to end BEFORE removing APP_PREVIOUS_KEYS from .env.');

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function reencryptColumn(string $modelClass, string $table, string $column): int
    {
        $count = 0;

        /** @var Model $row */
        foreach ($modelClass::query()->whereNotNull($column)->get() as $row) {
            // Reading the attribute decrypts it — under the current key if
            // it's already current, or via the APP_PREVIOUS_KEYS fallback
            // if it's still under the old one. Either way we get plaintext.
            $plain = $row->getAttribute($column);
            if ($plain === null || $plain === '') {
                continue;
            }

            DB::table($table)
                ->where('id', $row->getKey())
                ->update([$column => Crypt::encryptString($plain)]);

            $count++;
        }

        return $count;
    }
}
