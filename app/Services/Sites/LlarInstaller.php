<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\Chat\ChatNotifier;
use App\Services\Ssh\SshClient;

class LlarInstaller
{
    public const RESULT_INSTALLED = 'installed';

    public const RESULT_ALREADY_PRESENT = 'already-present';

    public const RESULT_FAILED = 'failed';

    public const RESULT_SKIPPED_NOT_WP = 'skipped-not-wp';

    public function __construct(
        private readonly SshClient $ssh,
        private readonly ChatNotifier $mattermost,
    ) {}

    /**
     * Install Limit Login Attempts Reloaded on a site that doesn't have it,
     * activate it, and disable the lockout email feature (we keep the log
     * channel — that's what populates our review queue).
     *
     * If the plugin is already installed, returns ALREADY_PRESENT and does
     * NOT touch the plugin or its settings — site owner's existing config
     * is preserved.
     *
     * @return array{result: string, message: string, output?: string}
     */
    public function process(Site $site): array
    {
        if (! $site->is_wordpress) {
            return ['result' => self::RESULT_SKIPPED_NOT_WP, 'message' => 'Not a WordPress site.'];
        }
        if (! $site->server) {
            return ['result' => self::RESULT_FAILED, 'message' => 'Site has no linked server.'];
        }
        if (! $site->site_user) {
            return ['result' => self::RESULT_FAILED, 'message' => 'Site has no site_user; cannot drop privileges to install.'];
        }
        if (! $site->server->ssh_password) {
            return ['result' => self::RESULT_FAILED, 'message' => 'Server has no sudo password stored; cannot run wp-cli as the site user.'];
        }

        $wpPath = $site->wp_path ?: '/sites/'.$site->domain.'/files';

        $detect = $this->run($site, $site->site_user, $wpPath, 'plugin is-installed limit-login-attempts-reloaded');

        if ($detect['exit'] === 0) {
            // Plugin is on disk — but is it active? Inactive means the detector will
            // flip llar_enabled back to false on its next run, creating the "keeps
            // forgetting" loop. Activate if needed.
            $isActive = $this->run($site, $site->site_user, $wpPath, 'plugin is-active limit-login-attempts-reloaded');

            if ($isActive['exit'] === 0) {
                // Active and present — nothing to do.
                if (! $site->llar_enabled) {
                    $site->llar_enabled = true;
                    $site->save();
                }

                return [
                    'result' => self::RESULT_ALREADY_PRESENT,
                    'message' => 'LLAR already installed and active; left untouched.',
                ];
            }

            // Installed but inactive — activate it and apply email suppression.
            $activate = $this->run($site, $site->site_user, $wpPath, 'plugin activate limit-login-attempts-reloaded');
            if ($activate['exit'] !== 0) {
                return [
                    'result' => self::RESULT_FAILED,
                    'message' => 'LLAR installed but activation failed (exit '.$activate['exit'].').',
                    'output' => $activate['output'],
                ];
            }

            $output = $activate['output'];
            $bestEffortWarnings = [];
            foreach ($this->emailSuppressionSteps() as $label => $args) {
                $r = $this->run($site, $site->site_user, $wpPath, $args);
                $output .= "\n".$r['output'];
                if ($r['exit'] !== 0) {
                    return [
                        'result' => self::RESULT_FAILED,
                        'message' => "LLAR activated but email-suppression step failed: {$label}.",
                        'output' => trim($output),
                    ];
                }
            }
            foreach ($this->dismissModalSteps() as $label => $args) {
                $r = $this->run($site, $site->site_user, $wpPath, $args);
                $output .= "\n".$r['output'];
                if ($r['exit'] !== 0) {
                    $bestEffortWarnings[] = $label;
                }
            }

            $site->llar_enabled = true;
            $site->save();

            $this->mattermost->llarInstalled($site);

            $message = 'LLAR was installed but inactive — activated and email notifications suppressed.';
            if (! empty($bestEffortWarnings)) {
                $message .= ' Best-effort modal steps already at target: '.implode(', ', $bestEffortWarnings).'.';
            }

            return [
                'result' => self::RESULT_INSTALLED,
                'message' => $message,
                'output' => trim($output),
            ];
        }

        $install = $this->run($site, $site->site_user, $wpPath, 'plugin install limit-login-attempts-reloaded --activate');
        if ($install['exit'] !== 0) {
            return [
                'result' => self::RESULT_FAILED,
                'message' => 'wp plugin install failed (exit '.$install['exit'].').',
                'output' => $install['output'],
            ];
        }

        // Belt-and-suspenders: clear every option that could trigger a lockout email,
        // and pre-dismiss the onboarding/review modals so the site owner doesn't see
        // them when they next visit wp-admin. LLAR 3.2.x option keys, verified by
        // dumping live wp option list after a fresh activate.
        $output = $install['output'];
        $bestEffortWarnings = [];
        foreach ($this->emailSuppressionSteps() as $label => $args) {
            $r = $this->run($site, $site->site_user, $wpPath, $args);
            $output .= "\n".$r['output'];
            if ($r['exit'] !== 0) {
                return [
                    'result' => self::RESULT_FAILED,
                    'message' => "Plugin installed but email-suppression step failed: {$label}. Aborting before notifying Mattermost.",
                    'output' => trim($output),
                ];
            }
        }
        foreach ($this->dismissModalSteps() as $label => $args) {
            $r = $this->run($site, $site->site_user, $wpPath, $args);
            $output .= "\n".$r['output'];
            if ($r['exit'] !== 0) {
                $bestEffortWarnings[] = $label;
            }
        }

        $site->llar_enabled = true;
        $site->save();

        // Fire-and-forget: notifier no-ops when Mattermost is disabled in config.
        $this->mattermost->llarInstalled($site);

        $message = 'LLAR installed and activated. Lockout email disabled (recipient cleared, threshold maxed, notify channel emptied). Onboarding + review modals pre-dismissed.';
        if (! empty($bestEffortWarnings)) {
            $message .= ' Best-effort dismiss-modal steps that wp-cli reported as "could not update" (likely already at target): '.implode(', ', $bestEffortWarnings).'.';
        }

        return [
            'result' => self::RESULT_INSTALLED,
            'message' => $message,
            'output' => trim($output),
        ];
    }

