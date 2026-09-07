@extends('layouts.app')

@section('title', 'Code Snippets · Clockwork')

@section('content')
<div class="mb-6">
    <div class="flex items-center justify-between flex-wrap gap-4">
        <div>
            <h1 class="display-heading text-3xl text-[var(--color-ink-strong)] flex items-center gap-2">
                <i class="fa-solid fa-code text-[var(--color-brand)]"></i>
                PHP Code Snippets
            </h1>
            <p class="text-xs text-[var(--color-ink-soft)] mt-1">
                Execute sandboxed PHP snippets across one or multiple WordPress sites in your fleet.
            </p>
        </div>
        <button type="button" @click="isCreating = true; editingSnippet = { id: null, name: '', description: '', code: '' }" class="btn-pill-primary text-xs">
            <i class="fa-solid fa-plus mr-1"></i> New Snippet
        </button>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6" x-data="snippetWorkbench({
    snippets: {{ json_encode($snippets) }},
    sites: {{ json_encode($sites) }},
    selectedSnippetId: {{ $selectedSnippetId ?: ($snippets->first()->id ?? 0) }},
    preselectedSiteId: {{ $selectedSiteId }}
})">
    {{-- Left Column: Library & Site Selector --}}
    <div class="lg:col-span-4 space-y-6">
        {{-- Snippet Library Card --}}
        <div class="card p-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)]">
                    <i class="fa-solid fa-book-bookmark text-[var(--color-ink-soft)] mr-1"></i> Snippet Library
                </h3>
                <span class="text-[10px] text-[var(--color-ink-muted)]" x-text="snippets.length + ' available'"></span>
            </div>

            <div class="space-y-1.5 max-h-72 overflow-y-auto pr-1">
                <template x-for="s in snippets" :key="s.id">
                    <div @click="selectSnippet(s)"
                         :class="selectedSnippet?.id === s.id ? 'bg-[var(--color-surface-alt)] border-[var(--color-brand)] shadow-sm' : 'border-transparent hover:bg-[var(--color-surface-alt)]/50'"
                         class="p-2.5 rounded-lg border cursor-pointer transition-all">
                        <div class="flex items-center justify-between">
                            <span class="font-medium text-xs text-[var(--color-ink-strong)] truncate" x-text="s.name"></span>
                            <span x-show="s.is_preset" class="px-1.5 py-0.2 rounded text-[9px] font-semibold bg-sky-100 text-sky-700">Preset</span>
                        </div>
                        <p class="text-[11px] text-[var(--color-ink-muted)] line-clamp-2 mt-0.5" x-text="s.description || 'No description'"></p>
                    </div>
                </template>
            </div>
        </div>

        {{-- Target Sites Card --}}
        <div class="card p-4">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-server text-[var(--color-ink-soft)] mr-1"></i> Target Sites
                    </h3>
                    <p class="text-[10px] text-[var(--color-ink-muted)]">Select which sites to run the snippet on</p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" @click="selectAllSites()" class="text-[10px] text-[var(--color-brand)] hover:underline">Select All</button>
                    <span class="text-[var(--color-border)]">|</span>
                    <button type="button" @click="selectedSites = []" class="text-[10px] text-[var(--color-ink-soft)] hover:underline">Clear</button>
                </div>
            </div>

            <input type="text" x-model="siteSearch" placeholder="Filter sites..."
                   class="text-xs w-full px-2.5 py-1.5 mb-2 rounded border border-[var(--color-border-light)] bg-[var(--color-surface)] outline-none focus:border-[var(--color-brand)]">

            <div class="space-y-1 max-h-60 overflow-y-auto pr-1">
                <template x-for="site in filteredSites" :key="site.id">
                    <label class="flex items-center gap-2 p-1.5 rounded hover:bg-[var(--color-surface-alt)] cursor-pointer text-xs">
                        <input type="checkbox" :value="site.id" x-model="selectedSites" class="rounded text-[var(--color-brand)] focus:ring-0">
                        <span class="text-[var(--color-ink-strong)] truncate flex-1" x-text="site.domain"></span>
                    </label>
                </template>
                <div x-show="filteredSites.length === 0" class="text-xs text-[var(--color-ink-muted)] text-center py-4">
                    No matching Companion sites.
                </div>
            </div>
            <div class="mt-2 text-[10px] text-[var(--color-ink-soft)] text-right" x-text="selectedSites.length + ' site(s) selected'"></div>
        </div>
    </div>

    {{-- Right Column: Code Editor & Execution Workbench --}}
    <div class="lg:col-span-8 space-y-6">
        <div class="card p-5">
            {{-- Editor Header --}}
            <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                <div>
                    <h2 class="font-display font-semibold text-base text-[var(--color-ink-strong)]" x-text="selectedSnippet ? selectedSnippet.name : 'PHP Editor'"></h2>
                    <p class="text-xs text-[var(--color-ink-soft)]" x-text="selectedSnippet?.description || 'Write or paste PHP code without opening php tags.'"></p>
                </div>
                <div class="flex items-center gap-2">
                    <label class="text-xs text-[var(--color-ink-muted)] flex items-center gap-1">
                        Timeout:
                        <select x-model="timeout" class="text-xs py-1 px-2 rounded border border-[var(--color-border-light)] bg-[var(--color-surface)]">
                            <option value="10">10s</option>
                            <option value="30">30s</option>
                            <option value="60">60s</option>
                            <option value="120">120s</option>
                        </select>
                    </label>
                    <button type="button" @click="runSnippet()" :disabled="running || selectedSites.length === 0"
                            class="btn-pill-primary text-xs px-4 py-1.5 flex items-center gap-1.5 disabled:opacity-50">
                        <i class="fa-solid" :class="running ? 'fa-spinner fa-spin' : 'fa-play'"></i>
                        <span x-text="running ? 'Executing...' : 'Run on (' + selectedSites.length + ') Sites'"></span>
                    </button>
                </div>
            </div>

            {{-- Code Input Area --}}
            <div class="relative rounded-lg border border-[var(--color-border-light)] bg-slate-950 font-mono text-xs overflow-hidden">
                <div class="flex items-center justify-between px-3 py-1.5 bg-slate-900 border-b border-slate-800 text-slate-400 text-[11px]">
                    <span>PHP Sandbox (implicit return supported)</span>
                    <button type="button" @click="navigator.clipboard.writeText(code)" class="hover:text-white" title="Copy code">
                        <i class="fa-regular fa-copy"></i>
                    </button>
                </div>
                <textarea x-model="code" rows="12" spellcheck="false"
                          class="w-full bg-transparent text-emerald-400 p-3 outline-none font-mono text-xs resize-y leading-relaxed border-none focus:ring-0"></textarea>
            </div>
        </div>

        {{-- Execution Results Panel --}}
        <div class="card p-5" x-show="results !== null" x-cloak>
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-display font-semibold text-base text-[var(--color-ink-strong)] flex items-center gap-2">
                    <i class="fa-solid fa-terminal text-[var(--color-ink-soft)]"></i>
                    Execution Results
                </h3>
                <span class="text-xs text-[var(--color-ink-muted)]" x-text="'Completed for ' + (results?.results?.length || 0) + ' site(s)'"></span>
            </div>

            <div class="space-y-4">
                <template x-for="res in (results?.results || [])" :key="res.site_id">
                    <div class="rounded-lg border p-3 text-xs" :class="res.ok ? 'border-emerald-200 bg-emerald-500/5' : 'border-rose-200 bg-rose-500/5'">
                        <div class="flex items-center justify-between mb-2">
                            <span class="font-semibold text-sm text-[var(--color-ink-strong)]" x-text="res.domain"></span>
                            <div class="flex items-center gap-2">
                                <span class="px-2 py-0.5 rounded text-[10px] font-semibold" :class="res.ok ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'" x-text="res.ok ? 'Success' : 'Error'"></span>
                                <span x-show="res.duration_ms" class="text-[11px] text-[var(--color-ink-muted)] font-mono" x-text="res.duration_ms + ' ms'"></span>
                                <span x-show="res.memory_used_bytes" class="text-[11px] text-[var(--color-ink-muted)] font-mono" x-text="Math.round(res.memory_used_bytes / 1024) + ' KB'"></span>
                            </div>
                        </div>

                        {{-- Output / Return Value / Error --}}
                        <div x-show="res.error" class="text-rose-600 font-mono text-xs mb-2 whitespace-pre-wrap" x-text="res.error"></div>
                        <div x-show="res.output" class="mb-2">
                            <div class="text-[10px] text-[var(--color-ink-soft)] uppercase font-semibold mb-0.5">Stdout Output:</div>
                            <pre class="bg-slate-900 text-slate-100 p-2 rounded text-[11px] overflow-x-auto whitespace-pre-wrap font-mono max-h-40" x-text="res.output"></pre>
                        </div>
                        <div x-show="res.return_value !== undefined && res.return_value !== null">
                            <div class="text-[10px] text-[var(--color-ink-soft)] uppercase font-semibold mb-0.5">Return Value:</div>
                            <pre class="bg-slate-900 text-amber-300 p-2 rounded text-[11px] overflow-x-auto whitespace-pre-wrap font-mono max-h-48" x-text="typeof res.return_value === 'object' ? JSON.stringify(res.return_value, null, 2) : res.return_value"></pre>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>

