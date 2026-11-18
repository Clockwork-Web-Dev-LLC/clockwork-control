@extends('layouts.app')

@section('title', 'AI visibility · '.$site->domain.' · Clockwork')

@section('content')
<x-page-header :title="$site->domain" subtitle="AI visibility: how often this client comes up when AI assistants are asked its tracked questions." />

@include('seo::_nav')
@include('seo::sites._tabs')
@include('seo::_flash')

@php
    $inputClass = 'mt-1 text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full';
    $cell = function (?array $r) {
        if ($r === null) { return ['—', 'text-[var(--color-ink-soft)]', 'Not checked yet']; }
        if (! $r['answered']) { return ['No answer', 'text-[var(--color-ink-soft)]', 'The engine gave no answer on '.$r['checked_on']]; }
        if ($r['cited']) { return [$r['mentioned'] ? 'Mentioned + cited' : 'Cited', 'text-[var(--color-status-green)] font-semibold', $r['checked_on']]; }
        if ($r['mentioned']) { return ['Mentioned', 'text-[var(--color-status-green)]', $r['checked_on']]; }
        return ['Not there', 'text-[var(--color-ink-muted)]', 'Checked '.$r['checked_on'].': the client did not come up'];
    };
@endphp

@unless ($configured)
    <div class="mb-6 p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-700 dark:text-amber-300 text-sm" role="alert">
        Asking the AI engines needs a DataForSEO API login. You can add prompts now; nothing is asked until an admin adds the login under SEO on Settings → Integrations.
    </div>
@endunless

@if ($brandTerms === [])
    <div class="mb-6 p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-700 dark:text-amber-300 text-sm" role="alert" data-no-brand-terms>
        No brand terms are set for this client, so a mention can only be found by its domain name. Add the names it is known by on the <a href="{{ route('seo.sites.profile', $site) }}" class="underline font-semibold">Profile tab</a>.
    </div>
@endif

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-2">
    @foreach ($engines as $key => [$label])
        @php $e = $report['engines'][$key] ?? null; @endphp
        <div class="card p-5 {{ in_array($key, $activeEngines, true) ? '' : 'opacity-60' }}" data-engine="{{ $key }}">
            <p class="text-xs uppercase tracking-wider text-[var(--color-ink-muted)] font-semibold">{{ $label }}{{ in_array($key, $activeEngines, true) ? '' : ' · off' }}</p>
            @if ($e)
                <p class="font-display text-2xl font-semibold text-[var(--color-ink-strong)] mt-1">{{ $e['mentioned'] }} <span class="text-sm font-normal text-[var(--color-ink-muted)]">of {{ $e['checked'] }} prompts mentioned</span></p>
                <p class="text-xs text-[var(--color-ink-muted)] mt-1">Cited as a source in {{ $e['cited'] }} · mention rate {{ $e['mention_rate'] }}%</p>
            @else
                <p class="font-display text-2xl font-semibold text-[var(--color-ink-soft)] mt-1">—</p>
                <p class="text-xs text-[var(--color-ink-muted)] mt-1">No answers stored yet</p>
            @endif
        </div>
    @endforeach
</div>
<p class="text-xs text-[var(--color-ink-soft)] mb-6">
    Latest round{{ $report['last_checked'] ? ' ('.$report['last_checked'].')' : '' }}, asked {{ $frequency }}. {{ $report['active'] }} of {{ $max }} prompts in use, about ${{ number_format($monthlyEstimate, 2) }} a month.
    @if ($pending > 0) {{ $pending }} {{ Str::plural('answer', $pending) }} waiting on DataForSEO across all clients. @endif
    An AI answer changes each time it is asked, so one check is a sample: read the counts across all prompts and over several weeks, not one cell. This does not say how many people saw an answer.
</p>