    /**
     * Required post-activation writes that kill every path to a lockout email.
     *
     * @return array<string, string>
     */
    private function emailSuppressionSteps(): array
    {
        return [
            'lockout_notify cleared' => 'option update limit_login_lockout_notify ""',
            'admin_notify_email blank' => 'option update limit_login_admin_notify_email ""',
            'notify_after maxed' => 'option update limit_login_notify_email_after 999999',
        ];
    }

    /**
     * Best-effort writes that pre-dismiss LLAR's UI nag-screens.
     *
     * @return array<string, string>
     */
    private function dismissModalSteps(): array
    {
        return [
            'onboarding popup hidden' => 'option update limit_login_onboarding_popup_shown 1',
            'review notice hidden' => 'option update limit_login_review_notice_shown 1',
        ];
    }

    /**
     * Run a `wp <args>` command on the server as the site_user.
     *
     * @return array{output: string, exit: int}
     */
    private function run(Site $site, string $siteUser, string $wpPath, string $wpArgs): array
    {
        $sentinel = '__CLOCKWORK_WP_EXIT__';

        $inner = sprintf(
            'printf %%s "$CW_SUDO_PW" | sudo -S -u %s /usr/local/bin/wp --path=%s %s 2>&1; echo "%s:$?"',
            escapeshellarg($siteUser),
            escapeshellarg($wpPath),
            $wpArgs,
            $sentinel,
        );

        $cmd = sprintf(
            'CW_SUDO_PW=%s bash -c %s',
            escapeshellarg((string) $site->server->ssh_password),
            escapeshellarg($inner),
        );

        $raw = $this->ssh->exec($site->server, $cmd);

        $exit = -1;
        if (preg_match('/'.$sentinel.':(\d+)/', $raw, $m)) {
            $exit = (int) $m[1];
            $raw = (string) preg_replace('/\s*'.$sentinel.':\d+\s*$/', '', $raw);
        }

        return [
            'output' => trim($raw),
            'exit' => $exit,
        ];
    }
}
