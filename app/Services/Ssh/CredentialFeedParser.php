<?php

namespace App\Services\Ssh;

class CredentialFeedParser
{
    /**
     * Parse a text feed of the form:
     *
     *     srv01.example.com
     *     198.51.100.50
     *     ssh deploy@198.51.100.50
     *     <password>
     *     ----------------------- (3+ dashes, optional spaces)
     *     srv02.example.com
     *     ...
     *
     * Returns one parsed entry per block. Blocks shorter than 4 non-empty lines
     * are skipped. The password line is taken VERBATIM (no trim) so
     * passwords with internal whitespace survive intact.
     *
     * @return array<int, array{
     *     name: string,
     *     ip: string,
     *     user: string,
     *     port: int,
     *     password: string,
     *     raw_index: int
     * }>
     */
    public function parse(string $feed): array
    {
        $blocks = preg_split('/^-{3,}\s*$/m', $feed) ?: [];
        $entries = [];

        foreach ($blocks as $index => $block) {
            $rawLines = preg_split("/\r\n|\n|\r/", $block) ?: [];
            $lines = [];
            foreach ($rawLines as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $lines[] = $line;
            }

            if (count($lines) < 4) {
                continue;
            }

            // Lines 1 & 2 may carry trailing descriptions (e.g. "web-test5.example.com - Dedicated Server for client-i.example").
            // Hostnames and IPs never contain whitespace, so the first whitespace-separated token is the canonical value.
            $name = $this->firstToken($lines[0]);
            $ip = $this->firstToken($lines[1]);
            $sshLine = trim($lines[2]);
            $password = $lines[3]; // verbatim — passwords may contain trailing chars

            if (! preg_match('/^ssh\s+(?:-p\s+(\d+)\s+)?([^@\s]+)@(\S+)/', $sshLine, $m)) {
                continue;
            }

            $port = isset($m[1]) && $m[1] !== '' ? (int) $m[1] : 22;
            $user = $m[2];
            // we don't override $ip from $m[3] — line 2 is canonical

            $entries[] = [
                'name' => $name,
                'ip' => $ip,
                'user' => $user,
                'port' => $port,
                'password' => $password,
                'raw_index' => $index,
            ];
        }

        return $entries;
    }

    protected function firstToken(string $line): string
    {
        $trimmed = trim($line);
        $parts = preg_split('/\s+/', $trimmed, 2) ?: [];

        return $parts[0] ?? $trimmed;
    }
}
