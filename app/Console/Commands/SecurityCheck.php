<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Fail2ban\IgnoreIpListBuilder;
use App\Services\Ssh\SshClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Asserts the invariants Clockwork's security model depends on. Runs locally,
 * fast (sub-second) on the default check set; the optional --ssh flag adds a
 * fleet-wide ignoreip drift verification that opens 33 SSH sessions and takes
 * ~30s.
 *
 * Each check returns one of three severities:
 *   ok    — invariant verified
 *   warn  — soft signal worth eyeballing, not necessarily wrong
 *   fail  — invariant violated, needs attention
 *
 * Exit code is 0 if no fails, 1 if any fail. Schedule it weekly so a regression
 * surfaces without you having to remember to run it.
 */
class SecurityCheck extends Command
{
    protected $signature = 'clockwork:security-check
        {--ssh : Include the SSH-heavy ignoreip drift check across all provisioned servers (~30s)}
        {--quiet-ok : Only print warn/fail rows; suppress the green ok rows}';

    protected $description = 'Run the suite of local security invariant checks.';

    /** @var array<int, array{name: string, severity: string, message: string, detail: ?string}> */
    private array $results = [];

    public function handle(SshClient $ssh, IgnoreIpListBuilder $ignoreIpList): int
    {
        $this->info('Running security checks…');
        $this->line('');

        $this->checkServeBind();
        $this->checkEnvInGitignore();
        $this->checkEnvNotInGit();
        $this->checkSitesDbPasswordCiphertext();
        $this->checkServersSshPasswordCiphertext();
        $this->checkServersSshPrivateKeyCiphertext();
        $this->checkLogCredentialLeak();
        $this->checkCacheCredentialLeak();

        if ($this->option('ssh')) {
            $this->checkIgnoreipDrift($ssh, $ignoreIpList);
        } else {
            $this->record('fail2ban_ignoreip_drift', 'info', 'Skipped (pass --ssh to include — ~30s SSH-heavy check).');
        }

        return $this->renderReport();
    }

    // ----------------------------------------------------------------------
    // Checks
    // ----------------------------------------------------------------------

    private function checkServeBind(): void
    {
        // Look for any listening php artisan serve process on 0.0.0.0 instead of
        // 127.0.0.1. Local-LAN-only is part of the security posture; we'd never
        // intentionally bind to all interfaces.
        $output = (string) shell_exec("ps aux 2>/dev/null | grep 'artisan serve' | grep -v grep");
        if ($output === '') {
            $this->record('serve_bind', 'info', 'No `php artisan serve` running.');

            return;
        }
        $bad = preg_match('/--host[= ]0\.0\.0\.0|--host[= ]\*/', $output);
        if ($bad) {
            $this->record('serve_bind', 'fail', '`php artisan serve` is bound to a non-loopback interface.', $output);

            return;
        }
        $this->record('serve_bind', 'ok', 'Dev server bound to loopback only.');
    }

    private function checkEnvInGitignore(): void
    {
        $gitignore = base_path('.gitignore');
        if (! is_readable($gitignore)) {
            $this->record('env_in_gitignore', 'warn', 'No .gitignore found at project root.');

            return;
        }
        $contents = (string) file_get_contents($gitignore);
        if (preg_match('/^\.env(\.|$)/m', $contents) || str_contains($contents, "\n.env\n") || str_starts_with($contents, '.env')) {
            $this->record('env_in_gitignore', 'ok', '.env is in .gitignore.');

            return;
        }
        $this->record('env_in_gitignore', 'fail', '.env is NOT in .gitignore — could be committed by accident.');
    }

    private function checkEnvNotInGit(): void
    {
        $cmd = 'git -C '.escapeshellarg(base_path()).' ls-files --error-unmatch .env 2>&1';
        $output = (string) shell_exec($cmd);
        // ls-files --error-unmatch returns non-zero (and "did not match" message) if not tracked.
        if (str_contains($output, 'did not match') || trim($output) === '') {
            $this->record('env_not_in_git', 'ok', '.env is not tracked by git.');

            return;
        }
        $this->record('env_not_in_git', 'fail', '.env IS tracked by git — secrets in commit history.', trim($output));
    }