@if ($competitors !== [] && $report['competitors'] !== [])
    <x-card title="Competitors on the same prompts" icon="fa-flag-checkered" class="mb-6">
        <table class="w-full text-sm" data-ai-competitors>
            <thead class="text-left text-xs uppercase tracking-wider text-[var(--color-ink-muted)]">
                <tr><th class="py-1 font-semibold">Domain</th>@foreach ($engines as $key => [$label])<th class="py-1 font-semibold text-right">{{ $label }}</th>@endforeach</tr>
            </thead>
            <tbody class="divide-y divide-[var(--color-border-light)]">
                <tr><td class="py-1 font-data">{{ $site->domain }} <span class="status-pill status-green text-[10px]">Client</span></td>
                    @foreach ($engines as $key => $_)
                        <td class="py-1 text-right font-data">{{ isset($report['engines'][$key]) ? $report['engines'][$key]['mentioned'].' / '.$report['engines'][$key]['cited'] : '—' }}</td>
                    @endforeach
                </tr>
                @foreach ($competitors as $competitor)
                    <tr><td class="py-1 font-data">{{ $competitor }}</td>
                        @foreach ($engines as $key => $_)
                            @php $c = $report['competitors'][$competitor][$key] ?? null; @endphp
                            <td class="py-1 text-right font-data">{{ $c ? $c['mentioned'].' / '.$c['cited'] : '—' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="text-xs text-[var(--color-ink-soft)] mt-2">Prompts mentioned / prompts cited, latest round. A competitor is matched by its domain and the name in it, so one known by a different name than its domain is under-counted.</p>
    </x-card>
@endif

<section class="card mb-6">
    <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]"><i class="fa-solid fa-comments text-[var(--color-ink-soft)] mr-2"></i>Prompts</h2>
        <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Choose a prompt to read what each engine answered.</p>
    </div>
    @if ($report['prompts'] === [])
        <p class="px-5 py-8 text-sm text-[var(--color-ink-muted)] text-center">No prompts yet. Add some below.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wider text-[var(--color-ink-muted)]">
                    <tr><th class="px-5 py-2 font-semibold">Prompt</th>@foreach ($engines as $key => [$label])<th class="px-5 py-2 font-semibold">{{ $label }}</th>@endforeach<th class="px-5 py-2 font-semibold"></th></tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($report['prompts'] as $row)
                        <tr data-prompt-row="{{ $row['id'] }}" class="{{ $row['is_active'] ? '' : 'opacity-60' }}">
                            <td class="px-5 py-2">
                                <a href="{{ route('seo.sites.ai-visibility', [$site, 'prompt' => $row['id']]) }}#answers" class="text-[var(--color-primary-600)] hover:underline">{{ $row['prompt'] }}</a>
                                @unless ($row['is_active'])<span class="text-xs text-[var(--color-ink-soft)]">· paused</span>@endunless
                            </td>
                            @foreach ($engines as $key => $_)
                                @php [$text, $class, $title] = $cell($row['engines'][$key]); @endphp
                                <td class="px-5 py-2 text-xs whitespace-nowrap {{ $class }}" title="{{ $title }}" data-engine-cell="{{ $key }}">{{ $text }}</td>
                            @endforeach
                            <td class="px-5 py-2 text-right whitespace-nowrap">
                                <form method="POST" action="{{ route('seo.sites.ai-visibility.toggle', [$site, $row['id']]) }}" class="inline">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="btn-pill-nav text-xs">{{ $row['is_active'] ? 'Pause' : 'Resume' }}</button>
                                </form>
                                <form method="POST" action="{{ route('seo.sites.ai-visibility.destroy', [$site, $row['id']]) }}" class="inline"
                                      onsubmit="return confirm('Delete this prompt and all of its stored answers? This cannot be undone. Pause it instead to keep the history.')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn-pill-nav text-xs" aria-label="Delete prompt"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

@if ($selected)
    <x-card :title="'Answers: '.$selected->prompt" icon="fa-quote-left" class="mb-6" id="answers">
        @if ($answers->isEmpty())
            <p class="text-sm text-[var(--color-ink-muted)]">No answers stored for this prompt yet.</p>
        @else
            <div class="space-y-4" data-answers>
                @foreach ($answers as $a)
                    <div class="text-sm">
                        <p class="text-xs text-[var(--color-ink-muted)] mb-1">
                            <span class="font-semibold text-[var(--color-ink-strong)]">{{ $engines[$a->engine][0] ?? $a->engine }}</span> · {{ $a->checked_on->toDateString() }} ·
                            {{ ! $a->answered ? 'no answer' : ($a->mentioned ? 'mentioned' : 'not mentioned').', '.($a->cited ? 'cited' : 'not cited') }}
                        </p>
                        @if ($a->excerpt)
                            <p class="text-xs text-[var(--color-ink)] whitespace-pre-line border-l-2 border-[var(--color-border)] pl-3">{{ Str::limit($a->excerpt, 900) }}</p>
                        @endif
                        @if ($a->sources)
                            <p class="text-xs text-[var(--color-ink-soft)] mt-1 font-data">Sources: {{ implode(', ', array_slice($a->sources, 0, 12)) }}{{ count($a->sources) > 12 ? '…' : '' }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
            <p class="text-xs text-[var(--color-ink-soft)] mt-3">The start of each answer as stored. It is what the AI engine wrote, not Control's view.</p>
        @endif
    </x-card>
@endif

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <x-card title="Add prompts" icon="fa-plus">
        <form method="POST" action="{{ route('seo.sites.ai-visibility.store', $site) }}">
            @csrf
            <label class="block text-sm mb-3">
                <span class="font-medium text-[var(--color-ink-strong)]">Prompts, one per line</span>
                <textarea name="prompts" rows="6" required class="{{ $inputClass }}" placeholder="Who is the best emergency plumber in Austin?&#10;How much does it cost to replace a water heater?">{{ old('prompts') }}</textarea>
                <span class="block text-xs text-[var(--color-ink-soft)] mt-1">Write the question a customer would ask an AI assistant, in full, with the city for a local business. Do not name the client in it: the point is to see whether it comes up unprompted.</span>
            </label>
            <p class="text-xs text-[var(--color-ink-muted)] mb-4">Each prompt costs about ${{ number_format($perPromptMonthly, 4) }} a month at the current settings ({{ $frequency }}, {{ count($activeEngines) }} {{ Str::plural('engine', count($activeEngines)) }}).</p>
            <button type="submit" class="btn-primary text-sm px-4 py-2">Track these prompts</button>
        </form>
    </x-card>

    @if ($report['weeks'] !== [])
        <x-card title="By week" icon="fa-chart-line">
            <table class="w-full text-sm" data-ai-weeks>
                <thead class="text-left text-xs uppercase tracking-wider text-[var(--color-ink-muted)]">
                    <tr><th class="py-1 font-semibold">Week of</th><th class="py-1 font-semibold text-right">Answers</th><th class="py-1 font-semibold text-right">Mentioned</th><th class="py-1 font-semibold text-right">Cited</th></tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach (array_reverse($report['weeks']) as $w)
                        <tr><td class="py-1 font-data">{{ $w['week'] }}</td><td class="py-1 text-right font-data">{{ $w['checks'] }}</td><td class="py-1 text-right font-data">{{ $w['mentioned'] }} ({{ $w['mention_rate'] }}%)</td><td class="py-1 text-right font-data">{{ $w['cited'] }} ({{ $w['citation_rate'] }}%)</td></tr>
                    @endforeach
                </tbody>
            </table>
            <p class="text-xs text-[var(--color-ink-soft)] mt-2">All engines together. A week with few answers swings widely.</p>
        </x-card>
    @endif
</div>
@endsection
