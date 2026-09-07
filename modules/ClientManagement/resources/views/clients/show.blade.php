@extends('layouts.app')

@section('title', "{$client->name} · Clients · Clockwork Control")

@section('content')
<div x-data="{ showEditModal: false, showAssignModal: false }">
    <x-page-header :title="$client->name"
                   :subtitle="$client->company_name ? $client->company_name . ' · Managed Client Account' : 'Managed Client Account'">
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <a href="{{ route('clients.index') }}" class="btn-pill-secondary">
                    <i class="fa-solid fa-arrow-left text-xs"></i>
                    <span>All Clients</span>
                </a>
                <button type="button" @click="showEditModal = true" class="btn-pill-secondary">
                    <i class="fa-solid fa-pen-to-square text-xs"></i>
                    <span>Edit Profile</span>
                </button>
            </div>
        </x-slot:actions>
    </x-page-header>

    @include('client-reports::_tabs', ['activeTab' => 'clients'])

    @if (session('status'))
        <div class="card p-4 mb-6 status-green flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i>
            <span>{{ session('status') }}</span>
        </div>
    @endif
    @if (session('status_error'))
        <div class="card p-4 mb-6 status-red flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>{{ session('status_error') }}</span>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
        {{-- Left Column: Client Contact Information --}}
        <div class="space-y-6">
            <div class="card p-6">
                <div class="flex items-center gap-3 mb-5 pb-4 border-b border-[var(--color-border-light)]">
                    <div class="w-12 h-12 rounded-full bg-[var(--color-brand)]/10 text-[var(--color-brand)] font-display font-bold text-base flex items-center justify-center shrink-0">
                        {{ strtoupper(substr($client->name, 0, 1)) }}{{ strtoupper(substr($client->company_name ?? '', 0, 1)) }}
                    </div>
                    <div class="min-w-0">
                        <h2 class="font-display font-bold text-base text-[var(--color-ink-strong)] truncate">
                            {{ $client->name }}
                        </h2>
                        @if ($client->company_name)
                            <div class="text-xs text-[var(--color-ink-muted)] flex items-center gap-1 mt-0.5 truncate">
                                <i class="fa-regular fa-building text-[10px]"></i>
                                <span>{{ $client->company_name }}</span>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="space-y-4 text-xs">
                    <div>
                        <span class="block text-[10px] font-semibold uppercase tracking-wider text-[var(--color-ink-muted)] mb-1">
                            Primary Report Email
                        </span>
                        <a href="mailto:{{ $client->email }}" class="font-mono text-xs text-[var(--color-brand)] hover:underline flex items-center gap-1.5">
                            <i class="fa-regular fa-envelope text-[10px]"></i>
                            <span>{{ $client->email }}</span>
                        </a>
                    </div>

                    @if (!empty($client->additional_emails))
                        <div>
                            <span class="block text-[10px] font-semibold uppercase tracking-wider text-[var(--color-ink-muted)] mb-1">
                                Additional CC Recipients ({{ count($client->additional_emails) }})
                            </span>
                            <div class="space-y-1 font-mono text-[11px] text-[var(--color-ink-strong)]">
                                @foreach ($client->additional_emails as $cc)
                                    <div class="flex items-center gap-1.5">
                                        <i class="fa-solid fa-at text-[9px] text-[var(--color-ink-soft)]"></i>
                                        <span>{{ $cc }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($client->phone)
                        <div>
                            <span class="block text-[10px] font-semibold uppercase tracking-wider text-[var(--color-ink-muted)] mb-1">
                                Phone Number
                            </span>
                            <div class="text-xs text-[var(--color-ink-strong)] flex items-center gap-1.5">
                                <i class="fa-solid fa-phone text-[10px] text-[var(--color-ink-soft)]"></i>
                                <span>{{ $client->phone }}</span>
                            </div>
                        </div>
                    @endif

                    @if ($client->address)
                        <div>
                            <span class="block text-[10px] font-semibold uppercase tracking-wider text-[var(--color-ink-muted)] mb-1">
                                Physical / Billing Address
                            </span>
                            <div class="text-xs text-[var(--color-ink-strong)] leading-relaxed whitespace-pre-line">
                                {{ $client->address }}
                            </div>
                        </div>
                    @endif

                    @if ($client->notes)
                        <div>
                            <span class="block text-[10px] font-semibold uppercase tracking-wider text-[var(--color-ink-muted)] mb-1">
                                Internal Agency Notes
                            </span>
                            <div class="text-xs text-[var(--color-ink-muted)] bg-[var(--color-surface-alt)] p-3 rounded-xl border border-[var(--color-border-light)] leading-relaxed whitespace-pre-line">
                                {{ $client->notes }}
                            </div>
                        </div>
                    @endif

                    <div class="pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between text-[11px] text-[var(--color-ink-soft)]">
                        <span>Created {{ $client->created_at->format('M j, Y') }}</span>
                        <form method="POST" action="{{ route('clients.destroy', $client) }}" onsubmit="return confirm('Permanently delete {{ $client->name }}?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-rose-600 hover:text-rose-700 hover:underline">
                                Delete Client
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            {{-- Quick Stats Card --}}
            <div class="card p-4">
                <div class="grid grid-cols-2 gap-3 text-center">
                    <div class="p-3 bg-[var(--color-surface-alt)] rounded-xl">
                        <div class="text-2xl font-bold font-display text-[var(--color-ink-strong)]">
                            {{ $client->sites->count() }}
                        </div>
                        <div class="text-[10px] text-[var(--color-ink-muted)] mt-0.5">Assigned Sites</div>
                    </div>
                    <div class="p-3 bg-[var(--color-surface-alt)] rounded-xl">
                        <div class="text-2xl font-bold font-display text-[var(--color-ink-strong)]">
                            {{ $client->reports->count() }}
                        </div>
                        <div class="text-[10px] text-[var(--color-ink-muted)] mt-0.5">Reports Sent</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Right Column: Assigned Fleet Sites & Reports History --}}
        <div class="lg:col-span-2 space-y-6">
            {{-- Assigned Fleet Sites Card --}}
            <div class="card overflow-hidden">
                <div class="p-4 sm:p-5 border-b border-[var(--color-border-light)] flex items-center justify-between flex-wrap gap-3">
                    <div>
                        <h2 class="font-display font-semibold text-base text-[var(--color-ink-strong)] flex items-center gap-2">
                            <i class="fa-solid fa-globe text-[var(--color-brand)]"></i>
                            Assigned Website Fleet
                        </h2>
                        <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                            Websites managed under {{ $client->name }}'s account. Reports automatically route to this client.
                        </p>
                    </div>
                    <button type="button" @click="showAssignModal = true" class="btn-pill-primary text-xs py-1.5 px-3">
                        <i class="fa-solid fa-plus text-xs"></i>
                        <span>Assign Website</span>
                    </button>
                </div>

                @if ($client->sites->isEmpty())
                    <div class="text-center py-10 px-6">
                        <div class="w-12 h-12 rounded-full bg-[var(--color-surface-alt)] flex items-center justify-center mx-auto mb-3 text-[var(--color-brand)]">
                            <i class="fa-solid fa-globe text-xl opacity-60"></i>
                        </div>
                        <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] mb-1">
                            No websites assigned yet
                        </h3>
                        <p class="text-xs text-[var(--color-ink-muted)] max-w-sm mx-auto mb-4">
                            Connect websites to this client to automatically associate reports and populate email recipients.
                        </p>
                        <button type="button" @click="showAssignModal = true" class="btn-pill-secondary text-xs">
                            <i class="fa-solid fa-plus text-xs"></i>
                            <span>Assign Website to Client</span>
                        </button>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] uppercase tracking-wider text-[10px]">
                                <tr>
                                    <th class="px-5 py-3 font-semibold">Website Domain</th>
                                    <th class="px-5 py-3 font-semibold">Care Plan</th>
                                    <th class="px-5 py-3 font-semibold text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--color-border-light)]">
                                @foreach ($client->sites as $site)
                                    <tr class="hover:bg-[var(--color-surface-alt)]/50 transition-colors">
                                        <td class="px-5 py-3.5">
                                            <a href="{{ route('sites.show', $site) }}" class="font-mono text-xs font-semibold text-[var(--color-brand)] hover:underline flex items-center gap-1.5">
                                                <i class="fa-solid fa-globe text-[10px]"></i>
                                                <span>{{ $site->domain }}</span>
                                            </a>
                                        </td>
                                        <td class="px-5 py-3.5">
                                            @if ($site->care_plan_enabled)
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700">
                                                    <i class="fa-solid fa-check text-[9px]"></i> Active Care Plan
                                                </span>
                                            @else
                                                <span class="text-[var(--color-ink-soft)] text-[11px]">&mdash;</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                            <div class="flex items-center justify-end gap-2">
                                                <a href="{{ route('sites.show', $site) }}" class="btn-pill-secondary text-xs py-1 px-3">
                                                    <span>Site Dashboard</span>
                                                </a>
                                                <form method="POST" action="{{ route('clients.unassign-site', ['client' => $client, 'site' => $site]) }}" class="inline" onsubmit="return confirm('Unassign {{ $site->domain }} from {{ $client->name }}?');">
                                                    @csrf
                                                    <button type="submit" class="btn-pill-secondary text-xs py-1 px-2.5 text-[var(--color-ink-muted)] hover:text-rose-600" title="Unassign Site">
                                                        <i class="fa-solid fa-link-slash text-[10px]"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Client Reports History Card --}}
            <div class="card overflow-hidden">
                <div class="p-4 sm:p-5 border-b border-[var(--color-border-light)] flex items-center justify-between flex-wrap gap-3">
                    <div>
                        <h2 class="font-display font-semibold text-base text-[var(--color-ink-strong)] flex items-center gap-2">
                            <i class="fa-solid fa-clock-rotate-left text-[var(--color-ink-soft)]"></i>
                            Reports History for {{ $client->name }}
                        </h2>
                        <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                            Executive maintenance and performance summaries generated for this client.
                        </p>
                    </div>
                    <span class="text-xs text-[var(--color-ink-muted)] font-medium">
                        {{ $client->reports->count() }} {{ \Illuminate\Support\Str::plural('report', $client->reports->count()) }}
                    </span>
                </div>

                @if ($client->reports->isEmpty())
                    <div class="text-center py-10 px-6">
                        <div class="w-12 h-12 rounded-full bg-[var(--color-surface-alt)] flex items-center justify-center mx-auto mb-3 text-[var(--color-brand)]">
                            <i class="fa-solid fa-file-invoice text-xl opacity-60"></i>
                        </div>
                        <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] mb-1">
                            No reports on record
                        </h3>
                        <p class="text-xs text-[var(--color-ink-muted)] max-w-sm mx-auto mb-4">
                            When reports are compiled for {{ $client->name }}'s websites, they will appear here.
                        </p>
                        <a href="{{ route('client-reports.index') }}" class="btn-pill-secondary text-xs">
                            <i class="fa-solid fa-wand-magic-sparkles text-xs"></i>
                            <span>Go to Reports Generator</span>
                        </a>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] uppercase tracking-wider text-[10px]">
                                <tr>
                                    <th class="px-5 py-3 font-semibold">Report Title</th>
                                    <th class="px-5 py-3 font-semibold">Target Site</th>
                                    <th class="px-5 py-3 font-semibold">Period</th>
                                    <th class="px-5 py-3 font-semibold">Status</th>
                                    <th class="px-5 py-3 font-semibold text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--color-border-light)]">
                                @foreach ($client->reports as $report)
                                    <tr class="hover:bg-[var(--color-surface-alt)]/50 transition-colors">
                                        <td class="px-5 py-3.5 font-semibold text-[var(--color-ink-strong)]">
                                            {{ $report->title }}
                                        </td>
                                        <td class="px-5 py-3.5">
                                            <a href="{{ route('sites.show', $report->site) }}" class="font-mono text-[11px] text-[var(--color-brand)] hover:underline">
                                                {{ $report->site->domain }}
                                            </a>
                                        </td>
                                        <td class="px-5 py-3.5 whitespace-nowrap text-[var(--color-ink-muted)]">
                                            {{ $report->period_start->format('M j') }} &ndash; {{ $report->period_end->format('M j, Y') }}
                                        </td>
                                        <td class="px-5 py-3.5 whitespace-nowrap">
                                            @if ($report->status === 'sent')
                                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700">
                                                    <i class="fa-solid fa-check text-[9px]"></i> Sent
                                                </span>
                                            @else
                                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)]">
                                                    <i class="fa-regular fa-file text-[9px]"></i> Draft
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                            <div class="flex items-center justify-end gap-2">
                                                <a href="{{ route('client-reports.show', $report) }}" target="_blank" class="btn-pill-secondary text-xs py-1 px-3">
                                                    <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                                                    <span>View</span>
                                                </a>
                                                <form method="POST" action="{{ route('client-reports.send', $report) }}" class="inline" onsubmit="return confirm('Send report by email?');">
                                                    @csrf
                                                    <button type="submit" class="btn-pill-secondary text-xs py-1 px-3 hover:text-[var(--color-brand)]">
                                                        <i class="fa-solid fa-paper-plane text-[10px]"></i>
                                                        <span>Send</span>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Assign Site Modal --}}
    <div x-show="showAssignModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" role="dialog">
        <div class="fixed inset-0 bg-black/50 backdrop-blur-xs" @click="showAssignModal = false"></div>
        <div class="min-h-full flex items-center justify-center p-4">
            <div class="w-full max-w-md bg-[var(--color-surface)] rounded-[var(--radius-card)] border border-[var(--color-border-light)] shadow-2xl overflow-hidden z-10 p-6">
                <div class="flex items-center justify-between mb-4 border-b border-[var(--color-border-light)] pb-3">
                    <h3 class="font-display font-semibold text-base text-[var(--color-ink-strong)]">
                        Assign Website to {{ $client->name }}
                    </h3>
                    <button type="button" @click="showAssignModal = false" class="text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <form method="POST" action="{{ route('clients.assign-site', $client) }}" class="space-y-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                            Choose Website Domain <span class="text-rose-500">*</span>
                        </label>
                        <select name="site_id" required class="w-full text-xs px-3 py-2.5 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] focus:outline-none">
                            <option value="">Select a website from fleet…</option>
                            @foreach ($availableSites as $s)
                                <option value="{{ $s->id }}">{{ $s->domain }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="pt-3 border-t border-[var(--color-border-light)] flex items-center justify-end gap-2">
                        <button type="button" @click="showAssignModal = false" class="btn-pill-secondary text-xs">
                            Cancel
                        </button>
                        <button type="submit" class="btn-pill-primary text-xs">
                            <span>Assign Website</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Edit Profile Modal --}}
    <div x-show="showEditModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" role="dialog">
        <div class="fixed inset-0 bg-black/50 backdrop-blur-xs" @click="showEditModal = false"></div>
        <div class="min-h-full flex items-center justify-center p-4">
            <div class="w-full max-w-2xl bg-[var(--color-surface)] rounded-[var(--radius-card)] border border-[var(--color-border-light)] shadow-2xl overflow-hidden z-10 p-6">
                <div class="flex items-center justify-between mb-4 border-b border-[var(--color-border-light)] pb-3">
                    <h3 class="font-display font-semibold text-base text-[var(--color-ink-strong)]">
                        Edit {{ $client->name }} Profile
                    </h3>
                    <button type="button" @click="showEditModal = false" class="text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <form method="POST" action="{{ route('clients.update', $client) }}" class="space-y-4">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Contact Name <span class="text-rose-500">*</span></label>
                            <input type="text" name="name" value="{{ $client->name }}" required class="w-full text-xs px-3 py-2 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Company</label>
                            <input type="text" name="company_name" value="{{ $client->company_name }}" class="w-full text-xs px-3 py-2 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Primary Email <span class="text-rose-500">*</span></label>
                            <input type="email" name="email" value="{{ $client->email }}" required class="w-full text-xs px-3 py-2 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Phone</label>
                            <input type="text" name="phone" value="{{ $client->phone }}" class="w-full text-xs px-3 py-2 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Additional CC Emails</label>
                        <input type="text" name="additional_emails" value="{{ !empty($client->additional_emails) ? implode(', ', $client->additional_emails) : '' }}" class="w-full text-xs px-3 py-2 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] font-mono">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Address</label>
                        <input type="text" name="address" value="{{ $client->address }}" class="w-full text-xs px-3 py-2 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Notes</label>
                        <textarea name="notes" rows="2" class="w-full text-xs p-3 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]">{{ $client->notes }}</textarea>
                    </div>

                    <div class="pt-3 border-t border-[var(--color-border-light)] flex items-center justify-end gap-2">
                        <button type="button" @click="showEditModal = false" class="btn-pill-secondary text-xs">
                            Cancel
                        </button>
                        <button type="submit" class="btn-pill-primary text-xs">
                            <span>Save Changes</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
