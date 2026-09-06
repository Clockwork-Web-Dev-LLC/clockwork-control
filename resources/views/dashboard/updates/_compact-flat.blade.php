{{-- Compact flat list — Core / Translations tab. One row per site. --}}

<form method="POST" action="{{ route('updates.bulkUpdate', ['tab' => $activeTab]) }}" id="updates-form">
    @csrf

    @if ($data['total'] === 0)
        <div class="px-5 py-12 text-center text-[var(--color-ink-muted)]">
            <i class="fa-solid fa-circle-check text-4xl text-[var(--color-status-green)] mb-3"></i>
            <p class="text-sm">All sites have {{ strtolower($label) }} up to date with the current filter.</p>
            @if ($kind === 'core' || $kind === 'translation')
                <p class="text-xs text-[var(--color-ink-soft)] mt-2">
                    Empty here usually means Companion v1.15.0 hasn't surfaced this data yet.
                </p>
            @endif
        </div>
    @else
        @if ($kind === 'core')
            <div class="mx-5 my-4 p-3 bg-[var(--color-status-amber,#d97706)]/5 border-l-4 border-[var(--color-status-amber,#d97706)] text-xs rounded">
                <strong class="text-[var(--color-ink-strong)]"><i class="fa-solid fa-triangle-exclamation"></i> Major-version bumps require explicit confirmation.</strong>
                <label class="mt-1 flex items-center gap-2">
                    <input type="checkbox" name="confirm_major" value="1" class="rounded">
                    <span>Confirm major version bump (e.g. 6.x → 7.x)</span>
                </label>
            </div>
        @endif

        <div class="divide-y divide-[var(--color-border-light)]">
            @foreach ($data['rows'] as $row)
                @php $target = "{$kind}:{$row['site_id']}:"; @endphp
                <label class="flex items-center gap-3 px-5 py-2.5 hover:bg-[var(--color-surface-alt)] cursor-pointer {{ $row['is_ignored'] ? 'opacity-50' : '' }}">
                    <input type="checkbox" name="targets[]" value="{{ $target }}"
                           class="rounded" {{ $row['has_live_job'] ? 'disabled' : '' }}>

                    <span class="flex-1 min-w-0">
                        <a href="{{ route('sites.show', $row['site_id']) }}"
                           onclick="event.stopPropagation()"
                           class="text-sm font-data text-[var(--color-ink-strong)] hover:underline truncate block">{{ $row['domain'] }}</a>
                        @if ($row['server_id'])
                            <span class="text-[10px] text-[var(--color-ink-soft)]">
                                {{ $row['server_name'] ?? '' }}@if ($row['server_tier']) · <span class="{{ $row['server_tier'] === 'Dedicated' ? 'text-[var(--color-primary-600)]' : '' }}">{{ $row['server_tier'] }}</span>@endif
                            </span>
                        @endif
                    </span>

                    @if ($row['care_plan_enabled'])
                        <i class="fa-solid fa-circle-check text-[10px] text-[var(--color-status-green)]" title="On care plan"></i>
                    @endif

                    @if ($kind === 'core' && ! empty($row['before_version']))
                        <span class="text-xs font-data tabular-nums whitespace-nowrap">
                            <span class="text-[var(--color-ink-soft)]">{{ $row['before_version'] }}</span>
                            <i class="fa-solid fa-arrow-right text-[var(--color-ink-soft)] mx-1 text-[10px]"></i>
                            <span class="text-[var(--color-primary-600)]">{{ $row['target_version'] }}</span>
                        </span>
                    @elseif ($kind === 'translation')
                        <span class="text-xs text-[var(--color-ink-muted)] whitespace-nowrap">{{ $row['before_version'] ?: 'pending' }}</span>
                    @endif

                    @if ($row['has_live_job'])
                        @php $isRunning = $row['live_job_status'] === \App\Models\PluginUpdateJob::STATUS_RUNNING; @endphp
                        <span class="text-[10px] whitespace-nowrap {{ $isRunning ? 'text-[var(--color-status-amber,#d97706)]' : 'text-[var(--color-ink-soft)]' }}">
                            <i class="fa-solid {{ $isRunning ? 'fa-spinner fa-spin' : 'fa-clock' }}"></i> {{ $row['live_job_status'] }}
                        </span>
                    @elseif ($row['is_ignored'])
                        <span class="text-[10px] text-[var(--color-ink-soft)]">ignored</span>
                    @else
                        <button type="button" data-update-row="{{ $target }}"
                                onclick="event.stopPropagation(); event.preventDefault();"
                                class="text-[11px] font-medium px-2 py-0.5 rounded border border-[var(--color-primary-600)] text-[var(--color-primary-600)] hover:bg-[var(--color-primary-600)] hover:text-white"
                                title="Update just this site">
                            Update
                        </button>
                    @endif
                </label>
            @endforeach
        </div>

        @include('dashboard.updates._footer-bar', ['data' => $data])
    @endif
</form>

@include('dashboard.updates._form-script')
