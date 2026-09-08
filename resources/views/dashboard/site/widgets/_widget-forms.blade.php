@php
    $formsModuleEnabled = app(\Modules\Core\ModuleStateResolver::class)->isEnabled('contact-forms');
    $hasForms = $formsModuleEnabled && ($formTestsCount > 0 || $site->contact_form_plugin || $latestFormRun);
    $runSuccess = $latestFormRun && $latestFormRun->status === 'success';
@endphp

<div class="card p-5 flex flex-col justify-between h-full">
    <div>
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                @if ($hasForms)
                    <i class="fa-solid fa-envelope-circle-check text-indigo-600"></i>
                    Form Dispatch &amp; Leads
                @else
                    <i class="fa-solid fa-server text-[var(--color-brand)]"></i>
                    Environment &amp; SSL
                @endif
            </h3>
            @if ($hasForms)
                @if ($runSuccess)
                    <span class="status-pill status-green text-[10px]">
                        <span class="status-dot"></span> Functional
                    </span>
                @elseif ($latestFormRun)
                    <span class="status-pill status-red text-[10px]">
                        <span class="status-dot"></span> Failing
                    </span>
                @else
                    <span class="status-pill status-unknown text-[10px]">Untested</span>
                @endif
            @else
                <span class="status-pill status-green text-[10px]">
                    <span class="status-dot"></span> Active
                </span>
            @endif
        </div>

        @if ($hasForms)
            <div class="space-y-2.5 py-1">
                <div class="flex items-center justify-between text-xs p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60">
                    <span class="text-[var(--color-ink-muted)]">Detected Plugin:</span>
                    <span class="font-medium text-[var(--color-ink-strong)] capitalize">
                        {{ $site->contact_form_plugin ?: 'Custom / Standard' }}
                    </span>
                </div>
                <div class="flex items-center justify-between text-xs p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60">
                    <span class="text-[var(--color-ink-muted)]">Last Automated Test:</span>
                    @if ($runSuccess)
                        <span class="text-[10px] text-emerald-700 font-semibold flex items-center gap-1">
                            <i class="fa-solid fa-check text-[9px]"></i> Dispatched &amp; Verified
                        </span>
                    @elseif ($latestFormRun)
                        <span class="text-[10px] text-rose-700 font-semibold">
                            Failed (Streak: {{ $site->contact_form_test_failure_streak }})
                        </span>
                    @else
                        <span class="text-[10px] text-[var(--color-ink-muted)]">Awaiting test cycle</span>
                    @endif
                </div>
            </div>
        @else
            {{-- Fallback: Environment & Tech Stack card matching ManageWP subtext --}}
            <div class="space-y-2.5 py-1">
                <div class="flex items-center justify-between text-xs p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60">
                    <span class="text-[var(--color-ink-muted)]">PHP Version:</span>
                    <span class="font-mono font-medium text-[var(--color-ink-strong)]">
                        {{ $site->companion_snapshot['environment']['php_version'] ?? 'Standard' }}
                    </span>
                </div>
                <div class="flex items-center justify-between text-xs p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60">
                    <span class="text-[var(--color-ink-muted)]">WordPress Core:</span>
                    <span class="font-mono font-medium text-[var(--color-ink-strong)]">
                        {{ $site->companion_snapshot['environment']['wp_version'] ?? ($site->is_wordpress ? 'WordPress' : 'Static') }}
                    </span>
                </div>
                <div class="flex items-center justify-between text-xs p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60">
                    <span class="text-[var(--color-ink-muted)]">SSL Certificate:</span>
                    <span class="font-medium text-[var(--color-ink-strong)]">
                        @if ($site->cert_expires_at)
                            Expires {{ $site->cert_expires_at->diffForHumans() }}
                        @else
                            Not tracked
                        @endif
                    </span>
                </div>
            </div>
        @endif
    </div>

    <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between">
        <span class="text-[11px] text-[var(--color-ink-muted)]">
            @if ($hasForms && $latestFormRun?->ran_at)
                Ran {{ $latestFormRun->ran_at->diffForHumans() }}
            @else
                Server &amp; Core Stack
            @endif
        </span>
        @if ($hasForms)
            <a href="{{ route('sites.show', ['site' => $site, 'tab' => 'forms']) }}"
               class="btn-pill-nav text-xs font-medium text-indigo-600 hover:underline">
                Manage Forms <i class="fa-solid fa-chevron-right text-[10px] ml-0.5"></i>
            </a>
        @else
            <a href="{{ route('sites.show', ['site' => $site, 'tab' => 'settings']) }}#cert-detail"
               class="btn-pill-nav text-xs font-medium text-[var(--color-brand)] hover:underline">
                View Settings <i class="fa-solid fa-chevron-right text-[10px] ml-0.5"></i>
            </a>
        @endif
    </div>
</div>
