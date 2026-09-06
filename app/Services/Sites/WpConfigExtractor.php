<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\Ssh\SshClient;
use RuntimeException;

class WpConfigExtractor
{
    public function __construct(private readonly SshClient $ssh) {}

    /**
     * Read wp-config.php from the site over SSH and parse out DB credentials.
     *
     * @return array{db_name: ?string, db_user: ?string, db_password: ?string, db_host: string, db_port: int, table_prefix: ?string}
     */
    public function extract(Site $site): array
    {
        $path = $this->wpConfigPath($site);
        $escaped = escapeshellarg($path);

        // Try a plain read first (works for 0644 files, the SpinupWP default).
        $contents = $this->ssh->exec($site->server, "cat {$escaped} 2>/dev/null");

        if ($contents === '' && $site->server->ssh_password) {
            // Some sites have wp-config.php at mode 0600 owned by the site user — only sudo can read.
            // Pass the password via env-var, never on the command line.
            $script = "printf '%s\\n' \"\$CW_SUDO_PW\" | sudo -S cat {$escaped} 2>/dev/null";
            $cmd = sprintf(
                'CW_SUDO_PW=%s bash -c %s',
                escapeshellarg((string) $site->server->ssh_password),
                escapeshellarg($script),
            );
            $contents = $this->ssh->exec($site->server, $cmd);
        }

        if ($contents === '') {
            throw new RuntimeException("wp-config.php not found or unreadable at {$path}");
        }

        return $this->parse($contents);
    }

    public function extractAndStore(Site $site): array
    {
        $parsed = $this->extract($site);

        $site->wp_path = $site->wp_path ?: $this->wpDirPath($site);
        $site->db_name = $parsed['db_name'];
        $site->db_user = $parsed['db_user'];
        $site->db_password = $parsed['db_password'];
        $site->db_host = $parsed['db_host'];
        $site->db_port = $parsed['db_port'];
        if ($parsed['table_prefix'] !== null && $parsed['table_prefix'] !== '') {
            $site->table_prefix = $parsed['table_prefix'];
        }
        $site->save();

        return $parsed;
    }

    /**
     * @return array{db_name: ?string, db_user: ?string, db_password: ?string, db_host: string, db_port: int, table_prefix: ?string}
     */
    public function parse(string $contents): array
    {
        $result = [
            'db_name' => null,
            'db_user' => null,
            'db_password' => null,
            'db_host' => 'localhost',
            'db_port' => 3306,
            'table_prefix' => null,
        ];

        $defines = [
            'DB_NAME' => 'db_name',
            'DB_USER' => 'db_user',
            'DB_PASSWORD' => 'db_password',
            'DB_HOST' => 'db_host',
        ];

        foreach ($defines as $constant => $key) {
            $pattern = '/define\s*\(\s*[\'"]'.preg_quote($constant, '/').'[\'"]\s*,\s*[\'"](.*?)[\'"]\s*\)/s';
            if (preg_match($pattern, $contents, $m)) {
                $result[$key] = $m[1];
            }
        }

        if (preg_match('/\$table_prefix\s*=\s*[\'"](.*?)[\'"]/', $contents, $m)) {
            $result['table_prefix'] = $m[1];
        }

        // DB_HOST may be "host:port" or "host:/path/to/socket" — strip non-numeric ports.
        if (is_string($result['db_host']) && str_contains($result['db_host'], ':')) {
            [$host, $portOrSocket] = explode(':', $result['db_host'], 2);
            $result['db_host'] = $host !== '' ? $host : 'localhost';
            if (ctype_digit($portOrSocket)) {
                $result['db_port'] = (int) $portOrSocket;
            }
        }

        return $result;
    }

    public function wpConfigPath(Site $site): string
    {
        return rtrim($this->wpDirPath($site), '/').'/wp-config.php';
    }

    private function wpDirPath(Site $site): string
    {
        return $site->wp_path ?: '/sites/'.$site->domain.'/files';
    }
}
