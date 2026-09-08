@extends('layouts.app')

@section('title', ($template->exists ? 'Edit Template: '.$template->name : 'New Report Template').' · Clockwork')

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
        <a href="{{ route('client-reports.templates.index') }}" class="btn-pill-nav text-xs">
            <i class="fa-solid fa-arrow-left mr-1"></i> Back to Templates
        </a>
    </div>
</div>

@include('client-reports::_nav')

<div class="max-w-3xl">
    <div class="card p-6 md:p-8">
        <div class="mb-6">
            <h2 class="font-display font-semibold text-lg text-[var(--color-ink-strong)] flex items-center gap-2">
                <i class="fa-solid fa-layer-group text-[var(--color-brand)]"></i>
                {{ $template->exists ? 'Edit Template: '.$template->name : 'Create Report Template' }}
            </h2>
            <p class="text-xs text-[var(--color-ink-muted)] mt-1">
                Select which analytical and maintenance sections to include when compiling reports with this template.
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

        <form method="POST" action="{{ $template->exists ? route('client-reports.templates.update', $template) : route('client-reports.templates.store') }}" class="space-y-6">
            @csrf
            @if ($template->exists)
                @method('PUT')
            @endif

            {{-- Template Name --}}
            <div>
                <label for="name" class="block text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-strong)] mb-1.5">
                    Template Name <span class="text-rose-500">*</span>
                </label>
                <input type="text"
                       name="name"
                       id="name"
                       value="{{ old('name', $template->name) }}"
                       required
                       placeholder="e.g. Standard Care Plan, Executive Summary, E-Commerce Tier"
                       class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]">
                <p class="text-[11px] text-[var(--color-ink-muted)] mt-1">
                    A clear, recognizable identifier for operators when selecting report templates.
                </p>
            </div>

            {{-- Included Sections Checklist --}}
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-strong)] mb-2">
                    Included Report Sections <span class="text-rose-500">*</span>
                </label>
                <p class="text-xs text-[var(--color-ink-muted)] mb-3">
                    Choose at least one section. Core sections (Site identity, reporting period, and agency branding) are always included automatically.
                </p>

                <div class="space-y-2.5">
                    @foreach ($sections as $key => $meta)
                        @php
                            $checked = in_array($key, (array) old('sections', $template->sections ?? []));
                        @endphp
                        <label class="card p-3.5 flex items-start gap-3 cursor-pointer hover:bg-[var(--color-surface-alt)]/60 transition-colors border border-[var(--color-border-light)] rounded-xl">
                            <input type="checkbox"
                                   name="sections[]"
                                   value="{{ $key }}"
                                   {{ $checked ? 'checked' : '' }}
                                   class="mt-1 rounded border-[var(--color-border)] text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <i class="fa-solid {{ $meta['icon'] }} text-xs text-[var(--color-brand)]"></i>
                                    <span class="text-sm font-semibold text-[var(--color-ink-strong)]">{{ $meta['label'] }}</span>
                                </div>
                                <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">{{ $meta['description'] }}</p>
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- Default Template Option --}}
            <div class="pt-2 border-t border-[var(--color-border-light)]">
                <label class="flex items-start gap-3 cursor-pointer select-none">
                    <input type="checkbox"
                           name="is_default"
                           value="1"
                           {{ old('is_default', $template->is_default) ? 'checked' : '' }}
                           class="mt-1 rounded border-[var(--color-border)] text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                    <div>
                        <span class="text-sm font-semibold text-[var(--color-ink-strong)]">Set as Default Template</span>
                        <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                            Automatically preselected when generating manual reports and creating new automated schedules.
                        </p>
                    </div>
                </label>
            </div>

            {{-- Action Buttons --}}
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-[var(--color-border-light)]">
                <a href="{{ route('client-reports.templates.index') }}" class="btn-pill-nav text-xs py-2 px-4">
                    Cancel
                </a>
                <button type="submit" class="btn-pill-primary text-xs py-2 px-5">
                    <i class="fa-solid fa-check mr-1.5"></i>
                    {{ $template->exists ? 'Save Changes' : 'Create Template' }}
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
