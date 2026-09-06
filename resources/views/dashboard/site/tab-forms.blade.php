@php
    $onCarePlan = (bool) $site->care_plan_enabled;
    $atCap = $formTests->count() >= \App\Models\ContactFormTest::MAX_PER_SITE;

    $statePill = fn (?string $state) => match ($state) {
        \App\Models\ContactFormTest::STATE_SUCCESS => ['class' => 'status-green', 'label' => 'Passing'],
        \App\Models\ContactFormTest::STATE_FAILED => ['class' => 'status-red', 'label' => 'Failing'],
        \App\Models\ContactFormTest::STATE_PENDING => ['class' => 'status-yellow', 'label' => 'Pending'],
        default => ['class' => 'status-unknown', 'label' => '—'],
    };

    // Detected form IDs from the most recent clockwork:detect-contact-forms
    // pass — used to populate the dropdown when adding a new form-test. Falls
    // back to a free-text input if no detection has run yet.
    $detectedForms = $site->detected_forms ?: [];
    $detectedFormIds = collect($detectedForms)->pluck('id')->filter()->values()->all();
@endphp

@if (! $onCarePlan)
    <div class="card p-4 mb-5 flex items-start gap-3 border-l-4 border-[var(--color-ink-soft)]">
        <i class="fa-regular fa-circle text-[var(--color-ink-soft)] mt-0.5"></i>
        <div class="text-sm flex-1">
            <p class="text-[var(--color-ink-strong)] font-medium">Contact form testing is part of the care plan.</p>
            <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">Daily form tests run on care-plan sites only. Enable on the <a class="text-[var(--color-primary-600)] hover:underline" href="{{ route('sites.show', [$site, 'settings']) }}">Settings tab</a> to begin scheduled runs.</p>
        </div>
    </div>
@endif

