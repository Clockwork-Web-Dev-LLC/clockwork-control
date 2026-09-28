@extends('layouts.app')

@section('title', 'Feedback & Backlog · Clockwork')

@section('content')
<div class="relative" x-data="{
    batchModalOpen: false,
    batchLoading: false,
    batchPromptText: '',
    batchCount: {{ $stats['approved'] }},
    approvedCount: {{ $stats['approved'] }},
    openCount: {{ $stats['open'] }},
    batchStatus: 'approved',
    batchCopied: false,
    async openBatchModal(status = 'approved') {
        this.batchStatus = status;
        this.batchModalOpen = true;
        this.batchLoading = true;
        try {
            const res = await fetch(`{{ route('feedback.prompt.batch') }}?status=${encodeURIComponent(this.batchStatus)}`, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();
            if (data.ok) {
                this.batchPromptText = data.prompt;
                this.batchCount = data.count;
            } else {
                this.batchPromptText = data.message || 'No feedback items found.';
                this.batchCount = 0;
            }
        } catch (e) {
            this.batchPromptText = 'Failed to load batch prompt.';
            this.batchCount = 0;
        } finally {
            this.batchLoading = false;
        }
    },
    copyBatchPrompt() {
        if (!this.batchPromptText || this.batchCount === 0) return;
        navigator.clipboard.writeText(this.batchPromptText).then(() => {
            this.batchCopied = true;
            setTimeout(() => this.batchCopied = false, 2500);
        });
    }
}">
    <x-page-header title="Feedback & Backlog"
        subtitle="Visual in-app feedback, employee change requests, and threaded collaboration across Clockwork Control.">
        <x-slot:actions>
            {{-- Master Prompt Button for Approved items (Always visible on all views including status=all) --}}
            <button type="button"
                    @click="openBatchModal('approved')"
                    class="btn-primary inline-flex items-center gap-2 text-xs md:text-sm font-semibold shadow-xs cursor-pointer"
                    title="Generate one big prompt for all approved items to paste into Claude/Grok">
                <i class="fa-solid fa-wand-magic-sparkles text-xs"></i>
                <span>Generate Approved Prompt</span>
                <span class="px-1.5 py-0.2 rounded-full font-data text-xs"
                      :class="approvedCount > 0 ? 'bg-white/25 text-white font-bold' : 'bg-white/10 text-white/70'"
                      x-text="approvedCount">
                    {{ $stats['approved'] }}
                </span>
            </button>

            @if ($stats['approved'] > 0)
                <a href="{{ route('feedback.prompt.download', ['status' => 'approved']) }}"
                   class="btn-pill-nav inline-flex items-center gap-1.5 text-xs md:text-sm"
                   title="Download .md prompt file for all approved items">
                    <i class="fa-solid fa-download text-xs"></i>
                    <span class="hidden sm:inline">Download .md</span>
                </a>
            @endif

            <a href="{{ route('feedback.index', ['status' => 'all']) }}"
               class="btn-pill-nav inline-flex items-center gap-1.5 text-xs md:text-sm"
               title="Refresh feedback backlog">
                <i class="fa-solid fa-rotate text-xs"></i>
                <span>Refresh</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="card p-4 mb-6 status-green flex items-center gap-2 text-sm">
            <i class="fa-solid fa-circle-check"></i>
            {{ session('status') }}
        </div>
    @endif

    {{-- Stats Cards (5 Pillars including Approved) --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 mb-6">
        <a href="{{ route('feedback.index', ['status' => 'all']) }}"
           class="card p-4 hover:border-[var(--color-brand)] transition-all cursor-pointer {{ $currentStatus === 'all' ? 'border-[var(--color-brand)] shadow-xs' : '' }}">
            <div class="text-[11px] uppercase tracking-wide text-[var(--color-ink-muted)] mb-1">Total Submissions</div>
            <div class="text-2xl lg:text-3xl font-bold font-data text-[var(--color-ink-strong)]">{{ $stats['total'] }}</div>
            <div class="text-[11px] text-[var(--color-ink-muted)] mt-1">all-time feedback</div>
        </a>

        <a href="{{ route('feedback.index', ['status' => 'open']) }}"
           class="card p-4 hover:border-amber-500 transition-all cursor-pointer {{ $currentStatus === 'open' ? 'border-amber-500 shadow-xs' : '' }}">
            <div class="text-[11px] uppercase tracking-wide text-[var(--color-ink-muted)] mb-1">Open Issues</div>
            <div class="text-2xl lg:text-3xl font-bold font-data text-amber-500" x-text="openCount">{{ $stats['open'] }}</div>
            <div class="text-[11px] text-[var(--color-ink-muted)] mt-1">awaiting review</div>
        </a>

        <a href="{{ route('feedback.index', ['status' => 'approved']) }}"
           class="card p-4 hover:border-emerald-500 transition-all cursor-pointer {{ $currentStatus === 'approved' ? 'border-emerald-500 shadow-xs ring-1 ring-emerald-500/20' : '' }}">
            <div class="text-[11px] uppercase tracking-wide text-emerald-600 dark:text-emerald-400 font-semibold mb-1 flex items-center justify-between">
                <span class="flex items-center gap-1">
                    <i class="fa-solid fa-check-circle text-[10px]"></i> Approved
                </span>
                <button type="button"
                        @click.stop.prevent="openBatchModal('approved')"
                        class="text-[10px] text-emerald-600 dark:text-emerald-400 hover:underline font-semibold flex items-center gap-1"
                        title="Generate prompt for all approved items">
                    <i class="fa-solid fa-wand-magic-sparkles text-[9px]"></i> Prompt
                </button>
            </div>
            <div class="text-2xl lg:text-3xl font-bold font-data text-emerald-600 dark:text-emerald-400" x-text="approvedCount">{{ $stats['approved'] }}</div>
            <div class="text-[11px] text-[var(--color-ink-muted)] mt-1 flex items-center justify-between">
                <span>ready for Claude prompt</span>
                <span class="text-[10px] text-emerald-600 dark:text-emerald-400 font-semibold">Generate →</span>
            </div>
        </a>

        <a href="{{ route('feedback.index', ['status' => 'in_progress']) }}"
           class="card p-4 hover:border-blue-500 transition-all cursor-pointer {{ $currentStatus === 'in_progress' ? 'border-blue-500 shadow-xs' : '' }}">
            <div class="text-[11px] uppercase tracking-wide text-[var(--color-ink-muted)] mb-1">In Progress</div>
            <div class="text-2xl lg:text-3xl font-bold font-data text-blue-500">{{ $stats['in_progress'] }}</div>
            <div class="text-[11px] text-[var(--color-ink-muted)] mt-1">under development</div>
        </a>

        <a href="{{ route('feedback.index', ['status' => 'resolved']) }}"
           class="card p-4 hover:border-[var(--color-status-green)] transition-all cursor-pointer {{ $currentStatus === 'resolved' ? 'border-[var(--color-status-green)] shadow-xs' : '' }}">
            <div class="text-[11px] uppercase tracking-wide text-[var(--color-ink-muted)] mb-1">Resolved</div>
            <div class="text-2xl lg:text-3xl font-bold font-data text-[var(--color-status-green)]">{{ $stats['resolved'] }}</div>
            <div class="text-[11px] text-[var(--color-ink-muted)] mt-1">completed & closed</div>
        </a>
    </div>

    {{-- Approved Implementation Batch Banner --}}
    @if ($stats['approved'] > 0)
        <div class="card p-5 mb-6 border-emerald-500/40 bg-gradient-to-r from-emerald-500/5 via-teal-500/5 to-transparent relative overflow-hidden">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0 mt-0.5">
                        <i class="fa-solid fa-wand-magic-sparkles text-lg"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="font-display font-bold text-sm text-[var(--color-ink-strong)]">
                                Implementation Batch: {{ $stats['approved'] }} Approved {{ \Illuminate\Support\Str::plural('Item', $stats['approved']) }}
                            </h3>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30">
                                Ready for Claude & Grok
                            </span>
                        </div>
                        <p class="text-xs text-[var(--color-ink-muted)] mt-1 max-w-2xl leading-relaxed">
                            These items have been reviewed and approved. Download the complete batch prompt file or copy it directly into Claude, Grok, or Antigravity to implement all requested features and bug fixes with complete code and DOM context.
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2 shrink-0 flex-wrap">
                    {{-- Copy Batch Prompt Button --}}
                    <button type="button"
                            @click="openBatchModal('approved')"
                            class="btn-primary text-xs py-1.5 px-3.5 flex items-center gap-1.5 cursor-pointer font-semibold shadow-xs">
                        <i class="fa-solid fa-wand-magic-sparkles text-[11px]"></i>
                        <span>Generate Approved Prompt</span>
                    </button>

                    {{-- Download Prompt File (.md) --}}
                    <a href="{{ route('feedback.prompt.download', ['status' => 'approved']) }}"
                       class="btn-pill-nav text-xs py-1.5 px-3 flex items-center gap-1.5 cursor-pointer font-medium hover:border-[var(--color-brand)]">
                        <i class="fa-solid fa-download text-[11px]"></i>
                        <span>Download .md</span>
                    </a>

                    {{-- Mark In Progress --}}
                    <form method="POST" action="{{ route('feedback.prompt.mark-in-progress') }}" class="inline" onsubmit="return confirm('Mark all {{ $stats['approved'] }} approved items as In Progress?');">
                        @csrf
                        <button type="submit"
                                class="btn-pill-nav text-xs py-1.5 px-3 flex items-center gap-1.5 cursor-pointer text-blue-600 dark:text-blue-400 hover:bg-blue-500/10 border-blue-500/30"
                                title="Move approved items to In Progress once you have handed them to Claude">
                            <i class="fa-solid fa-arrow-right text-[10px]"></i>
                            <span>Mark in Progress</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    @elseif ($currentStatus === 'all' && $stats['total'] > 0)
        <div class="card p-4 mb-6 border-dashed border-[var(--color-border)] bg-[var(--color-surface-alt)]/40 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-[var(--color-brand)]/10 text-[var(--color-brand)] flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-lightbulb text-xs"></i>
                </div>
                <div>
                    <span class="font-semibold text-[var(--color-ink-strong)]">Want to generate one big AI prompt for approved items?</span>
                    <span class="text-[var(--color-ink-muted)] block sm:inline">Click <strong>Approve</strong> on any item below to add it to your implementation batch, or generate a prompt for all items.</span>
                </div>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <button type="button"
                        @click="openBatchModal('all')"
                        class="btn-pill-nav text-xs py-1.5 px-3 flex items-center gap-1.5 cursor-pointer font-medium hover:border-[var(--color-brand)]"
                        title="Generate prompt for all feedback items regardless of status">
                    <i class="fa-solid fa-wand-magic-sparkles text-[10px]"></i>
                    <span>Prompt All {{ $stats['total'] }} Items</span>
                </button>
            </div>
        </div>
    @endif

    {{-- Filter & Search Toolbar --}}
    <div class="card p-4 mb-6">
        <form method="GET" action="{{ route('feedback.index') }}" class="flex flex-wrap items-center gap-3">
            {{-- Status Tabs --}}
            <div class="flex items-center gap-1 text-xs flex-wrap">
                <a href="{{ route('feedback.index', array_merge(request()->query(), ['status' => 'active'])) }}"
                   class="px-2.5 py-1 rounded-full font-medium transition-all {{ $currentStatus === 'active' ? 'bg-[var(--color-brand)] text-white' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]' }}">
                    Active
                </a>
                <a href="{{ route('feedback.index', array_merge(request()->query(), ['status' => 'open'])) }}"
                   class="px-2.5 py-1 rounded-full font-medium transition-all {{ $currentStatus === 'open' ? 'bg-amber-500 text-white' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]' }}">
                    Open (<span x-text="openCount">{{ $stats['open'] }}</span>)
                </a>
                <a href="{{ route('feedback.index', array_merge(request()->query(), ['status' => 'approved'])) }}"
                   class="px-2.5 py-1 rounded-full font-medium transition-all {{ $currentStatus === 'approved' ? 'bg-emerald-600 text-white' : 'text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/10' }}">
                    Approved (<span x-text="approvedCount">{{ $stats['approved'] }}</span>)
                </a>
                <a href="{{ route('feedback.index', array_merge(request()->query(), ['status' => 'in_progress'])) }}"
                   class="px-2.5 py-1 rounded-full font-medium transition-all {{ $currentStatus === 'in_progress' ? 'bg-blue-500 text-white' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]' }}">
                    In Progress ({{ $stats['in_progress'] }})
                </a>
                <a href="{{ route('feedback.index', array_merge(request()->query(), ['status' => 'resolved'])) }}"
                   class="px-2.5 py-1 rounded-full font-medium transition-all {{ $currentStatus === 'resolved' ? 'bg-[var(--color-status-green)] text-white' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]' }}">
                    Resolved ({{ $stats['resolved'] }})
                </a>
                <a href="{{ route('feedback.index', array_merge(request()->query(), ['status' => 'all'])) }}"
                   class="px-2.5 py-1 rounded-full font-medium transition-all {{ $currentStatus === 'all' ? 'bg-[var(--color-surface-alt)] font-bold text-[var(--color-ink-strong)]' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]' }}">
                    All
                </a>
            </div>

            <div class="w-px h-5 bg-[var(--color-border-light)] hidden md:block"></div>

            {{-- Type Filter --}}
            <select name="type" onchange="this.form.submit()" class="text-xs rounded border border-[var(--color-border)] bg-[var(--color-surface)] py-1.5 px-2.5 text-[var(--color-ink-strong)] focus:outline-none focus:border-[var(--color-brand)] cursor-pointer">
                <option value="">All Categories</option>
                <option value="bug" {{ $currentType === 'bug' ? 'selected' : '' }}>🐛 Bug Reports</option>
                <option value="tweak" {{ $currentType === 'tweak' ? 'selected' : '' }}>✨ UI Tweaks</option>
                <option value="feature" {{ $currentType === 'feature' ? 'selected' : '' }}>💡 Feature Ideas</option>
                <option value="copy" {{ $currentType === 'copy' ? 'selected' : '' }}>✍️ Content / Copy</option>
            </select>

            {{-- Page Path Filter --}}
            @if ($availablePaths->isNotEmpty())
                <select name="path" onchange="this.form.submit()" class="text-xs rounded border border-[var(--color-border)] bg-[var(--color-surface)] py-1.5 px-2.5 text-[var(--color-ink-strong)] focus:outline-none focus:border-[var(--color-brand)] cursor-pointer">
                    <option value="">All Screens</option>
                    @foreach ($availablePaths as $p)
                        <option value="{{ $p }}" {{ $currentPath === $p ? 'selected' : '' }}>{{ $p }}</option>
                    @endforeach
                </select>
            @endif

            {{-- Search Bar --}}
            <div class="relative flex-1 min-w-48">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-[var(--color-ink-soft)] text-xs"></i>
                <input type="search"
                       name="q"
                       value="{{ $searchQuery }}"
                       placeholder="Search feedback notes or submitter…"
                       class="w-full pl-8 pr-3 py-1.5 rounded-full border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] focus:bg-[var(--color-surface)] focus:outline-none focus:border-[var(--color-brand)] text-xs text-[var(--color-ink-strong)]">
            </div>

            <input type="hidden" name="status" value="{{ $currentStatus }}">

            @if ($searchQuery || $currentType || $currentPath)
                <a href="{{ route('feedback.index', ['status' => $currentStatus]) }}"
                   class="btn-pill-nav text-xs py-1.5 px-3 text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] cursor-pointer"
                   title="Clear filters">
                    <i class="fa-solid fa-xmark"></i> Clear
                </a>
            @endif

            {{-- Quick action to generate approved prompt --}}
            <button type="button"
                    @click="openBatchModal('approved')"
                    class="btn-pill-nav text-xs py-1.5 px-3 flex items-center gap-1.5 font-semibold text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/10 border-emerald-500/40 cursor-pointer sm:ml-auto"
                    title="Generate one big prompt for all approved items">
                <i class="fa-solid fa-wand-magic-sparkles text-[10px]"></i>
                <span>Prompt Approved</span>
                <span class="px-1.5 py-0.2 rounded-full font-data text-[10px]"
                      :class="approvedCount > 0 ? 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 font-bold' : 'bg-neutral-500/10 text-[var(--color-ink-muted)]'"
                      x-text="approvedCount">
                    {{ $stats['approved'] }}
                </span>
            </button>
        </form>
    </div>

    {{-- Feedback Items List --}}
    @if ($items->isEmpty())
        <div class="card p-12 text-center text-[var(--color-ink-muted)]">
            <div class="w-12 h-12 rounded-full bg-[var(--color-surface-alt)] flex items-center justify-center mx-auto mb-3 text-[var(--color-ink-soft)]">
                <i class="fa-solid fa-comment-dots text-xl"></i>
            </div>
            <h3 class="font-display font-semibold text-base text-[var(--color-ink-strong)] mb-1">No feedback found</h3>
            <p class="text-xs max-w-sm mx-auto leading-relaxed">
                @if ($searchQuery || $currentType || $currentPath)
                    No items match the selected filters. Try clearing your search filters.
                @else
                    Right-click any button, table, or card anywhere across Clockwork Control to leave an in-app note and drop a pin!
                @endif
            </p>
        </div>
    @else
        <div class="space-y-4 mb-6">
            @foreach ($items as $item)
                <div class="card p-5 hover:border-[var(--color-brand)]/40 transition-all text-xs"
                     x-data="{
                         expanded: false,
                         promptCopied: false,
                         itemStatus: '{{ $item->status }}',
                         approving: false,
                         async approveItem() {
                             if (this.itemStatus === 'approved' || this.approving) return;
                             this.approving = true;
                             try {
                                 const res = await fetch('{{ route('feedback.approve', $item) }}', {
                                     method: 'POST',
                                     headers: {
                                         'Content-Type': 'application/json',
                                         'Accept': 'application/json',
                                         'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                     }
                                 });
                                 const data = await res.json();
                                 if (data.ok) {
                                     const prev = this.itemStatus;
                                     this.itemStatus = 'approved';
                                     approvedCount++;
                                     batchCount = approvedCount;
                                     if (prev === 'open' && openCount > 0) {
                                         openCount--;
                                     }
                                 }
                             } catch (e) {
                                 console.error('Approve failed', e);
                             } finally {
                                 this.approving = false;
                             }
                         },
                         async updateStatus(newStatus) {
                             const prev = this.itemStatus;
                             this.itemStatus = newStatus;
                             try {
                                 const res = await fetch('{{ route('feedback.update', $item) }}', {
                                     method: 'PATCH',
                                     headers: {
                                         'Content-Type': 'application/json',
                                         'Accept': 'application/json',
                                         'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                     },
                                     body: JSON.stringify({ status: newStatus })
                                 });
                                 const data = await res.json();
                                 if (data.ok) {
                                     if (prev === 'approved' && newStatus !== 'approved') {
                                         approvedCount = Math.max(0, approvedCount - 1);
                                     } else if (prev !== 'approved' && newStatus === 'approved') {
                                         approvedCount++;
                                     }
                                     if (prev === 'open' && newStatus !== 'open') {
                                         openCount = Math.max(0, openCount - 1);
                                     } else if (prev !== 'open' && newStatus === 'open') {
                                         openCount++;
                                     }
                                     batchCount = approvedCount;
                                 } else {
                                     this.itemStatus = prev;
                                 }
                             } catch (e) {
                                 console.error('Status update failed', e);
                                 this.itemStatus = prev;
                             }
                         }
                     }">
                    {{-- Item Top Row: Badges, Path, Author, Date --}}
                    <div class="flex items-start justify-between flex-wrap gap-2 mb-2.5">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold font-data {{ $item->typeBadgeClass() }}">
                                {{ $item->formattedType() }}
                            </span>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold font-data transition-colors"
                                  :class="{
                                      'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30': itemStatus === 'approved',
                                      'bg-amber-500/15 text-amber-600 dark:text-amber-400 border border-amber-500/30': itemStatus === 'open',
                                      'bg-blue-500/15 text-blue-600 dark:text-blue-400 border border-blue-500/30': itemStatus === 'in_progress',
                                      'bg-[var(--color-status-green)]/15 text-[var(--color-status-green)] border border-[var(--color-status-green)]/30': itemStatus === 'resolved',
                                      'bg-neutral-500/15 text-[var(--color-ink-muted)] border border-neutral-500/30': itemStatus === 'dismissed'
                                  }"
                                  x-text="itemStatus.replace('_', ' ').toUpperCase()">
                                {{ strtoupper(str_replace('_', ' ', $item->status)) }}
                            </span>

                            <a href="{{ $item->url }}{{ str_contains($item->url, '?') ? '&' : '?' }}feedback_pin={{ $item->id }}"
                               target="_blank"
                               class="font-data text-[11px] text-[var(--color-brand)] hover:underline flex items-center gap-1"
                               title="Jump to page and highlight pin">
                                <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                                <span>{{ $item->path }}</span>
                            </a>

                            @if ($item->route_name)
                                <span class="text-[10px] text-[var(--color-ink-muted)] font-data hidden sm:inline">
                                    ({{ $item->route_name }})
                                </span>
                            @endif
                        </div>

                        <div class="flex items-center gap-3 text-[11px] text-[var(--color-ink-muted)]">
                            <div class="flex items-center gap-1.5">
                                @if ($item->user?->avatarUrl(40))
                                    <img src="{{ $item->user->avatarUrl(40) }}" alt="" class="w-4 h-4 rounded-full">
                                @endif
                                <span class="font-medium text-[var(--color-ink-strong)]">{{ $item->user?->name ?? 'User' }}</span>
                            </div>
                            <span>·</span>
                            <span title="{{ $item->created_at->format('M j, Y g:i A') }}">{{ $item->created_at->diffForHumans() }}</span>
                        </div>
                    </div>

                    {{-- Element Target Context --}}
                    @if ($item->element_tag || $item->element_text || $item->selector)
                        <div class="p-2 rounded bg-[var(--color-surface-alt)]/80 border border-[var(--color-border-light)] mb-3 flex items-start gap-2">
                            <i class="fa-solid fa-crosshairs text-[var(--color-brand)] text-[10px] mt-0.5 shrink-0"></i>
                            <div class="overflow-hidden">
                                <span class="text-[10px] text-[var(--color-ink-muted)] font-semibold uppercase tracking-wider">Target:</span>
                                <code class="font-data text-[11px] text-[var(--color-ink-strong)] truncate">
                                    &lt;{{ $item->element_tag }}&gt; {{ $item->element_text ? "\"{$item->element_text}\"" : $item->selector }}
                                </code>
                            </div>
                        </div>
                    @endif

                    {{-- Title & Content Description --}}
                    <h3 class="font-display font-bold text-sm text-[var(--color-ink-strong)] mb-1.5">{{ $item->title }}</h3>
                    <div class="text-[var(--color-ink)] leading-relaxed whitespace-pre-wrap mb-4">{{ $item->content }}</div>

                    {{-- Thread Discussion & Bottom Actions --}}
                    <div class="pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between flex-wrap gap-3">
                        <div class="flex items-center gap-2 flex-wrap">
                            {{-- Thread Discussion Accordion Button --}}
                            <button type="button"
                                    @click="expanded = !expanded"
                                    class="btn-pill-nav text-xs py-1 px-2.5 flex items-center gap-1.5 cursor-pointer">
                                <i class="fa-solid fa-comments text-[10px]"></i>
                                <span>{{ $item->comments->count() }} {{ \Illuminate\Support\Str::plural('reply', $item->comments->count()) }}</span>
                                <i class="fa-solid fa-chevron-down text-[9px] transition-transform duration-200" :class="expanded ? 'rotate-180' : ''"></i>
                            </button>

                            {{-- Jump to live pin --}}
                            <a href="{{ $item->url }}{{ str_contains($item->url, '?') ? '&' : '?' }}feedback_pin={{ $item->id }}"
                               target="_blank"
                               class="btn-pill-nav text-xs py-1 px-2.5 flex items-center gap-1 cursor-pointer"
                               title="Open target screen and drop pin">
                                <i class="fa-solid fa-location-dot text-[10px]"></i> View Pin
                            </a>

                            {{-- 1-Click Approve Button (AJAX) --}}
                            <template x-if="itemStatus !== 'approved'">
                                <button type="button"
                                        @click="approveItem()"
                                        :disabled="approving"
                                        class="btn-pill-nav text-xs py-1 px-2.5 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/10 border-emerald-500/30 flex items-center gap-1.5 cursor-pointer font-semibold disabled:opacity-50"
                                        title="Mark as Approved without reloading the page">
                                    <i class="fa-solid" :class="approving ? 'fa-spinner fa-spin text-[10px]' : 'fa-check text-[10px]'"></i>
                                    <span x-text="approving ? 'Approving…' : 'Approve'">Approve</span>
                                </button>
                            </template>
                            <template x-if="itemStatus === 'approved'">
                                <span class="px-2.5 py-1 rounded-full text-xs font-semibold font-data bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30 flex items-center gap-1">
                                    <i class="fa-solid fa-circle-check text-[10px]"></i> Approved
                                </span>
                            </template>

                            {{-- Copy Prompt Button --}}
                            <button type="button"
                                    @click="
                                        navigator.clipboard.writeText(@js($item->toClaudePrompt())).then(() => {
                                            promptCopied = true;
                                            setTimeout(() => promptCopied = false, 2500);
                                        });
                                    "
                                    class="btn-pill-nav text-xs py-1 px-2.5 flex items-center gap-1.5 cursor-pointer hover:border-[var(--color-brand)]"
                                    title="Copy AI implementation prompt to clipboard">
                                <i class="fa-solid text-[10px]" :class="promptCopied ? 'fa-check text-emerald-500' : 'fa-wand-magic-sparkles text-[var(--color-brand)]'"></i>
                                <span x-text="promptCopied ? 'Copied!' : 'Copy Prompt'"></span>
                            </button>

                            {{-- Download Single Item .md --}}
                            <a href="{{ route('feedback.prompt.download', ['id' => $item->id]) }}"
                               class="btn-pill-nav text-xs py-1 px-2 text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] flex items-center gap-1 cursor-pointer"
                               title="Download markdown prompt for this item">
                                <i class="fa-solid fa-download text-[10px]"></i>
                                <span>.md</span>
                            </a>
                        </div>

                        {{-- Quick Status Changer & Delete --}}
                        <div class="flex items-center gap-2">
                            <select x-model="itemStatus"
                                    @change="updateStatus($event.target.value)"
                                    class="text-xs rounded border border-[var(--color-border)] bg-[var(--color-surface)] py-1 px-2 text-[var(--color-ink-strong)] font-data cursor-pointer focus:outline-none focus:border-[var(--color-brand)]">
                                <option value="open">Open</option>
                                <option value="approved">Approved</option>
                                <option value="in_progress">In Progress</option>
                                <option value="resolved">Resolved</option>
                                <option value="dismissed">Dismissed</option>
                            </select>

                            <form method="POST" action="{{ route('feedback.destroy', $item) }}" class="inline" onsubmit="return confirm('Delete this feedback item?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-[var(--color-ink-muted)] hover:text-rose-600 p-1 cursor-pointer" title="Delete note">
                                    <i class="fa-solid fa-trash-can text-xs"></i>
                                </button>
                            </form>
                        </div>
                    </div>

                    {{-- Discussion Thread (Expandable) --}}
                    <div x-show="expanded" x-transition x-cloak class="mt-4 pt-4 border-t border-[var(--color-border-light)] space-y-3">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-muted)]">
                            Discussion Thread
                        </div>

                        @if ($item->comments->isEmpty())
                            <p class="text-xs text-[var(--color-ink-muted)] italic">No replies yet. Leave the first comment below.</p>
                        @else
                            <div class="space-y-2">
                                @foreach ($item->comments as $comment)
                                    <div class="p-3 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] space-y-1">
                                        <div class="flex items-center justify-between text-[10px]">
                                            <div class="flex items-center gap-1.5 font-semibold text-[var(--color-ink-strong)]">
                                                @if ($comment->user?->avatarUrl(40))
                                                    <img src="{{ $comment->user->avatarUrl(40) }}" alt="" class="w-3.5 h-3.5 rounded-full">
                                                @endif
                                                <span>{{ $comment->user?->name ?? 'User' }}</span>
                                            </div>
                                            <span class="text-[var(--color-ink-muted)]">{{ $comment->created_at->diffForHumans() }}</span>
                                        </div>
                                        <div class="text-xs text-[var(--color-ink)] whitespace-pre-wrap">{{ $comment->content }}</div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Add Reply Form --}}
                        <form method="POST" action="{{ route('feedback.comments.store', $item) }}" class="space-y-2 pt-2">
                            @csrf
                            <textarea
                                name="content"
                                rows="2"
                                placeholder="Reply to this thread…"
                                required
                                class="w-full text-xs rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-2.5 text-[var(--color-ink-strong)] focus:outline-none focus:border-[var(--color-brand)] resize-none"
                            ></textarea>
                            <div class="flex justify-end">
                                <button type="submit" class="btn-primary text-xs py-1 px-3 cursor-pointer">
                                    Send Reply
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Pagination --}}
        <div>
            {{ $items->links() }}
        </div>
    @endif

    {{-- Batch Prompt Modal --}}
    <div x-show="batchModalOpen"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs text-xs"
         @click.self="batchModalOpen = false"
         x-cloak>
        <div class="card p-6 max-w-3xl w-full max-h-[85vh] flex flex-col shadow-2xl relative bg-[var(--color-surface)] border border-[var(--color-border)] space-y-4"
             @click.stop>
            <div class="flex items-start justify-between shrink-0">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-full bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                        <i class="fa-solid fa-wand-magic-sparkles text-sm"></i>
                    </div>
                    <div>
                        <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)]">Implementation Master Prompt</h3>
                        <p class="text-[11px] text-[var(--color-ink-muted)]">
                            Bundling <span class="font-bold text-[var(--color-ink-strong)]" x-text="batchCount"></span> item(s) for Claude & Grok.
                        </p>
                    </div>
                </div>
                <button type="button" @click="batchModalOpen = false" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] p-1 cursor-pointer">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>

            {{-- Scope Selector Pills inside Modal --}}
            <div class="flex items-center justify-between gap-2 border-b border-[var(--color-border-light)] pb-2 flex-wrap">
                <div class="flex items-center gap-1.5 flex-wrap">
                    <span class="text-[10px] uppercase tracking-wider text-[var(--color-ink-muted)] font-semibold mr-1">Batch Scope:</span>
                    <button type="button"
                            @click="openBatchModal('approved')"
                            :class="batchStatus === 'approved' ? 'bg-emerald-600 text-white font-semibold shadow-xs' : 'bg-[var(--color-surface-alt)] text-[var(--color-ink)] hover:bg-[var(--color-border-light)]'"
                            class="px-2.5 py-1 rounded-full text-[11px] transition-colors cursor-pointer flex items-center gap-1">
                        <i class="fa-solid fa-check text-[9px]"></i>
                        <span>Approved Items</span>
                        <span class="font-data text-[10px] opacity-80 font-bold">({{ $stats['approved'] }})</span>
                    </button>

                    <button type="button"
                            @click="openBatchModal('all')"
                            :class="batchStatus === 'all' ? 'bg-[var(--color-brand)] text-white font-semibold shadow-xs' : 'bg-[var(--color-surface-alt)] text-[var(--color-ink)] hover:bg-[var(--color-border-light)]'"
                            class="px-2.5 py-1 rounded-full text-[11px] transition-colors cursor-pointer flex items-center gap-1">
                        <span>All Items</span>
                        <span class="font-data text-[10px] opacity-80 font-bold">({{ $stats['total'] }})</span>
                    </button>

                    <button type="button"
                            @click="openBatchModal('open')"
                            :class="batchStatus === 'open' ? 'bg-amber-500 text-white font-semibold shadow-xs' : 'bg-[var(--color-surface-alt)] text-[var(--color-ink)] hover:bg-[var(--color-border-light)]'"
                            class="px-2.5 py-1 rounded-full text-[11px] transition-colors cursor-pointer flex items-center gap-1">
                        <span>Open Only</span>
                        <span class="font-data text-[10px] opacity-80 font-bold">({{ $stats['open'] }})</span>
                    </button>
                </div>

                <template x-if="batchCount > 0">
                    <span class="text-[10px] text-emerald-600 dark:text-emerald-400 font-medium flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                        <span>Ready to copy & download</span>
                    </span>
                </template>
            </div>

            {{-- Textarea with generated prompt --}}
            <div class="flex-1 overflow-hidden flex flex-col">
                <template x-if="batchLoading">
                    <div class="p-12 text-center text-[var(--color-ink-muted)]">
                        <i class="fa-solid fa-spinner fa-spin text-xl mb-2 text-[var(--color-brand)]"></i>
                        <p>Compiling prompt for items…</p>
                    </div>
                </template>

                <template x-if="!batchLoading">
                    <textarea
                        x-model="batchPromptText"
                        readonly
                        placeholder="No prompt generated yet."
                        class="w-full h-80 font-mono text-[11px] p-3 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] focus:outline-none resize-none leading-relaxed"
                    ></textarea>
                </template>
            </div>

            {{-- Modal Actions --}}
            <div class="flex items-center justify-between pt-3 border-t border-[var(--color-border-light)] shrink-0 flex-wrap gap-2">
                <span class="text-[11px] text-[var(--color-ink-muted)]">
                    Paste directly into Claude, Grok, or Antigravity to implement all changes.
                </span>

                <div class="flex items-center gap-2">
                    <a :href="'{{ route('feedback.prompt.download') }}?status=' + encodeURIComponent(batchStatus)"
                       :class="batchCount === 0 ? 'pointer-events-none opacity-40' : ''"
                       class="btn-pill-nav text-xs py-1.5 px-3 flex items-center gap-1.5 cursor-pointer font-medium hover:border-[var(--color-brand)]">
                        <i class="fa-solid fa-download text-[11px]"></i>
                        <span>Download .md</span>
                    </a>

                    <button type="button"
                            @click="copyBatchPrompt()"
                            :disabled="batchCount === 0 || !batchPromptText"
                            class="btn-primary text-xs py-1.5 px-3.5 flex items-center gap-1.5 cursor-pointer font-semibold disabled:opacity-40">
                        <i class="fa-solid" :class="batchCopied ? 'fa-check text-emerald-300' : 'fa-copy'"></i>
                        <span x-text="batchCopied ? 'Copied to Clipboard!' : 'Copy Entire Prompt'"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