    private function checkSitesDbPasswordCiphertext(): void
    {
        $rows = DB::table('sites')
            ->whereNotNull('db_password')
            ->where('db_password', '!=', '')
            ->select(['id', 'domain', 'db_password'])
            ->limit(20)
            ->get();

        if ($rows->isEmpty()) {
            $this->record('sites_db_password_ciphertext', 'info', 'No sites with stored db_password to check.');

            return;
        }

        $bad = [];
        foreach ($rows as $row) {
            // Laravel's encrypted cast wraps in a base64-encoded JSON payload that
            // starts with "eyJ" (base64 of '{"'). A plaintext password almost
            // certainly wouldn't.
            if (! str_starts_with((string) $row->db_password, 'eyJ')) {
                $bad[] = "site #{$row->id} ({$row->domain}) — db_password doesn't look encrypted";

                continue;
            }
            // Round-trip: decrypt to confirm it's not a corrupted ciphertext.
            try {
                Crypt::decryptString($row->db_password);
            } catch (\Throwable $e) {
                $bad[] = "site #{$row->id} ({$row->domain}) — decrypt failed: ".$e->getMessage();
            }
        }

        if ($bad) {
            $this->record('sites_db_password_ciphertext', 'fail',
                count($bad)." of {$rows->count()} sampled sites have invalid db_password ciphertext.",
                implode("\n", array_slice($bad, 0, 5)),
            );

            return;
        }
        $this->record('sites_db_password_ciphertext', 'ok',
            "All {$rows->count()} sampled sites have valid db_password ciphertext.",
        );
    }

    private function checkServersSshPasswordCiphertext(): void
    {
        $this->checkServerColumnCiphertext('ssh_password');
    }

    private function checkServersSshPrivateKeyCiphertext(): void
    {
        $this->checkServerColumnCiphertext('ssh_private_key');
    }

    private function checkServerColumnCiphertext(string $col): void
    {
        $rows = DB::table('servers')
            ->whereNotNull($col)
            ->where($col, '!=', '')
            ->select(['id', 'name', $col])
            ->limit(40)
            ->get();

        $checkName = "servers_{$col}_ciphertext";

        if ($rows->isEmpty()) {
            $this->record($checkName, 'info', "No servers with stored {$col} to check.");

            return;
        }

        $bad = [];
        foreach ($rows as $row) {
            $value = (string) $row->{$col};
            if (! str_starts_with($value, 'eyJ')) {
                $bad[] = "server #{$row->id} ({$row->name}) — {$col} doesn't look encrypted";

                continue;
            }
            try {
                Crypt::decryptString($value);
            } catch (\Throwable $e) {
                $bad[] = "server #{$row->id} ({$row->name}) — decrypt failed: ".$e->getMessage();
            }
        }

        if ($bad) {
            $this->record($checkName, 'fail',
                count($bad)." of {$rows->count()} sampled servers have invalid {$col} ciphertext.",
                implode("\n", array_slice($bad, 0, 5)),
            );

            return;
        }
        $this->record($checkName, 'ok',
            "All {$rows->count()} sampled servers have valid {$col} ciphertext.",
        );
    }

    private function checkLogCredentialLeak(): void
    {
        $logFile = storage_path('logs/laravel.log');
        if (! is_readable($logFile)) {
            $this->record('log_credential_leak', 'info', 'No laravel.log to scan.');

            return;
        }

        // Sample only the tail (10 MB) so we don't OOM on huge logs. Recent leaks
        // are what matter most; ancient log lines aren't going to be re-leaked.
        $size = (int) filesize($logFile);
        $sampleSize = min($size, 10 * 1024 * 1024);
        $fp = fopen($logFile, 'rb');
        if (! $fp) {
            $this->record('log_credential_leak', 'warn', 'Could not open laravel.log to scan.');

            return;
        }
        fseek($fp, max(0, $size - $sampleSize));
        $tail = (string) fread($fp, $sampleSize);
        fclose($fp);

        // Patterns that suggest a decrypted credential leaked through. NOT exhaustive
        // — defense-in-depth, not a contract.
        $patterns = [
            '/define\s*\(\s*[\'"]DB_PASSWORD[\'"][^,]*,\s*[\'"](?!\s*\)|\$)\S+/' => 'DB_PASSWORD literal in log line',
            '/-----BEGIN\s+(RSA|OPENSSH|EC)\s+PRIVATE\s+KEY-----/' => 'SSH private key block in log',
            '/ssh_password["\']?\s*=>?\s*["\'][^"\']{8,}/' => 'ssh_password key/value with non-ciphertext value',
        ];

        $hits = [];
        foreach ($patterns as $regex => $label) {
            if (preg_match($regex, $tail, $m)) {
                $hits[] = $label.' — sample: '.substr($m[0], 0, 80);
            }
        }

        if ($hits) {
            $this->record('log_credential_leak', 'fail',
                'Possible credential exposure in laravel.log (last 10MB).',
                implode("\n", $hits),
            );

            return;
        }
        $this->record('log_credential_leak', 'ok', 'No credential-leak patterns found in the last 10MB of laravel.log.');
    }

