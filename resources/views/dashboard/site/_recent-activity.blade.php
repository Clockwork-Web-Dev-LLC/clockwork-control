@php
    $iconFor = fn (string $type) => match ($type) {
        \App\Models\ActionLog::TYPE_PLUGIN_UPDATE => ['icon' => 'fa-arrow-up-from-bracket', 'class' => 'text-[var(--color-status-yellow)]'],
        \App\Models\ActionLog::TYPE_THEME_UPDATE => ['icon' => 'fa-paintbrush', 'class' => 'text-[var(--color-status-yellow)]'],
        \App\Models\ActionLog::TYPE_CORE_UPDATE => ['icon' => 'fa-arrows-rotate', 'class' => 'text-[var(--color-status-yellow)]'],
        \App\Models\ActionLog::TYPE_SSO_LOGIN => ['icon' => 'fa-right-to-bracket', 'class' => 'text-[var(--color-brand)]'],
        \App\Models\ActionLog::TYPE_COMPANION_INSTALL => ['icon' => 'fa-download', 'class' => 'text-[var(--color-status-green)]'],
        \App\Models\ActionLog::TYPE_COMPANION_UPDATE => ['icon' => 'fa-arrow-up', 'class' => 'text-[var(--color-status-green)]'],
        \App\Models\ActionLog::TYPE_COMPANION_UNINSTALL => ['icon' => 'fa-trash', 'class' => 'text-[var(--color-status-red)]'],
        \App\Models\ActionLog::TYPE_MANUAL_BAN => ['icon' => 'fa-ban', 'class' => 'text-[var(--color-status-red)]'],
        \App\Models\ActionLog::TYPE_MANUAL_UNBAN => ['icon' => 'fa-unlock', 'class' => 'text-[var(--color-ink-muted)]'],
        \App\Models\ActionLog::TYPE_REVIEW_APPROVE => ['icon' => 'fa-check', 'class' => 'text-[var(--color-status-red)]'],
        \App\Models\ActionLog::TYPE_REVIEW_DISMISS => ['icon' => 'fa-xmark', 'class' => 'text-[var(--color-ink-muted)]'],
        \App\Models\ActionLog::TYPE_CARE_PLAN_TOGGLED => ['icon' => 'fa-shield-heart', 'class' => 'text-[var(--color-ink-muted)]'],
        default => ['icon' => 'fa-circle-dot', 'class' => 'text-[var(--color-ink-muted)]'],
    };
    $labelFor = fn (string $type) => match ($type) {
        \App\Models\ActionLog::TYPE_PLUGIN_UPDATE => 'plugin update',
        \App\Models\ActionLog::TYPE_SSO_LOGIN => 'SSO login',
        \App\Models\ActionLog::TYPE_COMPANION_INSTALL => 'Companion install',
        \App\Models\ActionLog::TYPE_COMPANION_UPDATE => 'Companion update',
        \App\Models\ActionLog::TYPE_MANUAL_BAN => 'manual ban',
        \App\Models\ActionLog::TYPE_MANUAL_UNBAN => 'manual unban',
        \App\Models\ActionLog::TYPE_REVIEW_APPROVE => 'review approve',
        \App\Models\ActionLog::TYPE_REVIEW_DISMISS => 'review dismiss',
        \App\Models\ActionLog::TYPE_CARE_PLAN_TOGGLED => 'care plan toggled',
        default => str_replace('_', ' ', $type),
    };
@endphp

<div class="card overflow-hidden mb-5">
    <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
        <h2 class="font-display text-base font-semibold text-[var(--color-ink-strong)]">
            <i class="fa-solid fa-clock-rotate-left text-[var(--color-ink-muted)] mr-1"></i>
            Recent activity
        </h2>
        <div class="flex items-center gap-3">
            @if ($recentActivity->isNotEmpty())
                <span class="text-xs text-[var(--color-ink-soft)]">last {{ $recentActivity->count() }}</span>
            @endif
            <a href="{{ route('maintenance-history.index', ['site_id' => $site->id]) }}"
               class="text-xs text-[var(--color-brand)] hover:text-[var(--color-ink-strong)]"
               title="Full history for this site — every plugin/theme/core update, SSO login, ban, and more">
                View full history <i class="fa-solid fa-arrow-right text-[10px]"></i>
            </a>
        </div>
    </div>
    @if ($recentActivity->isEmpty())
        <div class="p-6 text-center text-sm text-[var(--color-ink-soft)]">
            Nothing logged yet. Plugin updates, SSO logins, manual bans, and Companion installs land here as you do them.
        </div>
    @else
        <ul class="divide-y divide-[var(--color-border-light)] max-h-[28rem] overflow-y-auto">
            @foreach ($recentActivity as $row)
                @php $meta = $iconFor($row->action_type); @endphp
                <li class="px-5 py-2.5 flex items-start gap-3 text-sm">
                    <i class="fa-solid {{ $meta['icon'] }} {{ $meta['class'] }} mt-0.5 w-4 text-center" title="{{ $labelFor($row->action_type) }}"></i>
                    <div class="flex-1 min-w-0">
                        <div class="text-[var(--color-ink-strong)] truncate" title="{{ $row->summary }}">
                            {{ $row->summary }}
                            @unless ($row->ok)
                                <span class="status-pill status-red text-[10px] ml-1">failed</span>
                            @endunless
                        </div>
                        <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">
                            {{ $row->ran_at?->diffForHumans() }}
                            · {{ $labelFor($row->action_type) }}
                            @if ($row->actor !== 'manual')
                                · <span class="font-data">{{ $row->actor }}</span>
                            @endif
                            @if ($row->elapsed_ms)
                                · {{ number_format($row->elapsed_ms / 1000, 1) }}s
                            @endif
                            @if ($row->error)
                                · <span class="text-[var(--color-status-red)]">{{ Str::limit($row->error, 80) }}</span>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
