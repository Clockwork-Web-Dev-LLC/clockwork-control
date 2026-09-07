@extends('layouts.app')

@section('title', 'Client Reports · Clockwork')

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
        <a href="#generate-modal" onclick="document.getElementById('generate-panel').scrollIntoView({behavior: 'smooth'})" class="btn-pill-primary text-xs">
            <i class="fa-solid fa-plus mr-1"></i> Generate New Report
        </a>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
    {{-- Main Reports List --}}
    <div class="lg:col-span-8 space-y-6">
        <div class="card p-5">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-display font-semibold text-base text-[var(--color-ink-strong)] flex items-center gap-2">
                    <i class="fa-solid fa-clock-rotate-left text-[var(--color-ink-soft)]"></i>
                    Generated Reports History
                </h2>
                <span class="text-xs text-[var(--color-ink-muted)]">{{ $reports->total() }} reports</span>
            </div>

            @if ($reports->isEmpty())
                <div class="text-center py-12 text-sm text-[var(--color-ink-soft)]">
                    <i class="fa-solid fa-file-circle-check text-4xl mb-3 block opacity-30"></i>
                    No reports generated yet. Use the generator on the right to compile your first client report!
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left">
                        <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] uppercase tracking-wider text-[10px]">
                            <tr>
                                <th class="px-4 py-3">Site & Title</th>
                                <th class="px-4 py-3">Period</th>
                                <th class="px-4 py-3">Client</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-border-light)]">
                            @foreach ($reports as $r)
                                <tr class="hover:bg-[var(--color-surface-alt)]/50 transition-colors">
                                    <td class="px-4 py-3 font-medium text-[var(--color-ink-strong)]">
                                        <div>{{ $r->title }}</div>
                                        <div class="text-[11px] text-[var(--color-ink-muted)] font-mono">{{ $r->site->domain }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-[var(--color-ink-soft)] whitespace-nowrap">
                                        {{ $r->period_start->format('M j') }} &ndash; {{ $r->period_end->format('M j, Y') }}
                                    </td>
                                    <td class="px-4 py-3 text-[var(--color-ink-soft)]">
                                        {{ $r->client_name ?: '—' }}
                                        @if ($r->client_email)
                                            <div class="text-[10px] text-[var(--color-ink-muted)]">{{ $r->client_email }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $r->status === 'sent' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">
                                            {{ ucfirst($r->status) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-2">
                                            <a href="{{ route('client-reports.show', $r) }}" target="_blank" class="btn-pill-nav text-xs py-1 px-2.5" title="View / Print">
                                                <i class="fa-solid fa-arrow-up-right-from-square mr-1"></i> View
                                            </a>
                                            <form method="POST" action="{{ route('client-reports.send', $r) }}" class="inline" onsubmit="return confirm('Email report to client?');">
                                                @csrf
                                                <button type="submit" class="btn-pill-nav text-xs py-1 px-2.5 text-indigo-600 hover:bg-indigo-50" title="Send Email">
                                                    <i class="fa-solid fa-paper-plane mr-1"></i> Send
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">
                    {{ $reports->links() }}
                </div>
            @endif
        </div>
    </div>

    {{-- Right Column: Generate Report Form & Schedules --}}
    <div class="lg:col-span-4 space-y-6" id="generate-panel">
        {{-- Generate Report Panel --}}
        <div class="card p-5">
            <h2 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] mb-1 flex items-center gap-2">
                <i class="fa-solid fa-wand-magic-sparkles text-[var(--color-brand)]"></i>
                Generate Client Report
            </h2>
            <p class="text-xs text-[var(--color-ink-soft)] mb-4">
                Compile data from updates, uptime, security scans, and performance benchmarks.
            </p>

            <form method="POST" action="{{ route('client-reports.generate') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Target Site</label>
                    <select name="site_id" required class="w-full text-xs px-3 py-2 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface)]">
                        <option value="" disabled selected>Select a website...</option>
                        @foreach ($sites as $s)
                            <option value="{{ $s->id }}">{{ $s->domain }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">From Date</label>
                        <input type="date" name="period_start" value="{{ now()->subMonth()->startOfMonth()->toDateString() }}" required
                               class="w-full text-xs px-2.5 py-1.5 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface)]">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">To Date</label>
                        <input type="date" name="period_end" value="{{ now()->subMonth()->endOfMonth()->toDateString() }}" required
                               class="w-full text-xs px-2.5 py-1.5 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface)]">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Client Name (optional)</label>
                    <input type="text" name="client_name" placeholder="Acme Corp"
                           class="w-full text-xs px-3 py-2 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface)]">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Client Email (optional)</label>
                    <input type="email" name="client_email" placeholder="client@example.com"
                           class="w-full text-xs px-3 py-2 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface)]">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Custom Notes</label>
                    <textarea name="custom_notes" rows="2" placeholder="Everything is running smoothly! Next month we will..."
                              class="w-full text-xs px-3 py-2 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface)]"></textarea>
                </div>

                <button type="submit" class="w-full btn-pill-primary text-xs py-2">
                    <i class="fa-solid fa-file-export mr-1"></i> Generate & Preview Report
                </button>
            </form>
        </div>
    </div>
</div>
@endsection