    private function checkCacheCredentialLeak(): void
    {
        // The database cache driver stores values as serialized blobs in the `cache`
        // table. Scan the weird_stats:* keys (the only large cached payloads we
        // produce) for credential-like substrings.
        if (! DB::getSchemaBuilder()->hasTable('cache')) {
            $this->record('cache_credential_leak', 'info', 'No `cache` table (driver may not be database).');

            return;
        }
        $rows = DB::table('cache')
            ->where('key', 'like', '%weird_stats:%')
            ->select(['key', 'value'])
            ->get();

        if ($rows->isEmpty()) {
            $this->record('cache_credential_leak', 'info', 'No weird_stats:* cache entries to scan.');

            return;
        }

        $hits = [];
        foreach ($rows as $row) {
            $value = (string) $row->value;
            if (preg_match('/-----BEGIN\s+(RSA|OPENSSH|EC)\s+PRIVATE\s+KEY-----/', $value)) {
                $hits[] = "{$row->key}: SSH private key block";
            }
            // Look for the literal name field used in our DB credential blobs.
            if (preg_match('/\bDB_PASSWORD\b/', $value)) {
                $hits[] = "{$row->key}: DB_PASSWORD literal";
            }
        }

        if ($hits) {
            $this->record('cache_credential_leak', 'fail',
                'Possible credential exposure in cache.', implode("\n", $hits));

            return;
        }
        $this->record('cache_credential_leak', 'ok',
            "Scanned {$rows->count()} weird_stats:* cache entries — no credential-like content.");
    }

    private function checkIgnoreipDrift(SshClient $ssh, IgnoreIpListBuilder $ignoreIpList): void
    {
        $expectedRaw = $ignoreIpList->build();
        $expected = $this->canonicalizeList($expectedRaw);

        $servers = Server::query()
            ->monitored()
            ->whereNotNull('clockwork_jail_provisioned_at')
            ->orderBy('name')
            ->get();

        if ($servers->isEmpty()) {
            $this->record('fail2ban_ignoreip_drift', 'info', 'No provisioned servers to check.');

            return;
        }

        $bad = [];
        $checked = 0;
        foreach ($servers as $server) {
            try {
                $output = $ssh->exec(
                    $server,
                    'sudo -n fail2ban-client get clockwork ignoreip 2>&1 || echo SUDO_FAIL',
                );
                if (str_contains($output, 'SUDO_FAIL')) {
                    $bad[] = "{$server->name}: sudo refused (NOPASSWD on fail2ban-client may not be configured)";

                    continue;
                }
                $remoteList = $this->canonicalizeList($this->parseFail2banIgnoreipOutput($output));
                $missing = array_diff($expected, $remoteList);
                $extra = array_diff($remoteList, $expected);
                if ($missing || $extra) {
                    $sample = '';
                    if ($missing) {
                        $sample .= ' missing: '.implode(',', array_slice($missing, 0, 3));
                    }
                    if ($extra) {
                        $sample .= ' extra: '.implode(',', array_slice($extra, 0, 3));
                    }
                    $bad[] = "{$server->name}: drift — missing=".count($missing).', extra='.count($extra).$sample;
                }
                $checked++;
            } catch (\Throwable $e) {
                $bad[] = "{$server->name}: SSH failed — ".$e->getMessage();
            }
        }

        if ($bad) {
            $this->record('fail2ban_ignoreip_drift', 'fail',
                count($bad).' of '.$servers->count().' provisioned servers have ignoreip drift.',
                implode("\n", array_slice($bad, 0, 10)),
            );

            return;
        }

        $this->record('fail2ban_ignoreip_drift', 'ok',
            "All {$checked} provisioned servers have current ignoreip. No drift.");
    }

