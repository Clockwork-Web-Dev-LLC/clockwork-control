<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerUpdateSnapshot;
use App\Services\Ssh\SshClient;

/**
 * Cheap apt-update visibility probe.
 *
 * Reads three signals via a single SSH session:
 *   1. `/usr/lib/update-notifier/apt-check 2>&1` → "total;security"
 *      Provided by update-notifier-common (default on every Ubuntu LTS we run).
 *      No sudo needed; reads the apt cache that the apt-daily.timer keeps warm.
 *   2. `test -f /var/run/reboot-required` → reboot-pending flag.
 *   3. `cat /var/run/reboot-required.pkgs` → which packages forced the flag.
 *
 * Commands are joined with markers so we get a single round-trip per host.
 * The probe NEVER runs `apt-get update` — that would race with the system's
 * own apt-daily timer and add measurable load to the box. We rely on the
 * cache being fresh enough; in practice it is, because Ubuntu refreshes it
 * automatically every ~24h.
 */
class AptUpdateProbe
{
    private const APT_CHECK_BIN = '/usr/lib/update-notifier/apt-check';

    private const REBOOT_FLAG = '/var/run/reboot-required';

    private const REBOOT_PKGS = '/var/run/reboot-required.pkgs';

    private const MARKER_APT = '---APT-CHECK---';

    private const MARKER_FLAG = '---REBOOT-FLAG---';

    private const MARKER_PKGS = '---REBOOT-PKGS---';

    private const MARKER_LIST = '---APT-LIST---';

    private const MARKER_END = '---END---';

    public function __construct(private readonly SshClient $ssh) {}

    /**
     * Run the probe and return data shaped like a snapshot row payload.
     * Never throws — connection / parse failures are reported via the
     * `poll_status` + `poll_error` fields on the returned array so the
     * caller can persist a row that explains the gap.
     *
     * @return array{
     *   total_updates: int,
     *   security_updates: int,
     *   reboot_required: bool,
     *   reboot_required_pkgs: array<int, string>,
     *   poll_status: string,
     *   poll_error: ?string,
     * }
     */
    public function probe(Server $server): array
    {
        // `apt list --upgradable` is the per-package list. We pipe it
        // through `LANG=C` to keep the [upgradable from: ...] suffix
        // English-locale (parser depends on the literal token), and
        // accept that it can take a few seconds on a busy box.
        $command = sprintf(
            'echo %1$s; %2$s 2>&1 || true; echo %3$s; (test -f %4$s && echo yes || echo no); echo %5$s; (cat %6$s 2>/dev/null || true); echo %7$s; LANG=C apt list --upgradable 2>/dev/null || true; echo %8$s',
            escapeshellarg(self::MARKER_APT),
            escapeshellarg(self::APT_CHECK_BIN),
            escapeshellarg(self::MARKER_FLAG),
            escapeshellarg(self::REBOOT_FLAG),
            escapeshellarg(self::MARKER_PKGS),
            escapeshellarg(self::REBOOT_PKGS),
            escapeshellarg(self::MARKER_LIST),
            escapeshellarg(self::MARKER_END),
        );

        try {
            $output = $this->ssh->exec($server, $command, 30);

            return $this->parse($output);
        } catch (\Throwable $e) {
            return $this->failure(ServerUpdateSnapshot::STATUS_SSH_FAILED, $e->getMessage());
        }
    }

    /**
     * @return array{
     *   total_updates: int,
     *   security_updates: int,
     *   reboot_required: bool,
     *   reboot_required_pkgs: array<int, string>,
     *   poll_status: string,
     *   poll_error: ?string,
     * }
     */
    private function parse(string $output): array
    {
        $aptSection = $this->section($output, self::MARKER_APT, self::MARKER_FLAG);
        $flagSection = trim($this->section($output, self::MARKER_FLAG, self::MARKER_PKGS));
        $pkgsSection = trim($this->section($output, self::MARKER_PKGS, self::MARKER_LIST));
        $listSection = trim($this->section($output, self::MARKER_LIST, self::MARKER_END));

        // apt-check writes its "total;security" to stderr on older Ubuntus;
        // we redirected with 2>&1 so it lands here. Match the LAST line that
        // looks like a "<int>;<int>" pair so any preceding noise is ignored.
        $total = 0;
        $security = 0;
        $matched = false;
        foreach (array_reverse(preg_split('/\r?\n/', trim($aptSection)) ?: []) as $line) {
            if (preg_match('/^\s*(\d+);(\d+)\s*$/', (string) $line, $m)) {
                $total = (int) $m[1];
                $security = (int) $m[2];
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            return $this->failure(
                ServerUpdateSnapshot::STATUS_PARSE_FAILED,
                'apt-check output did not contain a "total;security" line. Raw: '.mb_substr($aptSection, 0, 240),
            );
        }

        $rebootRequired = ($flagSection === 'yes');

        $pkgs = [];
        foreach (preg_split('/\r?\n/', $pkgsSection) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $pkgs[] = $line;
            }
        }

        return [
            'total_updates' => $total,
            'security_updates' => $security,
            'reboot_required' => $rebootRequired,
            'reboot_required_pkgs' => $pkgs,
            'upgradable_pkgs' => $this->parseAptList($listSection),
            'poll_status' => ServerUpdateSnapshot::STATUS_OK,
            'poll_error' => null,
        ];
    }

    /**
     * Parse `apt list --upgradable` output. Each upgradable package is one
     * line in the form:
     *   linux-image-5.15.0-177-generic/jammy-updates 5.15.0-177.187 amd64 [upgradable from: 5.15.0-176.186]
     *
     * Lines without the "[upgradable from: ...]" suffix (the "Listing... Done"
     * banner, blank lines, locale headers) are skipped. The `source` token is
     * the slash-separated suite name; we treat any source containing
     * `-security` as a security-channel upgrade.
     *
     * @return list<array{name: string, from: string, to: string, source: string, security: bool}>
     */
    private function parseAptList(string $section): array
    {
        $pkgs = [];
        foreach (preg_split('/\r?\n/', $section) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, 'Listing')) {
                continue;
            }
            // Defensive regex: package and source come before the version+arch
            // chunk; the upgrade-from version is the last bracketed token.
            if (! preg_match('#^([^\s/]+)/(\S+)\s+(\S+)\s+\S+\s+\[upgradable from:\s+(\S+)\]\s*$#', $line, $m)) {
                continue;
            }
            $source = $m[2];
            $pkgs[] = [
                'name' => $m[1],
                'source' => $source,
                'to' => $m[3],
                'from' => $m[4],
                'security' => str_contains($source, '-security'),
            ];
        }

        return $pkgs;
    }

    private function section(string $output, string $startMarker, string $endMarker): string
    {
        $start = strpos($output, $startMarker);
        if ($start === false) {
            return '';
        }
        $start += strlen($startMarker);
        $end = strpos($output, $endMarker, $start);
        if ($end === false) {
            return substr($output, $start);
        }

        return substr($output, $start, $end - $start);
    }

    /**
     * @return array{
     *   total_updates: int,
     *   security_updates: int,
     *   reboot_required: bool,
     *   reboot_required_pkgs: array<int, string>,
     *   poll_status: string,
     *   poll_error: string,
     * }
     */
    private function failure(string $status, string $message): array
    {
        return [
            'total_updates' => 0,
            'security_updates' => 0,
            'reboot_required' => false,
            'reboot_required_pkgs' => [],
            'upgradable_pkgs' => [],
            'poll_status' => $status,
            'poll_error' => mb_substr($message, 0, 2000),
        ];
    }
}
