@extends('layouts.app')

@section('title', ($schedule->exists ? 'Edit Schedule · '.$schedule->site->domain : 'New Reporting Schedule').' · Clockwork')

@section('content')
<div class="mb-6 flex items-center justify-between flex-wrap gap-4">
    <div>
        <h1 class="display-heading text-3xl text-[var(--color-ink-strong)] flex items-center gap-2">
            <i class="fa-solid fa-file-lines text-[var(--color-brand)]"></i>
            Client Reports
        </h1>
        <p class="text-xs text-[var(--color-ink-soft)] mt-1">
            Automated, executive-ready white-labeled maintenance and performance reports for your agency clients.
        </p>
    </div>
    <div class="flex items-center gap-2">
        <a href="{{ route('client-reports.schedules.index') }}" class="btn-pill-nav text-xs">
            <i class="fa-solid fa-arrow-left mr-1"></i> Back to Schedules
        </a>
    </div>
</div>

@include('client-reports::_nav')

<div class="max-w-3xl">
    <div class="card p-6 md:p-8">
        <div class="mb-6">
            <h2 class="font-display font-semibold text-lg text-[var(--color-ink-strong)] flex items-center gap-2">
                <i class="fa-solid fa-clock text-[var(--color-brand)]"></i>
                {{ $schedule->exists ? 'Edit Schedule: '.$schedule->site->domain : 'Configure Automated Reporting Schedule' }}
            </h2>
            <p class="text-xs text-[var(--color-ink-muted)] mt-1">
                Configure automatic cron compilation and optional email delivery for care plan clients.
            </p>
        </div>

        @if ($errors->any())
            <div class="card p-4 mb-6 status-red text-xs space-y-1">
                <div class="font-semibold flex items-center gap-1.5">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>Please correct the errors below:</span>
                </div>
                <ul class="list-disc list-inside space-y-0.5 text-[11px] pl-2">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ $schedule->exists ? route('client-reports.schedules.update', $schedule) : route('client-reports.schedules.store') }}" class="space-y-6">
            @csrf
            @if ($schedule->exists)
                @method('PUT')
            @endif

            {{-- Target Site --}}
            <div>
                <label for="site_id" class="block text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-strong)] mb-1.5">
                    Target Website <span class="text-rose-500">*</span>
                </label>
                @if ($schedule->exists)
                    <input type="text"
                           disabled
                           value="{{ $schedule->site->domain }}"
                           class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-alt)] font-mono text-sm text-[var(--color-ink-strong)] cursor-not-allowed">
                    <input type="hidden" name="site_id" value="{{ $schedule->site_id }}">
                @else
                    <select name="site_id" id="site_id" required class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-sm font-mono text-[var(--color-ink-strong)] focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]">
                        <option value="" disabled {{ ! $schedule->site_id ? 'selected' : '' }}>Select an active website...</option>
                        @foreach ($sites as $site)
                            <option value="{{ $site->id }}" {{ old('site_id', $schedule->site_id) == $site->id ? 'selected' : '' }}>
                                {{ $site->domain }}
                            </option>
                        @endforeach
                    </select>
                @endif
            </div>

            {{-- Template Selector --}}
            <div>
                <label for="template_id" class="block text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-strong)] mb-1.5">
                    Report Template
                </label>
                <select name="template_id" id="template_id" class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-sm text-[var(--color-ink-strong)] focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]">
                    <option value="">Default template (All 7 Sections)</option>
                    @foreach ($templates as $t)
                        <option value="{{ $t->id }}" {{ old('template_id', $schedule->template_id) == $t->id ? 'selected' : '' }}>
                            {{ $t->name }} ({{ count((array) $t->sections) }} sections){{ $t->is_default ? ' — Default' : '' }}
                        </option>
                    @endforeach
                </select>
                <p class="text-[11px] text-[var(--color-ink-muted)] mt-1">
                    Manage templates under the <a href="{{ route('client-reports.templates.index') }}" target="_blank" class="text-[var(--color-brand)] underline">Templates tab</a>.
                </p>
            </div>

            {{-- Frequency --}}
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-strong)] mb-2">
                    Dispatch Cadence <span class="text-rose-500">*</span>
                </label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    @php $freq = old('frequency', $schedule->frequency ?? 'monthly'); @endphp
                    <label class="card p-3.5 flex items-start gap-3 cursor-pointer hover:bg-[var(--color-surface-alt)]/60 transition-colors border border-[var(--color-border-light)] rounded-xl">
                        <input type="radio" name="frequency" value="monthly" {{ $freq === 'monthly' ? 'checked' : '' }} class="mt-1 text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                        <div>
                            <span class="text-sm font-semibold text-[var(--color-ink-strong)]">Monthly</span>
                            <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">Aggregates the previous full calendar month.</p>
                        </div>
                    </label>
                    <label class="card p-3.5 flex items-start gap-3 cursor-pointer hover:bg-[var(--color-surface-alt)]/60 transition-colors border border-[var(--color-border-light)] rounded-xl">
                        <input type="radio" name="frequency" value="weekly" {{ $freq === 'weekly' ? 'checked' : '' }} class="mt-1 text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                        <div>
                            <span class="text-sm font-semibold text-[var(--color-ink-strong)]">Weekly</span>
                            <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">Aggregates metrics and updates for the previous 7 days.</p>
                        </div>
                    </label>
                </div>
            </div>

            {{-- Delivery Mode --}}
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-strong)] mb-2">
                    Delivery Mode <span class="text-rose-500">*</span>
                </label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    @php $mode = old('delivery_mode', $schedule->delivery_mode ?? 'auto'); @endphp
                    <label class="card p-3.5 flex items-start gap-3 cursor-pointer hover:bg-[var(--color-surface-alt)]/60 transition-colors border border-[var(--color-border-light)] rounded-xl">
                        <input type="radio" name="delivery_mode" value="auto" {{ $mode === 'auto' ? 'checked' : '' }} class="mt-1 text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                        <div>
                            <div class="flex items-center gap-1.5">
                                <i class="fa-solid fa-paper-plane text-xs text-emerald-600"></i>
                                <span class="text-sm font-semibold text-[var(--color-ink-strong)]">Automatic Dispatch</span>
                            </div>
                            <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">Compiles and directly emails client recipients on the scheduled date.</p>
                        </div>
                    </label>
                    <label class="card p-3.5 flex items-start gap-3 cursor-pointer hover:bg-[var(--color-surface-alt)]/60 transition-colors border border-[var(--color-border-light)] rounded-xl">
                        <input type="radio" name="delivery_mode" value="draft" {{ $mode === 'draft' ? 'checked' : '' }} class="mt-1 text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                        <div>
                            <div class="flex items-center gap-1.5">
                                <i class="fa-solid fa-file-pen text-xs text-amber-600"></i>
                                <span class="text-sm font-semibold text-[var(--color-ink-strong)]">Draft for Review</span>
                            </div>
                            <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">Generates report into history without emailing, allowing operator review.</p>
                        </div>
                    </label>
                </div>
            </div>

            {{-- Recipient Emails --}}
            <div>
                <label for="recipients" class="block text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-strong)] mb-1.5">
                    Recipient Email Addresses
                </label>
                @php
                    $recipientsVal = old('recipients', is_array($schedule->recipients) ? implode("\n", $schedule->recipients) : $schedule->recipients);
                @endphp
                <textarea name="recipients"
                          id="recipients"
                          rows="3"
                          placeholder="client@example.com&#10;manager@example.com"
                          class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-sm font-mono text-[var(--color-ink-strong)] focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]">{{ $recipientsVal }}</textarea>
                <p class="text-[11px] text-[var(--color-ink-muted)] mt-1">
                    Enter one or more email addresses separated by newlines or commas. Required for Automatic Dispatch mode.
                </p>
            </div>

            {{-- Enabled Toggle --}}
            <div class="pt-2 border-t border-[var(--color-border-light)]">
                <label class="flex items-start gap-3 cursor-pointer select-none">
                    <input type="checkbox"
                           name="is_enabled"
                           value="1"
                           {{ old('is_enabled', $schedule->is_enabled ?? true) ? 'checked' : '' }}
                           class="mt-1 rounded border-[var(--color-border)] text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                    <div>
                        <span class="text-sm font-semibold text-[var(--color-ink-strong)]">Enable Scheduled Runs</span>
                        <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                            When enabled, the automated daily cron task (<code>06:00</code>) checks and dispatches this report when due.
                        </p>
                    </div>
                </label>
            </div>

            {{-- Submit Buttons --}}
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-[var(--color-border-light)]">
                <a href="{{ route('client-reports.schedules.index') }}" class="btn-pill-nav text-xs py-2 px-4">
                    Cancel
                </a>
                <button type="submit" class="btn-pill-primary text-xs py-2 px-5">
                    <i class="fa-solid fa-check mr-1.5"></i>
                    {{ $schedule->exists ? 'Save Schedule' : 'Create Schedule' }}
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