{{-- Existing form-tests --}}
<div class="card p-5 mb-5">
    <div class="flex items-center justify-between mb-3">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
            <i class="fa-solid fa-envelope-circle-check text-[var(--color-ink-muted)] mr-1"></i>
            Configured forms
        </h2>
        <span class="text-xs text-[var(--color-ink-soft)]">{{ $formTests->count() }} / {{ \App\Models\ContactFormTest::MAX_PER_SITE }}</span>
    </div>

    @if ($formTests->isEmpty())
        <p class="text-sm text-[var(--color-ink-muted)]">
            No forms configured yet. Use the "Add form" card below to start testing — the Companion mu-plugin must be installed first.
        </p>
    @else
        <div class="space-y-3">
            @foreach ($formTests as $cft)
                @php $pill = $statePill($cft->state); @endphp
                <div class="border border-[var(--color-border-light)] rounded p-4">
                    <div class="flex items-start justify-between gap-3 flex-wrap mb-3">
                        <div>
                            <div class="font-display text-base text-[var(--color-ink-strong)] flex items-center gap-2 flex-wrap">
                                <span class="text-[var(--color-ink-soft)] text-xs font-data">slot {{ $cft->slot }}</span>
                                <span class="font-data">{{ $cft->form_id }}</span>
                                <span class="status-pill {{ $pill['class'] }} text-[10px]">{{ $pill['label'] }}</span>
                                @if ($cft->created_by === \App\Models\ContactFormTest::CREATED_BY_CLIENT)
                                    <span class="status-pill status-unknown text-[10px]" title="The site admin subscribed this from Companion's wp-admin Forms tab.">client</span>
                                @endif
                                @if (! $cft->enabled)
                                    <span class="status-pill status-unknown text-[10px]">disabled</span>
                                @endif
                            </div>
                            <div class="text-xs text-[var(--color-ink-muted)] mt-1">
                                Plugin: <span class="font-data">{{ $cft->form_plugin ?: '—' }}</span>
                                · Frequency: <span class="capitalize">{{ $cft->frequency }}</span>
                                · Last run: {{ $cft->last_test_at?->diffForHumans() ?? 'never' }}
                                · Streak: {{ $cft->failure_streak }}
                            </div>
                            @if ($cft->last_test_error)
                                <div class="text-xs text-[var(--color-status-red)] mt-1 font-data" title="{{ $cft->last_test_error }}">
                                    {{ Str::limit($cft->last_test_error, 200) }}
                                </div>
                            @endif
                        </div>
                        <div class="flex items-center gap-2 flex-wrap">
                            @if ($onCarePlan && $cft->enabled)
                                <form method="POST" action="{{ route('sites.forms.test-now', ['site' => $site, 'cft' => $cft]) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="btn-pill-nav text-xs">
                                        <i class="fa-solid fa-paper-plane"></i> Test now
                                    </button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('sites.forms.destroy', ['site' => $site, 'cft' => $cft]) }}" class="inline"
                                  onsubmit="return confirm('Remove form-test {{ $cft->form_id }}?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-pill-nav text-xs text-[var(--color-status-red)]" title="Remove this form-test">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>

                    {{-- Inline edit form --}}
                    <details>
                        <summary class="text-xs text-[var(--color-primary-600)] hover:underline cursor-pointer">Edit settings</summary>
                        <form method="POST" action="{{ route('sites.forms.update', ['site' => $site, 'cft' => $cft]) }}"
                              class="mt-2 grid grid-cols-2 gap-3 text-sm">
                            @csrf
                            @method('PATCH')
                            <label class="block">
                                <span class="block text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">Form ID</span>
                                <input type="text" name="form_id" value="{{ $cft->form_id }}"
                                    class="bg-[var(--color-surface)] border border-[var(--color-border)] rounded px-3 py-1.5 text-sm w-full font-data">
                            </label>
                            <label class="block">
                                <span class="block text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">Frequency</span>
                                <select name="frequency"
                                    class="bg-[var(--color-surface)] border border-[var(--color-border)] rounded px-3 py-1.5 text-sm w-full">
                                    <option value="weekly" @selected($cft->frequency === 'weekly')>Weekly</option>
                                    @if ($adminMode || $cft->frequency === 'daily')
                                        <option value="daily" @selected($cft->frequency === 'daily')>Daily (admin)</option>
                                    @endif
                                </select>
                            </label>
                            <label class="block col-span-2">
                                <span class="block text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">Form URL (optional)</span>
                                <input type="url" name="form_url" value="{{ $cft->form_url }}"
                                    placeholder="https://example.com/contact"
                                    class="bg-[var(--color-surface)] border border-[var(--color-border)] rounded px-3 py-1.5 text-sm w-full font-data">
                            </label>
                            <div class="col-span-2 flex items-center gap-3">
                                <label class="text-sm flex items-center gap-2">
                                    <input type="hidden" name="enabled" value="0">
                                    <input type="checkbox" name="enabled" value="1" @checked($cft->enabled) class="h-4 w-4">
                                    Enabled
                                </label>
                                <button type="submit" class="btn-pill-nav text-xs ml-auto">Save</button>
                            </div>
                        </form>
                    </details>
                </div>
            @endforeach
        </div>
    @endif
</div>