    /**
     * Parse fail2ban-client get clockwork ignoreip output into a list of CIDRs/IPs.
     * The output looks like:
     *
     *   These IP addresses/networks are ignored:
     *   |- 127.0.0.0/8
     *   |- ::1
     *   `- 203.0.113.10
     *
     * Strip the tree characters (|- and `-) and the header line, return just the
     * IP/CIDR strings.
     *
     * @return array<int, string>
     */
    private function parseFail2banIgnoreipOutput(string $output): array
    {
        $entries = [];
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_contains($line, 'are ignored')) {
                continue;
            }
            // Strip "|-" or "`-" tree prefixes plus surrounding whitespace.
            $line = preg_replace('/^[`|]\s*-\s*/', '', $line);
            $line = trim((string) $line);
            if ($line !== '') {
                $entries[] = $line;
            }
        }

        return $entries;
    }

    /**
     * Normalize a list of IPs/CIDRs so two representations of the same network
     * compare equal. fail2ban canonicalizes 127.0.0.1/8 → 127.0.0.0/8 (zeros
     * the host bits per the prefix length), so we apply the same canonicalization
     * to the expected list before diffing.
     *
     * @param  array<int, string>  $list
     * @return array<int, string> canonicalized + sorted
     */
    private function canonicalizeList(array $list): array
    {
        $out = [];
        foreach ($list as $entry) {
            $out[] = $this->canonicalizeCidr(trim($entry));
        }
        sort($out);

        return array_values(array_unique($out));
    }

    private function canonicalizeCidr(string $entry): string
    {
        if (! str_contains($entry, '/')) {
            return $entry; // bare IP — fail2ban doesn't transform these
        }
        [$addr, $prefix] = explode('/', $entry, 2);
        $prefix = (int) $prefix;

        $isV4 = (bool) filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        if ($isV4) {
            // Zero the host bits.
            $ipL = ip2long($addr);
            if ($ipL === false || $prefix < 0 || $prefix > 32) {
                return $entry;
            }
            $maskL = $prefix === 0 ? 0 : (-1 << (32 - $prefix));
            $networkL = $ipL & $maskL & 0xFFFFFFFF;

            return long2ip($networkL).'/'.$prefix;
        }

        $bin = @inet_pton($addr);
        if ($bin === false || strlen($bin) !== 16 || $prefix < 0 || $prefix > 128) {
            return $entry;
        }
        $bytes = intdiv($prefix, 8);
        $remBits = $prefix % 8;
        $masked = substr($bin, 0, $bytes);
        if ($remBits > 0 && $bytes < 16) {
            $maskByte = chr((0xFF << (8 - $remBits)) & 0xFF);
            $masked .= chr(ord($bin[$bytes]) & ord($maskByte));
            $bytes++;
        }
        $masked .= str_repeat("\x00", 16 - strlen($masked));

        return inet_ntop($masked).'/'.$prefix;
    }

    // ----------------------------------------------------------------------
    // Reporting
    // ----------------------------------------------------------------------

    private function record(string $name, string $severity, string $message, ?string $detail = null): void
    {
        $this->results[] = compact('name', 'severity', 'message', 'detail');
    }

    private function renderReport(): int
    {
        $quietOk = (bool) $this->option('quiet-ok');

        $rows = [];
        foreach ($this->results as $r) {
            if ($quietOk && $r['severity'] === 'ok') {
                continue;
            }
            $icon = match ($r['severity']) {
                'ok' => '<fg=green>✓</>',
                'warn' => '<fg=yellow>!</>',
                'fail' => '<fg=red>✗</>',
                default => '·',
            };
            $rows[] = [$icon, $r['name'], $r['message']];
        }
        $this->table(['', 'Check', 'Result'], $rows);

        $fails = collect($this->results)->where('severity', 'fail');
        $warns = collect($this->results)->where('severity', 'warn');

        foreach ($fails as $f) {
            $this->error("FAIL · {$f['name']}: {$f['message']}");
            if ($f['detail']) {
                foreach (explode("\n", $f['detail']) as $line) {
                    $this->line('       '.$line);
                }
            }
        }
        foreach ($warns as $w) {
            $this->warn("WARN · {$w['name']}: {$w['message']}");
        }

        $this->line('');
        $totalChecks = count($this->results);
        $this->info(sprintf(
            'Summary: %d ok, %d warn, %d fail (of %d total).',
            collect($this->results)->where('severity', 'ok')->count(),
            $warns->count(),
            $fails->count(),
            $totalChecks,
        ));

        return $fails->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
