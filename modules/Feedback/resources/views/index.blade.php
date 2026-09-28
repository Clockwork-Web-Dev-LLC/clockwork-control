@extends('layouts.app')

@section('title', 'Feedback & Backlog · Clockwork')

@section('content')
<div class="relative">
    <x-page-header title="Feedback & Backlog"
        subtitle="Visual in-app feedback, employee change requests, and threaded collaboration across Clockwork Control.">
        <x-slot:actions>
            <a href="{{ route('feedback.index') }}"
               class="btn-pill-nav inline-flex items-center gap-1.5 text-xs md:text-sm"
               title="Refresh feedback backlog">
                <i class="fa-solid fa-rotate"></i> <span>Refresh</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="card p-4 mb-6 status-green flex items-center gap-2 text-sm">
            <i class="fa-solid fa-circle-check"></i>
            {{ session('status') }}
        </div>
    @endif

    {{-- Stats Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <a href="{{ route('feedback.index', ['status' => 'all']) }}"
           class="card p-5 hover:border-[var(--color-brand)] transition-all cursor-pointer {{ $currentStatus === 'all' ? 'border-[var(--color-brand)] shadow-xs' : '' }}">
            <div class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)] mb-1">Total Submissions</div>
            <div class="text-3xl font-bold font-data text-[var(--color-ink-strong)]">{{ $stats['total'] }}</div>
            <div class="text-xs text-[var(--color-ink-muted)] mt-1">all-time feedback</div>
        </a>

        <a href="{{ route('feedback.index', ['status' => 'open']) }}"
           class="card p-5 hover:border-amber-500 transition-all cursor-pointer {{ $currentStatus === 'open' ? 'border-amber-500 shadow-xs' : '' }}">
            <div class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)] mb-1">Open Issues</div>
            <div class="text-3xl font-bold font-data text-amber-500">{{ $stats['open'] }}</div>
            <div class="text-xs text-[var(--color-ink-muted)] mt-1">awaiting review</div>
        </a>

        <a href="{{ route('feedback.index', ['status' => 'in_progress']) }}"
           class="card p-5 hover:border-blue-500 transition-all cursor-pointer {{ $currentStatus === 'in_progress' ? 'border-blue-500 shadow-xs' : '' }}">
            <div class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)] mb-1">In Progress</div>
            <div class="text-3xl font-bold font-data text-blue-500">{{ $stats['in_progress'] }}</div>
            <div class="text-xs text-[var(--color-ink-muted)] mt-1">under development</div>
        </a>

        <a href="{{ route('feedback.index', ['status' => 'resolved']) }}"
           class="card p-5 hover:border-[var(--color-status-green)] transition-all cursor-pointer {{ $currentStatus === 'resolved' ? 'border-[var(--color-status-green)] shadow-xs' : '' }}">
            <div class="text-xs uppercase tracking-wide text-[var(--color-ink-muted)] mb-1">Resolved</div>
            <div class="text-3xl font-bold font-data text-[var(--color-status-green)]">{{ $stats['resolved'] }}</div>
            <div class="text-xs text-[var(--color-ink-muted)] mt-1">completed & closed</div>
        </a>
    </div>

    {{-- Filter & Search Toolbar --}}
    <div class="card p-4 mb-6">
        <form method="GET" action="{{ route('feedback.index') }}" class="flex flex-wrap items-center gap-3">
            {{-- Status Tabs --}}
            <div class="flex items-center gap-1 text-xs">
                <a href="{{ route('feedback.index', array_merge(request()->query(), ['status' => 'active'])) }}"
                   class="px-2.5 py-1 rounded-full font-medium transition-all {{ $currentStatus === 'active' ? 'bg-[var(--color-brand)] text-white' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]' }}">
                    Active
                </a>
                <a href="{{ route('feedback.index', array_merge(request()->query(), ['status' => 'open'])) }}"
                   class="px-2.5 py-1 rounded-full font-medium transition-all {{ $currentStatus === 'open' ? 'bg-amber-500 text-white' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]' }}">
                    Open
                </a>
                <a href="{{ route('feedback.index', array_merge(request()->query(), ['status' => 'in_progress'])) }}"
                   class="px-2.5 py-1 rounded-full font-medium transition-all {{ $currentStatus === 'in_progress' ? 'bg-blue-500 text-white' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]' }}">
                    In Progress
                </a>
                <a href="{{ route('feedback.index', array_merge(request()->query(), ['status' => 'resolved'])) }}"
                   class="px-2.5 py-1 rounded-full font-medium transition-all {{ $currentStatus === 'resolved' ? 'bg-[var(--color-status-green)] text-white' : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]' }}">
                    Resolved
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
                <div class="card p-5 hover:border-[var(--color-brand)]/40 transition-all text-xs" x-data="{ expanded: false, promptCopied: false }">
                    {{-- Item Top Row: Badges, Path, Author, Date --}}
                    <div class="flex items-start justify-between flex-wrap gap-2 mb-2.5">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold font-data {{ $item->typeBadgeClass() }}">
                                {{ $item->formattedType() }}
                            </span>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold font-data {{ $item->statusBadgeClass() }}">
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
                        <div class="flex items-center gap-2">
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

                            {{-- One-Click Copy Claude Prompt Button --}}
                            <button type="button"
                                    @click="
                                        navigator.clipboard.writeText(@js($item->toClaudePrompt())).then(() => {
                                            promptCopied = true;
                                            setTimeout(() => promptCopied = false, 2500);
                                        });
                                    "
                                    class="btn-primary text-xs py-1 px-3 flex items-center gap-1.5 cursor-pointer">
                                <i class="fa-solid" :class="promptCopied ? 'fa-check text-emerald-300' : 'fa-wand-magic-sparkles'"></i>
                                <span x-text="promptCopied ? 'Copied Prompt!' : 'Copy Claude Prompt'"></span>
                            </button>
                        </div>

                        {{-- Quick Status Changer & Delete --}}
                        <div class="flex items-center gap-2">
                            <form method="POST" action="{{ route('feedback.update', $item) }}" class="inline">
                                @csrf
                                @method('PATCH')
                                <select name="status" onchange="this.form.submit()" class="text-xs rounded border border-[var(--color-border)] bg-[var(--color-surface)] py-1 px-2 text-[var(--color-ink-strong)] font-data cursor-pointer focus:outline-none focus:border-[var(--color-brand)]">
                                    <option value="open" {{ $item->status === 'open' ? 'selected' : '' }}>Open</option>
                                    <option value="in_progress" {{ $item->status === 'in_progress' ? 'selected' : '' }}>In Progress</option>
                                    <option value="resolved" {{ $item->status === 'resolved' ? 'selected' : '' }}>Resolved</option>
                                    <option value="dismissed" {{ $item->status === 'dismissed' ? 'selected' : '' }}>Dismissed</option>
                                </select>
                            </form>

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
</div>
@endsection