<script>
function snippetWorkbench(config) {
    return {
        snippets: config.snippets || [],
        sites: config.sites || [],
        selectedSites: config.preselectedSiteId ? [config.preselectedSiteId] : [],
        selectedSnippet: null,
        siteSearch: '',
        code: '',
        timeout: 30,
        running: false,
        results: null,

        init() {
            if (config.selectedSnippetId) {
                const found = this.snippets.find(s => s.id === config.selectedSnippetId);
                if (found) {
                    this.selectSnippet(found);
                }
            } else if (this.snippets.length > 0) {
                this.selectSnippet(this.snippets[0]);
            }
        },

        get filteredSites() {
            if (!this.siteSearch) return this.sites;
            const q = this.siteSearch.toLowerCase();
            return this.sites.filter(s => s.domain.toLowerCase().includes(q));
        },

        selectAllSites() {
            this.selectedSites = this.filteredSites.map(s => s.id);
        },

        selectSnippet(s) {
            this.selectedSnippet = s;
            this.code = s.code;
        },

        async runSnippet() {
            if (this.selectedSites.length === 0 || !this.code.trim()) return;
            this.running = true;
            this.results = null;

            try {
                const response = await fetch('{{ route("snippets.execute") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        site_ids: this.selectedSites,
                        code: this.code,
                        timeout: parseInt(this.timeout, 10),
                    })
                });

                const data = await response.json();
                this.results = data;
            } catch (err) {
                alert('Execution request failed: ' + err.message);
            } finally {
                this.running = false;
            }
        }
    };
}
</script>
@endsection
