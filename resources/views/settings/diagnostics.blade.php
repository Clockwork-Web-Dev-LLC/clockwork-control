@extends('layouts.app')

@section('title', 'Diagnostics · Clockwork')

@section('content')
    <x-page-header title="Diagnostics"
        subtitle="Connectivity check across every external integration the app uses. Read-only — nothing here sends test mail, posts to chat, or writes to anyone's API.">
        <x-slot:actions>
            <a href="{{ route('settings.diagnostics.index') }}"
               class="btn-pill-nav text-sm"
               title="Query all external integrations again">
                <i class="fa-solid fa-rotate-right"></i>
                <span>Run all again</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Roll-up metric tiles --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6 max-w-7xl">
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Passing Checks</div>
            <div class="text-2xl font-display text-[var(--color-status-green)] font-data">{{ $counts['ok'] }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Healthy integrations</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Failing Checks</div>
            <div class="text-2xl font-display {{ $counts['fail'] > 0 ? 'text-[var(--color-status-red)]' : 'text-[var(--color-ink-strong)]' }} font-data">{{ $counts['fail'] }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">{{ $counts['fail'] > 0 ? 'Require operator attention' : 'None failing' }}</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Skipped</div>
            <div class="text-2xl font-display text-[var(--color-ink-soft)] font-data">{{ $counts['skipped'] }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Unconfigured integrations</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Last Run</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">{{ $ranAt->diffForHumans(null, true) }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5 font-data">{{ $ranAt->format('H:i:s') }}</div>
        </div>
    </div>

    @php
        $rowsArr = is_array($rows) ? $rows : iterator_to_array($rows);
        $half = (int) ceil(count($rowsArr) / 2);
        $columns = [array_slice($rowsArr, 0, $half), array_slice($rowsArr, $half)];
    @endphp
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 max-w-7xl">
        @foreach ($columns as $column)
            <div class="card divide-y divide-[var(--color-border-light)]">
                @foreach ($column as $row)
                    @php
                        $r = $row['result'];
                        $pillClass = match ($r->status) {
                            'ok' => 'status-green',
                            'fail' => 'status-red',
                            default => 'status-unknown',
                        };
                        $statusIcon = match ($r->status) {
                            'ok' => 'fa-circle-check text-[var(--color-status-green)]',
                            'fail' => 'fa-circle-xmark text-[var(--color-status-red)]',
                            default => 'fa-circle-minus text-[var(--color-ink-soft)]',
                        };
                        $statusLabel = match ($r->status) {
                            'ok' => 'OK',
                            'fail' => 'FAIL',
                            default => 'SKIP',
                        };
                    @endphp
                    <div id="check-{{ $row['id'] }}" class="p-4 flex items-start gap-4">
                        <div class="pt-0.5">
                            <i class="fa-solid {{ $statusIcon }} text-lg"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-display font-semibold text-[var(--color-ink-strong)]">{{ $row['name'] }}</span>
                                <span class="status-pill {{ $pillClass }} text-[10px] py-0.5 px-2">
                                    <span class="status-dot"></span>
                                    <span>{{ $statusLabel }}</span>
                                </span>
                                @if ($r->durationMs > 0)
                                    <span class="text-xs text-[var(--color-ink-soft)] font-data">{{ $r->durationMs }} ms</span>
                                @endif
                            </div>
                            <div class="text-xs text-[var(--color-ink-muted)] mb-1">{{ $row['description'] }}</div>
                            <div class="text-sm text-[var(--color-ink-strong)]">{{ $r->summary }}</div>
                            @if ($r->detail)
                                <details class="mt-1.5">
                                    <summary class="text-xs text-[var(--color-ink-soft)] cursor-pointer hover:text-[var(--color-ink-muted)]">
                                        Details
                                    </summary>
                                    <pre class="mt-1.5 p-2 bg-[var(--color-surface-alt)] text-[11px] font-data text-[var(--color-ink-muted)] rounded whitespace-pre-wrap break-all">{{ $r->detail }}</pre>
                                </details>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    <div class="mt-6 text-xs text-[var(--color-ink-soft)] max-w-5xl">
        <strong class="text-[var(--color-ink-muted)]">Skipped</strong> means the integration isn't
        configured (no token in <code class="font-data">.env</code>, or feature disabled). That's
        not an error — but if you expected it to be on, it's worth a look.
    </div>
@endsection
