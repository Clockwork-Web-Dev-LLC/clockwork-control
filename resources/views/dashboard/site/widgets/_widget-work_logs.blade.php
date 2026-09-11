@php
    $workLogs = $site->workLogs()->with('user:id,name')->orderByDesc('worked_on')->limit(8)->get();
@endphp
<div class="card p-5 flex flex-col justify-between h-full">
    <div>
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                <i class="fa-solid fa-clock text-sky-600"></i>
                Work log
            </h3>
        </div>
        <p class="text-[11px] text-[var(--color-ink-muted)] mb-3">
            Hours that roll into the monthly client report. Separate from private site notes.
        </p>

        @if ($workLogs->isEmpty())
            <p class="text-xs text-[var(--color-ink-soft)] mb-3">No hours logged yet.</p>
        @else
            <ul class="space-y-2 mb-3 max-h-40 overflow-y-auto">
                @foreach ($workLogs as $log)
                    <li class="text-xs border-b border-[var(--color-border-light)] pb-2 last:border-0">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <div class="font-medium text-[var(--color-ink-strong)]">{{ $log->worked_on->toDateString() }} · {{ number_format((float) $log->hours, 2) }}h</div>
                                <div class="text-[var(--color-ink-muted)]">{{ $log->description }}</div>
                            </div>
                            <form method="POST" action="{{ route('sites.work-logs.destroy', [$site, $log]) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-[var(--color-ink-muted)] hover:text-rose-600" title="Remove">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <form method="POST" action="{{ route('sites.work-logs.store', $site) }}" class="mt-2 pt-3 border-t border-[var(--color-border-light)] space-y-2">
        @csrf
        <div class="grid grid-cols-2 gap-2">
            <input type="date" name="worked_on" value="{{ now()->toDateString() }}" required
                   class="px-2 py-1.5 rounded border border-[var(--color-border)] text-xs bg-[var(--color-surface)]">
            <input type="number" name="hours" step="0.25" min="0.25" max="999.99" value="1.00" required
                   class="px-2 py-1.5 rounded border border-[var(--color-border)] text-xs bg-[var(--color-surface)]">
        </div>
        <textarea name="description" rows="2" required maxlength="2000" placeholder="What did you do?"
                  class="w-full px-2 py-1.5 rounded border border-[var(--color-border)] text-xs bg-[var(--color-surface)]"></textarea>
        <button type="submit" class="btn-pill-primary text-xs py-1.5 px-3 w-full">
            <i class="fa-solid fa-plus"></i> Log hours
        </button>
    </form>
</div>
