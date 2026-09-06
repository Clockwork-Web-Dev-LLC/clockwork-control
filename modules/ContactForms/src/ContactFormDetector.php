<?php

namespace Modules\ContactForms;

use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;
use Throwable;

class ContactFormDetector
{
    public const RESULT_DETECTED = 'detected';

    public const RESULT_NO_FORM_PLUGIN = 'no-form-plugin';

    public const RESULT_FAILED = 'failed';

    public const RESULT_SKIPPED = 'skipped';

    /**
     * @return array{result: string, message: string, plugin?: string, form_id?: ?string}
     */
    public function detect(Site $site): array
    {
        // Gate moved to the caller (command/controller); the detector itself
        // only needs Companion reachable. The per-site Forms tab can also call
        // this lazily, so the legacy contact_form_test_enabled check no longer
        // belongs here.
        if (! $site->companion_installed || ! $site->companion_secret) {
            return ['result' => self::RESULT_SKIPPED, 'message' => 'Companion not installed yet.'];
        }

        try {
            $payload = (new ClockworkCompanionClient($site))->detect();
        } catch (Throwable $e) {
            return ['result' => self::RESULT_FAILED, 'message' => $e->getMessage()];
        }

        $plugin = $payload['form_plugin'] ?? null;
        $forms = (array) ($payload['forms'] ?? []);

        if (! is_string($plugin) || $plugin === '') {
            $site->forceFill([
                'contact_form_plugin' => null,
                'detected_forms' => null,
                'contact_forms_detected_at' => now(),
                'companion_last_seen_at' => now(),
            ])->save();

            return ['result' => self::RESULT_NO_FORM_PLUGIN, 'message' => 'No supported form plugin active.'];
        }

        // Normalize the list — store {id, title, page_url} only, drop anything
        // else Companion happened to include. Keeps the JSON small + predictable
        // for the Forms-tab dropdown that reads it.
        $normalized = array_values(array_filter(array_map(function ($f) {
            $id = (string) ($f['id'] ?? '');
            if ($id === '') {
                return null;
            }

            return [
                'id' => $id,
                'title' => (string) ($f['title'] ?? ''),
                'page_url' => $f['page_url'] ?? null,
            ];
        }, $forms)));

        $picked = $this->autoPick($forms);

        $site->forceFill([
            'contact_form_plugin' => $plugin,
            'detected_forms' => $normalized,
            'contact_forms_detected_at' => now(),
            'companion_last_seen_at' => now(),
        ])->save();

        return [
            'result' => self::RESULT_DETECTED,
            'message' => sprintf(
                'Detected %s; %d form%s%s.',
                $plugin,
                count($normalized),
                count($normalized) === 1 ? '' : 's',
                $picked ? " (auto-pick: #{$picked['id']})" : ''
            ),
            'plugin' => $plugin,
            'form_id' => $picked['id'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $forms
     * @return null|array{id: string, title: string, page_url: ?string}
     */
    private function autoPick(array $forms): ?array
    {
        if ($forms === []) {
            return null;
        }
        if (count($forms) === 1) {
            return $forms[0];
        }
        foreach ($forms as $form) {
            $url = (string) ($form['page_url'] ?? '');
            if ($url !== '' && stripos($url, 'contact') !== false) {
                return $form;
            }
        }

        return null;
    }
}
