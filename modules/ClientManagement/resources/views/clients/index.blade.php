@extends('layouts.app')

@section('title', 'Clients & Recipients · Clockwork Control')

@section('content')
<div x-data="clientsWorkbench({
    clients: {{ json_encode($clients->items()) }},
    allSites: {{ json_encode($allSites) }}
})">
    <x-page-header title="Clients &amp; Recipients"
                   subtitle="Manage client accounts, contact emails, assigned website fleets, and automated report recipients.">
        <x-slot:actions>
            <button type="button" @click="openCreateModal()" class="btn-pill-primary">
                <i class="fa-solid fa-user-plus text-xs"></i>
                <span>Add New Client</span>
            </button>
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

    {{-- Stats Highlights Row --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="card p-4">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-[var(--color-ink-muted)]">Total Clients</span>
                <div class="w-8 h-8 rounded-full bg-[var(--color-surface-alt)] flex items-center justify-center text-[var(--color-brand)]">
                    <i class="fa-solid fa-users text-xs"></i>
                </div>
            </div>
            <div class="text-2xl font-bold font-display text-[var(--color-ink-strong)] mt-2">
                {{ $totalClients }}
            </div>
            <div class="text-[11px] text-[var(--color-ink-soft)] mt-0.5">
                Client organizations on file
            </div>
        </div>

        <div class="card p-4">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-[var(--color-ink-muted)]">Assigned Fleet Sites</span>
                <div class="w-8 h-8 rounded-full bg-[var(--color-surface-alt)] flex items-center justify-center text-emerald-600">
                    <i class="fa-solid fa-globe text-xs"></i>
                </div>
            </div>
            <div class="text-2xl font-bold font-display text-[var(--color-ink-strong)] mt-2">
                {{ $assignedSitesCount }}
            </div>
            <div class="text-[11px] text-[var(--color-ink-soft)] mt-0.5">
                Websites mapped to client accounts
            </div>
        </div>

        <div class="card p-4">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-[var(--color-ink-muted)]">Client Reports Linked</span>
                <div class="w-8 h-8 rounded-full bg-[var(--color-surface-alt)] flex items-center justify-center text-indigo-500">
                    <i class="fa-solid fa-file-invoice text-xs"></i>
                </div>
            </div>
            <div class="text-2xl font-bold font-display text-[var(--color-ink-strong)] mt-2">
                {{ $totalReportsCount }}
            </div>
            <div class="text-[11px] text-[var(--color-ink-soft)] mt-0.5">
                Executive reports tied to clients
            </div>
        </div>
    </div>

    {{-- Main Clients Table Card --}}
    <div class="card overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-[var(--color-border-light)] flex items-center justify-between flex-wrap gap-3">
            <div>
                <h2 class="font-display font-semibold text-base text-[var(--color-ink-strong)] flex items-center gap-2">
                    <i class="fa-solid fa-address-book text-[var(--color-ink-soft)]"></i>
                    Client Directory
                </h2>
                <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                    Assign website fleets to clients to route automated email reports and prefill recipients.
                </p>
            </div>
            
            {{-- Search Bar --}}
            <form method="GET" action="{{ route('clients.index') }}" class="flex items-center gap-2">
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-[var(--color-ink-soft)]"></i>
                    <input type="search" name="q" value="{{ $q }}" placeholder="Search client, company, site…"
                           class="w-64 text-xs pl-8 pr-3 py-1.5 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] focus:bg-[var(--color-surface)] focus:border-[var(--color-brand)] focus:outline-none">
                </div>
                @if ($q)
                    <a href="{{ route('clients.index') }}" class="btn-pill-secondary text-xs py-1.5 px-3">
                        Clear
                    </a>
                @endif
            </form>
        </div>

        @if ($clients->isEmpty())
            <div class="text-center py-16 px-6">
                <div class="w-16 h-16 rounded-full bg-[var(--color-surface-alt)] flex items-center justify-center mx-auto mb-4 text-[var(--color-brand)]">
                    <i class="fa-solid fa-users text-2xl opacity-60"></i>
                </div>
                <h3 class="font-display font-semibold text-base text-[var(--color-ink-strong)] mb-1">
                    @if ($q)
                        No clients match "{{ $q }}"
                    @else
                        No clients added yet
                    @endif
                </h3>
                <p class="text-xs text-[var(--color-ink-muted)] max-w-md mx-auto mb-6">
                    Create clients to link websites to account owners, configure primary and CC recipients, and route automated care reports.
                </p>
                @if ($q)
                    <a href="{{ route('clients.index') }}" class="btn-pill-secondary text-xs">
                        View All Clients
                    </a>
                @else
                    <button type="button" @click="openCreateModal()" class="btn-pill-primary">
                        <i class="fa-solid fa-user-plus text-xs"></i>
                        <span>Add First Client</span>
                    </button>
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-xs text-left">
                    <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] uppercase tracking-wider text-[10px]">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Client &amp; Company</th>
                            <th class="px-5 py-3 font-semibold">Report Recipients</th>
                            <th class="px-5 py-3 font-semibold">Assigned Fleet Sites</th>
                            <th class="px-5 py-3 font-semibold">Reports</th>
                            <th class="px-5 py-3 font-semibold text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @foreach ($clients as $client)
                            <tr class="hover:bg-[var(--color-surface-alt)]/50 transition-colors">
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-full bg-[var(--color-brand)]/10 text-[var(--color-brand)] font-display font-bold text-xs flex items-center justify-center shrink-0">
                                            {{ strtoupper(substr($client->name, 0, 1)) }}{{ strtoupper(substr($client->company_name ?? '', 0, 1)) }}
                                        </div>
                                        <div>
                                            <a href="{{ route('clients.show', $client) }}" class="font-semibold text-sm text-[var(--color-ink-strong)] hover:text-[var(--color-brand)] transition-colors">
                                                {{ $client->name }}
                                            </a>
                                            @if ($client->company_name)
                                                <div class="text-[11px] text-[var(--color-ink-muted)] flex items-center gap-1 mt-0.5">
                                                    <i class="fa-regular fa-building text-[10px]"></i>
                                                    <span>{{ $client->company_name }}</span>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-3.5">
                                    <div class="font-mono text-[11px] text-[var(--color-ink-strong)] flex items-center gap-1.5">
                                        <i class="fa-regular fa-envelope text-[10px] text-[var(--color-ink-soft)]"></i>
                                        <span>{{ $client->email }}</span>
                                    </div>
                                    @if (!empty($client->additional_emails))
                                        <div class="text-[10px] text-[var(--color-ink-muted)] mt-1 flex items-center gap-1">
                                            <span class="px-1.5 py-0.2 rounded bg-[var(--color-surface-alt)] font-medium text-[9px]">
                                                +{{ count($client->additional_emails) }} CC
                                            </span>
                                            <span class="truncate max-w-xs" title="{{ implode(', ', $client->additional_emails) }}">
                                                {{ implode(', ', $client->additional_emails) }}
                                            </span>
                                        </div>
                                    @endif
                                </td>
                                <td class="px-5 py-3.5">
                                    @if ($client->sites->isNotEmpty())
                                        <div class="flex flex-wrap gap-1.5 max-w-md">
                                            @foreach ($client->sites as $site)
                                                <a href="{{ route('sites.show', $site) }}" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-mono bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] text-[var(--color-ink-strong)] hover:border-[var(--color-brand)] hover:text-[var(--color-brand)] transition-colors">
                                                    <i class="fa-solid fa-globe text-[9px] text-[var(--color-ink-soft)]"></i>
                                                    <span>{{ $site->domain }}</span>
                                                </a>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-[var(--color-ink-soft)] italic text-[11px]">No sites assigned yet</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3.5 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)]">
                                        <i class="fa-solid fa-file-invoice text-[9px] text-[var(--color-brand)]"></i>
                                        {{ $client->reports_count }} {{ \Illuminate\Support\Str::plural('report', $client->reports_count) }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('clients.show', $client) }}" class="btn-pill-secondary text-xs py-1 px-3">
                                            <i class="fa-solid fa-eye text-[10px]"></i>
                                            <span>View</span>
                                        </a>
                                        <button type="button" @click="openEditModal({{ json_encode($client) }})" class="btn-pill-secondary text-xs py-1 px-3 hover:text-[var(--color-brand)]">
                                            <i class="fa-solid fa-pen-to-square text-[10px]"></i>
                                            <span>Edit</span>
                                        </button>
                                        <form method="POST" action="{{ route('clients.destroy', $client) }}" class="inline" onsubmit="return confirm('Delete client \'{{ $client->name }}\'? Assigned sites will be unlinked safely.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn-pill-secondary text-xs py-1 px-2.5 text-rose-600 hover:border-rose-300 hover:bg-rose-50" title="Delete Client">
                                                <i class="fa-solid fa-trash text-[10px]"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($clients->hasPages())
                <div class="p-4 border-t border-[var(--color-border-light)]">
                    {{ $clients->links() }}
                </div>
            @endif
        @endif
    </div>

    {{-- Create / Edit Client Modal --}}
    <div x-show="showModal"
         x-cloak
         @keydown.escape.window="closeModal()"
         class="fixed inset-0 z-50 overflow-y-auto"
         aria-labelledby="modal-title" role="dialog" aria-modal="true">
        
        <div x-show="showModal"
             x-transition:enter="ease-out duration-200"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="ease-in duration-150"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed inset-0 bg-black/50 backdrop-blur-xs transition-opacity"
             @click="closeModal()"></div>

        <div class="min-h-full flex items-center justify-center p-4">
            <div x-show="showModal"
                 x-transition:enter="ease-out duration-200"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="ease-in duration-150"
                 x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-95"
                 class="w-full max-w-2xl bg-[var(--color-surface)] rounded-[var(--radius-card)] border border-[var(--color-border-light)] shadow-2xl overflow-hidden z-10">
                
                <div class="px-6 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-full bg-[var(--color-surface-alt)] flex items-center justify-center text-[var(--color-brand)]">
                            <i class="fa-solid fa-user-plus text-xs"></i>
                        </div>
                        <div>
                            <h3 class="font-display font-semibold text-base text-[var(--color-ink-strong)]" id="modal-title" x-text="isEditing ? 'Edit Client Profile' : 'Add New Client'"></h3>
                            <p class="text-[11px] text-[var(--color-ink-muted)]">Configure recipient emails and assign website care plan domains.</p>
                        </div>
                    </div>
                    <button type="button" @click="closeModal()" class="text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] p-1.5 rounded-full hover:bg-[var(--color-surface-alt)] transition-colors">
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>

                <form method="POST" :action="formAction" class="p-6 space-y-4">
                    @csrf
                    <template x-if="isEditing">
                        <input type="hidden" name="_method" value="PUT">
                    </template>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                                Contact Name <span class="text-rose-500">*</span>
                            </label>
                            <input type="text" name="name" x-model="form.name" required placeholder="Jane Doe"
                                   class="w-full text-xs px-3 py-2.5 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] focus:bg-[var(--color-surface)] focus:border-[var(--color-brand)] focus:outline-none">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                                Company / Organization <span class="text-[10px] text-[var(--color-ink-soft)] font-normal">(optional)</span>
                            </label>
                            <input type="text" name="company_name" x-model="form.company_name" placeholder="Acme Industries"
                                   class="w-full text-xs px-3 py-2.5 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] focus:bg-[var(--color-surface)] focus:border-[var(--color-brand)] focus:outline-none">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                                Primary Email <span class="text-rose-500">*</span>
                            </label>
                            <input type="email" name="email" x-model="form.email" required placeholder="contact@acme.com"
                                   class="w-full text-xs px-3 py-2.5 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] focus:bg-[var(--color-surface)] focus:border-[var(--color-brand)] focus:outline-none">
                            <p class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Primary address reports are delivered to.</p>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                                Phone Number <span class="text-[10px] text-[var(--color-ink-soft)] font-normal">(optional)</span>
                            </label>
                            <input type="text" name="phone" x-model="form.phone" placeholder="+1 (555) 234-5678"
                                   class="w-full text-xs px-3 py-2.5 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] focus:bg-[var(--color-surface)] focus:border-[var(--color-brand)] focus:outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                            Additional CC Recipient Emails <span class="text-[10px] text-[var(--color-ink-soft)] font-normal">(optional)</span>
                        </label>
                        <input type="text" name="additional_emails" x-model="form.additional_emails" placeholder="billing@acme.com, marketing@acme.com"
                               class="w-full text-xs px-3 py-2.5 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] focus:bg-[var(--color-surface)] focus:border-[var(--color-brand)] focus:outline-none font-mono">
                        <p class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Comma-separated email addresses included on automated report distributions.</p>
                    </div>

                    {{-- Assigned Fleet Sites Checklist with search filter --}}
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="block text-xs font-semibold text-[var(--color-ink-strong)]">
                                Assign Fleet Websites
                            </label>
                            <input type="text" x-model="siteFilter" placeholder="Filter sites…"
                                   class="text-[11px] px-2 py-0.5 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] focus:outline-none">
                        </div>
                        <div class="max-h-40 overflow-y-auto rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/50 p-2 divide-y divide-[var(--color-border-light)]/60">
                            <template x-for="s in filteredSitesList" :key="s.id">
                                <label class="flex items-center justify-between py-1.5 px-2 hover:bg-[var(--color-surface)] rounded-lg cursor-pointer transition-colors text-xs">
                                    <div class="flex items-center gap-2">
                                        <input type="checkbox" name="site_ids[]" :value="s.id" :checked="isSiteSelected(s.id)"
                                               @change="toggleSiteSelection(s.id)"
                                               class="rounded text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                                        <span class="font-mono text-[11px] text-[var(--color-ink-strong)]" x-text="s.domain"></span>
                                    </div>
                                    <span x-show="s.client_id && (!isEditing || s.client_id !== editingClientId)"
                                          class="text-[9px] px-1.5 py-0.5 rounded bg-amber-50 text-amber-700">
                                        Assigned to other
                                    </span>
                                </label>
                            </template>
                            <div x-show="filteredSitesList.length === 0" class="py-4 text-center text-xs text-[var(--color-ink-soft)]">
                                No sites match filter.
                            </div>
                        </div>
                        <p class="text-[10px] text-[var(--color-ink-soft)] mt-1">
                            Selected sites will automatically resolve report recipients and token branding to this client.
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">
                            Internal Agency Notes <span class="text-[10px] text-[var(--color-ink-soft)] font-normal">(optional)</span>
                        </label>
                        <textarea name="notes" x-model="form.notes" rows="2" placeholder="Care plan tier, account manager, SLA notes…"
                                  class="w-full text-xs p-3 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] focus:bg-[var(--color-surface)] focus:border-[var(--color-brand)] focus:outline-none leading-relaxed"></textarea>
                    </div>

                    <div class="pt-3 border-t border-[var(--color-border-light)] flex items-center justify-end gap-2.5">
                        <button type="button" @click="closeModal()" class="btn-pill-secondary text-xs">
                            Cancel
                        </button>
                        <button type="submit" class="btn-pill-primary text-xs">
                            <i class="fa-solid fa-floppy-disk text-xs"></i>
                            <span x-text="isEditing ? 'Update Client' : 'Create Client'"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function clientsWorkbench(config) {
    return {
        clients: config.clients || [],
        allSites: config.allSites || [],
        showModal: false,
        isEditing: false,
        editingClientId: null,
        siteFilter: '',
        selectedSiteIds: [],
        form: {
            name: '',
            company_name: '',
            email: '',
            additional_emails: '',
            phone: '',
            address: '',
            notes: ''
        },

        get formAction() {
            if (this.isEditing && this.editingClientId) {
                return `/clients/${this.editingClientId}`;
            }
            return '{{ route("clients.store") }}';
        },

        get filteredSitesList() {
            if (!this.siteFilter.trim()) {
                return this.allSites;
            }
            const q = this.siteFilter.toLowerCase();
            return this.allSites.filter(s => s.domain.toLowerCase().includes(q));
        },

        isSiteSelected(siteId) {
            return this.selectedSiteIds.includes(parseInt(siteId));
        },

        toggleSiteSelection(siteId) {
            const id = parseInt(siteId);
            const idx = this.selectedSiteIds.indexOf(id);
            if (idx > -1) {
                this.selectedSiteIds.splice(idx, 1);
            } else {
                this.selectedSiteIds.push(id);
            }
        },

        openCreateModal() {
            this.isEditing = false;
            this.editingClientId = null;
            this.selectedSiteIds = [];
            this.form = {
                name: '',
                company_name: '',
                email: '',
                additional_emails: '',
                phone: '',
                address: '',
                notes: ''
            };
            this.showModal = true;
        },

        openEditModal(client) {
            this.isEditing = true;
            this.editingClientId = client.id;
            this.selectedSiteIds = (client.sites || []).map(s => parseInt(s.id));
            this.form = {
                name: client.name || '',
                company_name: client.company_name || '',
                email: client.email || '',
                additional_emails: Array.isArray(client.additional_emails) ? client.additional_emails.join(', ') : '',
                phone: client.phone || '',
                address: client.address || '',
                notes: client.notes || ''
            };
            this.showModal = true;
        },

        closeModal() {
            this.showModal = false;
            this.siteFilter = '';
        }
    };
}
</script>
@endsection
