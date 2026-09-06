<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\Ssh\SshClient;
use RuntimeException;

class SiteMySqlClient
{
    public function __construct(private readonly SshClient $ssh) {}

    /**
     * Run a SQL query on the site's MySQL database, returning rows as associative arrays.
     *
     * Credentials are sent through a temporary defaults file on the remote server so the
     * password is never visible in `ps -e` and never appears on the command line.
     *
     * @return array<int, array<string, string>>
     */
    public function query(Site $site, string $sql): array
    {
        $output = $this->rawQuery($site, $sql);

        return $this->parseTabular($output);
    }

    /**
     * Run a query that returns no rows (or that we don't care to parse).
     */
    public function execute(Site $site, string $sql): string
    {
        return $this->rawQuery($site, $sql);
    }

    /**
     * Probe whether MySQL is reachable with the stored credentials.
     *
     * @return array{ok: bool, message: string, version?: string}
     */
    public function ping(Site $site): array
    {
        try {
            $rows = $this->query($site, 'SELECT VERSION() AS v');
            $version = $rows[0]['v'] ?? null;

            return [
                'ok' => true,
                'message' => "Connected to MySQL {$version}.",
                'version' => $version,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array<int, string>
     */
    public function listTables(Site $site, ?string $likePrefix = null): array
    {
        $sql = 'SHOW TABLES';
        if ($likePrefix !== null) {
            // Defense-in-depth: reject anything that doesn't look like a valid MySQL
            // identifier prefix before interpolation. Callers pass operator-controlled
            // table_prefix values (extracted from wp-config.php), which are generally
            // safe but are not guaranteed quote-free. SHOW TABLES is read-only so the
            // worst-case attack is read-amplification, but tightening costs nothing.
            if (! preg_match('/^[a-zA-Z0-9_]+$/', $likePrefix)) {
                throw new RuntimeException(
                    'Invalid table prefix for SHOW TABLES LIKE: must match [a-zA-Z0-9_]+. Got: '.substr($likePrefix, 0, 32)
                );
            }
            // SHOW TABLES LIKE expects underscores escaped.
            $escaped = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $likePrefix);
            $sql .= sprintf(" LIKE '%s%%'", $escaped);
        }
        $rows = $this->query($site, $sql);

        return array_map(fn (array $r) => array_values($r)[0] ?? '', $rows);
    }

    private function rawQuery(Site $site, string $sql): string
    {
        $this->guardCredentials($site);

        $defaults = sprintf(
            "[client]\nuser=%s\npassword=%s\nhost=%s\nport=%d\n",
            $site->db_user,
            $site->db_password,
            $site->db_host ?: 'localhost',
            $site->db_port ?: 3306,
        );

        // base64-encode so the credentials transit the SSH command line as opaque bytes.
        $encoded = base64_encode($defaults);

        // Build a remote shell pipeline that:
        //  1. writes the defaults to a 600-mode tempfile
        //  2. runs mysql --defaults-file=$TMP -B -e "$SQL" "$DB"
        //  3. removes the tempfile (trap covers errors)
        // -B = batch (TSV) output, NULLs become "NULL".
        $remoteScript = sprintf(
            'set -e; TMP=$(mktemp); chmod 600 "$TMP"; trap "rm -f $TMP" EXIT; '
            .'echo %s | base64 -d > "$TMP"; '
            .'mysql --defaults-file="$TMP" -B -e %s %s',
            escapeshellarg($encoded),
            escapeshellarg($sql),
            escapeshellarg($site->db_name ?? ''),
        );

        $cmd = sprintf('bash -c %s 2>&1', escapeshellarg($remoteScript));

        $output = $this->ssh->exec($site->server, $cmd);

        // Detect mysql client errors. The CLI prints "ERROR ..." to stderr, which we've
        // merged via 2>&1. Treat any line starting with ERROR as a hard failure.
        foreach (explode("\n", $output) as $line) {
            if (str_starts_with(ltrim($line), 'ERROR')) {
                throw new RuntimeException("MySQL error on {$site->domain}: ".trim($line));
            }
        }

        return $output;
    }

    private function guardCredentials(Site $site): void
    {
        if (! $site->db_name || ! $site->db_user || ! $site->db_password) {
            throw new RuntimeException(
                "Site {$site->domain} is missing DB credentials. Run clockwork:extract-wp-configs first."
            );
        }
    }

    /**
     * Parse mysql -B (tab-separated) output into associative rows.
     *
     * @return array<int, array<string, string>>
     */
    private function parseTabular(string $output): array
    {
        $lines = preg_split('/\r?\n/', trim($output)) ?: [];
        if (count($lines) < 1 || $lines[0] === '') {
            return [];
        }

        $headers = explode("\t", array_shift($lines));
        $rows = [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $values = explode("\t", $line);
            $row = [];
            foreach ($headers as $i => $h) {
                $v = $values[$i] ?? '';
                $row[$h] = $v === 'NULL' ? null : $v;
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
