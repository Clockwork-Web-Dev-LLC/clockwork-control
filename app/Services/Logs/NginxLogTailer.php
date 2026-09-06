<?php

namespace App\Services\Logs;

use App\Models\NginxLogCursor;
use App\Models\Site;
use App\Models\ThreatLog;
use App\Services\Ssh\SshClient;
use RuntimeException;

class NginxLogTailer
{
    /**
     * Cap how much we read in one pass. Sites that have been silent for days
     * could otherwise dump tens of MB through SSH on first run.
     */
    private const MAX_BYTES_PER_PASS = 2 * 1024 * 1024; // 2 MB

    public function __construct(
        private readonly SshClient $ssh,
        private readonly NginxLogParser $parser,
    ) {}

    /**
     * Pull new bytes since the last cursor, parse them, insert ThreatLog rows,
     * and advance the cursor.
     *
     * @return array{bytes: int, parsed: int, inserted: int, log_path: string}
     */
    public function tail(Site $site): array
    {
        $path = $this->logPath($site);

        $statOut = trim($this->ssh->exec(
            $site->server,
            sprintf('stat -c "%%i %%s" %s 2>/dev/null', escapeshellarg($path))
        ));

        if (! preg_match('/^(\d+)\s+(\d+)/', $statOut, $m)) {
            throw new RuntimeException("Cannot stat log at {$path} on {$site->server->name}.");
        }

        $inode = (int) $m[1];
        $size = (int) $m[2];

        $cursor = NginxLogCursor::firstOrNew([
            'site_id' => $site->id,
            'log_path' => $path,
        ]);

        $startOffset = 0;
        if ($cursor->exists && $cursor->inode === $inode && $cursor->offset <= $size) {
            $startOffset = $cursor->offset;
        }

        $bytesAvailable = $size - $startOffset;

        if ($bytesAvailable <= 0) {
            $cursor->fill([
                'inode' => $inode,
                'offset' => $startOffset,
                'last_read_at' => now(),
            ])->save();

            return ['bytes' => 0, 'parsed' => 0, 'inserted' => 0, 'log_path' => $path];
        }

        // tail -c +N is 1-indexed: +1 = whole file, +K+1 = bytes after byte K.
        $cmd = sprintf('tail -c +%d %s', $startOffset + 1, escapeshellarg($path));
        if ($bytesAvailable > self::MAX_BYTES_PER_PASS) {
            $cmd .= sprintf(' | head -c %d', self::MAX_BYTES_PER_PASS);
        }

        $output = $this->ssh->exec($site->server, $cmd);

        // Trim to the last complete line — the tail may have caught an in-flight write.
        $lastNewline = strrpos($output, "\n");
        if ($lastNewline === false) {
            // No complete line yet. Bump last_read_at and try next pass.
            $cursor->fill([
                'inode' => $inode,
                'offset' => $startOffset,
                'last_read_at' => now(),
            ])->save();

            return ['bytes' => 0, 'parsed' => 0, 'inserted' => 0, 'log_path' => $path];
        }

        $consumed = substr($output, 0, $lastNewline + 1);
        $consumedBytes = strlen($consumed);

        $rows = $this->parser->parse($consumed, $site);

        $inserted = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            if (ThreatLog::insert($chunk)) {
                $inserted += count($chunk);
            }
        }

        $cursor->fill([
            'inode' => $inode,
            'offset' => $startOffset + $consumedBytes,
            'last_read_at' => now(),
        ])->save();

        return [
            'bytes' => $consumedBytes,
            'parsed' => count($rows),
            'inserted' => $inserted,
            'log_path' => $path,
        ];
    }

    public function logPath(Site $site): string
    {
        return $site->nginx_access_log_path ?: '/sites/'.$site->domain.'/logs/access.log';
    }
}