{{-- Add form --}}
@if ($onCarePlan)
    <div class="card p-5 mb-5 {{ $atCap ? 'opacity-60' : '' }}">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-3">
            <i class="fa-solid fa-plus text-[var(--color-ink-muted)] mr-1"></i>
            Add form
        </h2>

        @if ($atCap)
            <p class="text-sm text-[var(--color-ink-muted)]">
                You're at the maximum of {{ \App\Models\ContactFormTest::MAX_PER_SITE }} forms for this site. Remove one above to add another.
            </p>
        @else
            <form method="POST" action="{{ route('sites.forms.store', $site) }}" class="grid grid-cols-2 gap-3 text-sm">
                @csrf
                <label class="block col-span-2 md:col-span-1">
                    <span class="block text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">Form ID</span>
                    @if (! empty($detectedForms))
                        <select name="form_id" required
                            class="bg-[var(--color-surface)] border border-[var(--color-border)] rounded px-3 py-1.5 text-sm w-full font-data">
                            <option value="">— select —</option>
                            @foreach ($detectedForms as $form)
                                <option value="{{ $form['id'] }}">
                                    #{{ $form['id'] }} — {{ $form['title'] ?: '(untitled)' }}
                                </option>
                            @endforeach
                        </select>
                    @else
                        <input type="text" name="form_id" required placeholder="e.g. 1, contact-form, etc."
                            class="bg-[var(--color-surface)] border border-[var(--color-border)] rounded px-3 py-1.5 text-sm w-full font-data">
                    @endif
                </label>
                <label class="block col-span-2 md:col-span-1">
                    <span class="block text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">Frequency</span>
                    <select name="frequency"
                        class="bg-[var(--color-surface)] border border-[var(--color-border)] rounded px-3 py-1.5 text-sm w-full">
                        <option value="weekly" selected>Weekly</option>
                        @if ($adminMode)
                            <option value="daily">Daily (admin)</option>
                        @endif
                    </select>
                </label>
                <label class="block col-span-2">
                    <span class="block text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">Form URL (optional)</span>
                    <input type="url" name="form_url" placeholder="https://example.com/contact"
                        class="bg-[var(--color-surface)] border border-[var(--color-border)] rounded px-3 py-1.5 text-sm w-full font-data">
                </label>
                <input type="hidden" name="form_plugin" value="{{ $site->contact_form_plugin }}">
                <div class="col-span-2">
                    <button type="submit" class="btn-pill-nav text-sm">Add form</button>
                    @if (! $site->companion_installed)
                        <span class="text-xs text-[var(--color-status-yellow)] ml-2">
                            <i class="fa-solid fa-triangle-exclamation"></i> Companion not installed — tests will be skipped until it is.
                        </span>
                    @endif
                </div>
            </form>
        @endif
    </div>
@endif

{{-- Recent runs (across all forms on this site) --}}
@if ($formTestRuns->isNotEmpty())
    <div class="card overflow-hidden">
        <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-clock-rotate-left text-[var(--color-ink-muted)] mr-1"></i>
                Recent runs
            </h2>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                <tr>
                    <th class="text-left px-4 py-2">When</th>
                    <th class="text-left px-4 py-2">Form</th>
                    <th class="text-left px-4 py-2">Mode</th>
                    <th class="text-left px-4 py-2">Accepted</th>
                    <th class="text-left px-4 py-2">Mail</th>
                    <th class="text-left px-4 py-2">Status</th>
                    <th class="text-left px-4 py-2">Error</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--color-border-light)]">
                @foreach ($formTestRuns as $run)
                    <tr>
                        <td class="px-4 py-2 text-xs text-[var(--color-ink-muted)]" title="{{ $run->ran_at }}">{{ $run->ran_at->diffForHumans() }}</td>
                        <td class="px-4 py-2 text-xs font-data text-[var(--color-ink-strong)]">{{ $run->contactFormTest?->form_id ?? '—' }}</td>
                        <td class="px-4 py-2 text-xs text-[var(--color-ink-muted)]">{{ $run->mode }}</td>
                        <td class="px-4 py-2 text-xs">
                            @if ($run->accepted)
                                <i class="fa-solid fa-check text-[var(--color-status-green)]"></i>
                            @else
                                <i class="fa-solid fa-xmark text-[var(--color-status-red)]"></i>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-xs font-data text-[var(--color-ink-muted)]">{{ $run->mail_outcome ?? '—' }}</td>
                        <td class="px-4 py-2 text-xs">
                            @if ($run->status === \App\Models\ContactFormTestRun::STATUS_SUCCESS)
                                <span class="status-pill status-green text-[10px]"><i class="fa-solid fa-circle-check"></i> ok</span>
                            @else
                                <span class="status-pill status-red text-[10px]"><i class="fa-solid fa-circle-xmark"></i> fail</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-xs text-[var(--color-ink-muted)] max-w-[24rem] truncate" title="{{ $run->error }}">
                            {{ $run->error ?? '' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
