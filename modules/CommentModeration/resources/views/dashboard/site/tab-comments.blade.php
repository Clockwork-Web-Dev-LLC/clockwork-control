@php
    $comments = $commentsData['comments'] ?? [];
    $counts = $commentsData['counts'] ?? [
        'all' => 0,
        'moderated' => 0,
        'approved' => 0,
        'spam' => 0,
        'trash' => 0,
    ];
    $total = (int) ($commentsData['total'] ?? 0);
    $totalPages = (int) ($commentsData['total_pages'] ?? 1);
    $currentPage = (int) ($commentsData['page'] ?? $currentPage ?? 1);
@endphp

{{-- Maintenance Mode Card --}}
@if ($maintenanceStatus !== null)
    <div class="card p-5 mb-6 border-l-4 {{ !empty($maintenanceStatus['enabled']) ? 'border-amber-500 bg-amber-500/5' : 'border-emerald-500' }}">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold {{ !empty($maintenanceStatus['enabled']) ? 'bg-amber-500 text-white' : 'bg-emerald-500/10 text-emerald-600' }}">
                        <i class="fa-solid {{ !empty($maintenanceStatus['enabled']) ? 'fa-person-digging' : 'fa-circle-check' }}"></i>
                        {{ !empty($maintenanceStatus['enabled']) ? 'Maintenance Mode ACTIVE' : 'Site is Live' }}
                    </span>
                    <h3 class="font-display font-semibold text-[var(--color-ink-strong)]">
                        Maintenance Mode
                    </h3>
                </div>
                <p class="text-xs text-[var(--color-ink-soft)] mt-1">
                    @if (!empty($maintenanceStatus['enabled']))
                        Public visitors receive HTTP 503 with the message: &ldquo;{{ $maintenanceStatus['title'] ?? 'Maintenance in Progress' }}&rdquo;.
                    @else
                        Turn on maintenance mode during updates or troubleshooting to display a styled maintenance screen to public visitors.
                    @endif
                </p>
                @if (!empty($maintenanceStatus['enabled']) && !empty($maintenanceStatus['bypass_key_configured']))
                    <p class="text-xs text-[var(--color-ink-muted)] mt-1 font-mono">
                        Bypass URL parameter: <span class="text-[var(--color-brand)]">?cw_bypass=...</span>
                    </p>
                @endif
            </div>
            <div>
                <form method="POST" action="{{ route('sites.maintenance-mode.update', $site) }}" class="inline-flex items-center gap-2">
                    @csrf
                    @if (!empty($maintenanceStatus['enabled']))
                        <input type="hidden" name="enabled" value="0">
                        <button type="submit" class="btn-pill-nav text-xs bg-emerald-600 hover:bg-emerald-700 text-white border-none px-4 py-2 font-medium">
                            <i class="fa-solid fa-power-off mr-1"></i> Disable Maintenance Mode
                        </button>
                    @else
                        <input type="hidden" name="enabled" value="1">
                        <button type="submit" class="btn-pill-nav text-xs bg-amber-600 hover:bg-amber-700 text-white border-none px-4 py-2 font-medium"
                                onclick="return confirm('Enable maintenance mode on {{ $site->domain }}? Visitors will see a 503 maintenance page.');">
                            <i class="fa-solid fa-person-digging mr-1"></i> Enable Maintenance Mode
                        </button>
                    @endif
                </form>
            </div>
        </div>
    </div>
@endif

{{-- Main Comments Management Card --}}
<div class="card p-5 mb-10" x-data="{
    selected: [],
    selectAll: false,
    toggleAll() {
        if (this.selectAll) {
            this.selected = {{ json_encode(array_column($comments, 'id')) }};
        } else {
            this.selected = [];
        }
    }
}">
    {{-- Header & Actions --}}
    <div class="flex items-center justify-between mb-6 flex-wrap gap-4 border-b border-[var(--color-border-light)] pb-4">
        <div>
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] flex items-center gap-2">
                <i class="fa-solid fa-comments text-[var(--color-ink-soft)]"></i>
                WordPress Comments
            </h2>
            <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                Manage, moderate, and auto-purge discussion comments on <span class="font-data">{{ $site->domain }}</span>.
            </p>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
            {{-- Purge Spam & Trash Button --}}
            <form method="POST" action="{{ route('sites.comments.cleanup', $site) }}"
                  onsubmit="return confirm('Purge all spam and trash comments older than 30 days on {{ $site->domain }}? This action cannot be undone.');">
                @csrf
                <input type="hidden" name="older_than_days" value="30">
                <button type="submit" class="btn-pill-nav text-xs text-rose-600 hover:bg-rose-50 hover:border-rose-300 transition-colors">
                    <i class="fa-solid fa-broom mr-1"></i> Purge Spam/Trash (> 30d)
                </button>
            </form>

            <a href="{{ route('sites.show', ['site' => $site, 'tab' => 'comments', 'status' => $currentStatus]) }}" class="btn-pill-nav text-xs" title="Refresh comments">
                <i class="fa-solid fa-rotate-right"></i> Refresh
            </a>
        </div>
    </div>

    {{-- Error Banner if Companion Failed --}}
    @if ($commentsError)
        <div class="card p-4 mb-6 border-l-4 border-rose-500 bg-rose-500/5 text-sm text-[var(--color-ink)]">
            <div class="flex items-start gap-3">
                <i class="fa-solid fa-circle-exclamation text-rose-500 mt-0.5"></i>
                <div>
                    <strong class="font-semibold text-rose-700">Unable to load comments from site:</strong>
                    <p class="text-xs text-[var(--color-ink-muted)] mt-1 font-mono break-all">{{ $commentsError }}</p>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-2">
                        Ensure the Clockwork Companion plugin is active and updated on {{ $site->domain }}.
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- Filter Nav Tabs --}}
    <div class="flex items-center justify-between flex-wrap gap-4 mb-5">
        <div class="flex items-center gap-1 overflow-x-auto text-xs font-medium">
            @php
                $statusFilters = [
                    'all' => ['label' => 'All', 'count' => $counts['all'] ?? 0],
                    'hold' => ['label' => 'Pending Moderation', 'count' => $counts['moderated'] ?? 0],
                    'approve' => ['label' => 'Approved', 'count' => $counts['approved'] ?? 0],
                    'spam' => ['label' => 'Spam', 'count' => $counts['spam'] ?? 0],
                    'trash' => ['label' => 'Trash', 'count' => $counts['trash'] ?? 0],
                ];
            @endphp
            @foreach ($statusFilters as $filterKey => $meta)
                @php $isActive = ($currentStatus === $filterKey); @endphp
                <a href="{{ route('sites.show', ['site' => $site, 'tab' => 'comments', 'status' => $filterKey]) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full transition-colors {{ $isActive ? 'bg-[var(--color-brand)] text-white' : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)] hover:text-[var(--color-ink)]' }}">
                    {{ $meta['label'] }}
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] {{ $isActive ? 'bg-white/20 text-white' : 'bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)]' }}">
                        {{ $meta['count'] }}
                    </span>
                </a>
            @endforeach
        </div>

        {{-- Search Input --}}
        <form method="GET" action="{{ route('sites.show', ['site' => $site, 'tab' => 'comments']) }}" class="flex items-center gap-2">
            <input type="hidden" name="status" value="{{ $currentStatus }}">
            <div class="relative">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-xs text-[var(--color-ink-soft)]"></i>
                <input type="text" name="search" value="{{ $searchQuery }}" placeholder="Search comments..."
                       class="text-xs pl-8 pr-3 py-1.5 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface)] focus:border-[var(--color-brand)] focus:ring-0 outline-none w-48 sm:w-64">
            </div>
            <button type="submit" class="btn-pill-nav text-xs py-1.5">Filter</button>
            @if ($searchQuery !== '')
                <a href="{{ route('sites.show', ['site' => $site, 'tab' => 'comments', 'status' => $currentStatus]) }}" class="text-xs text-[var(--color-ink-soft)] hover:text-rose-500" title="Clear search">
                    <i class="fa-solid fa-xmark"></i>
                </a>
            @endif
        </form>
    </div>

    {{-- Bulk Action Form Bar --}}
    @if (!empty($comments))
        <form id="bulk-moderate-form" method="POST" action="{{ route('sites.comments.moderate', $site) }}" class="mb-4">
            @csrf
            <template x-for="id in selected" :key="id">
                <input type="hidden" name="comment_ids[]" :value="id">
            </template>

            <div class="flex items-center gap-2 flex-wrap text-xs bg-[var(--color-surface-alt)] p-2.5 rounded-lg border border-[var(--color-border-light)]" x-show="selected.length > 0" x-cloak>
                <span class="font-semibold text-[var(--color-ink-strong)]" x-text="selected.length + ' selected'"></span>
                <span class="text-[var(--color-border)]">|</span>
                <button type="submit" name="action" value="approve" class="px-2.5 py-1 rounded bg-emerald-600 hover:bg-emerald-700 text-white font-medium">
                    <i class="fa-solid fa-check mr-1"></i> Approve
                </button>
                <button type="submit" name="action" value="hold" class="px-2.5 py-1 rounded bg-amber-600 hover:bg-amber-700 text-white font-medium">
                    <i class="fa-solid fa-pause mr-1"></i> Hold
                </button>
                <button type="submit" name="action" value="spam" class="px-2.5 py-1 rounded bg-orange-600 hover:bg-orange-700 text-white font-medium">
                    <i class="fa-solid fa-shield-virus mr-1"></i> Spam
                </button>
                <button type="submit" name="action" value="trash" class="px-2.5 py-1 rounded bg-rose-600 hover:bg-rose-700 text-white font-medium">
                    <i class="fa-solid fa-trash-can mr-1"></i> Trash
                </button>
                @if ($currentStatus === 'trash')
                    <button type="submit" name="action" value="delete" class="px-2.5 py-1 rounded bg-red-800 hover:bg-red-900 text-white font-medium"
                            onclick="return confirm('Permanently delete selected comments?');">
                        <i class="fa-solid fa-skull mr-1"></i> Delete Permanently
                    </button>
                @endif
            </div>
        </form>
    @endif

    {{-- Comments Table --}}
    @if (empty($comments))
        <div class="text-center py-12 text-sm text-[var(--color-ink-soft)]">
            <i class="fa-solid fa-comments text-3xl mb-3 block opacity-40"></i>
            No comments found for status: <span class="font-medium text-[var(--color-ink-strong)]">{{ $currentStatus }}</span>.
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide border-y border-[var(--color-border-light)]">
                    <tr>
                        <th class="px-4 py-3 w-10">
                            <input type="checkbox" x-model="selectAll" @change="toggleAll()" class="rounded text-[var(--color-brand)] focus:ring-0">
                        </th>
                        <th class="px-4 py-3 w-56">Author</th>
                        <th class="px-4 py-3">Comment</th>
                        <th class="px-4 py-3 w-44">In Response To</th>
                        <th class="px-4 py-3 w-36 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($comments as $c)
                        @php
                            $cId = (int) $c['id'];
                            $cStatus = (string) ($c['status'] ?? 'approved');
                            $statusBadge = match ($cStatus) {
                                'hold', 'unapproved' => ['class' => 'bg-amber-100 text-amber-800', 'label' => 'Pending'],
                                'spam' => ['class' => 'bg-orange-100 text-orange-800', 'label' => 'Spam'],
                                'trash' => ['class' => 'bg-rose-100 text-rose-800', 'label' => 'Trash'],
                                default => ['class' => 'bg-emerald-100 text-emerald-800', 'label' => 'Approved'],
                            };
                        @endphp
                        <tr class="hover:bg-[var(--color-surface-alt)]/50 transition-colors">
                            {{-- Checkbox --}}
                            <td class="px-4 py-3 align-top">
                                <input type="checkbox" value="{{ $cId }}" x-model="selected" class="rounded text-[var(--color-brand)] focus:ring-0">
                            </td>

                            {{-- Author --}}
                            <td class="px-4 py-3 align-top">
                                <div class="font-medium text-[var(--color-ink-strong)]">
                                    {{ $c['author_name'] ?: 'Anonymous' }}
                                </div>
                                @if (!empty($c['author_email']))
                                    <div class="text-xs text-[var(--color-ink-muted)] truncate max-w-[13rem]">
                                        <a href="mailto:{{ $c['author_email'] }}" class="hover:underline">{{ $c['author_email'] }}</a>
                                    </div>
                                @endif
                                @if (!empty($c['author_ip']))
                                    <div class="text-xs text-[var(--color-ink-soft)] font-mono mt-0.5">
                                        <x-ip-link :ip="$c['author_ip']" />
                                    </div>
                                @endif
                                <div class="mt-1">
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold {{ $statusBadge['class'] }}">
                                        {{ $statusBadge['label'] }}
                                    </span>
                                </div>
                            </td>

                            {{-- Comment Content --}}
                            <td class="px-4 py-3 align-top">
                                <div class="text-xs text-[var(--color-ink-soft)] mb-1">
                                    Submitted {{ !empty($c['date']) ? \Illuminate\Support\Carbon::parse($c['date'])->diffForHumans() : 'recently' }}
                                </div>
                                <div class="text-xs text-[var(--color-ink)] leading-relaxed line-clamp-4 font-sans max-w-xl">
                                    {{ strip_tags((string) ($c['content'] ?? '')) }}
                                </div>
                            </td>

                            {{-- Post Context --}}
                            <td class="px-4 py-3 align-top text-xs text-[var(--color-ink-muted)]">
                                @if (!empty($c['post_title']))
                                    @if (!empty($c['post_url']))
                                        <a href="{{ $c['post_url'] }}" target="_blank" rel="noopener noreferrer" class="font-medium text-[var(--color-ink-strong)] hover:text-[var(--color-brand)] hover:underline block line-clamp-2">
                                            {{ $c['post_title'] }}
                                        </a>
                                    @else
                                        <span class="font-medium text-[var(--color-ink-strong)] block line-clamp-2">
                                            {{ $c['post_title'] }}
                                        </span>
                                    @endif
                                @else
                                    <span class="text-[var(--color-ink-soft)]">—</span>
                                @endif
                            </td>

                            {{-- Single Actions --}}
                            <td class="px-4 py-3 align-top text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1.5">
                                    @if ($cStatus !== 'approved')
                                        <form method="POST" action="{{ route('sites.comments.moderate', $site) }}" class="inline">
                                            @csrf
                                            <input type="hidden" name="comment_ids[]" value="{{ $cId }}">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="p-1.5 text-emerald-600 hover:bg-emerald-50 rounded" title="Approve">
                                                <i class="fa-solid fa-check"></i>
                                            </button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('sites.comments.moderate', $site) }}" class="inline">
                                            @csrf
                                            <input type="hidden" name="comment_ids[]" value="{{ $cId }}">
                                            <input type="hidden" name="action" value="hold">
                                            <button type="submit" class="p-1.5 text-amber-600 hover:bg-amber-50 rounded" title="Unapprove / Hold">
                                                <i class="fa-solid fa-pause"></i>
                                            </button>
                                        </form>
                                    @endif

                                    @if ($cStatus !== 'spam')
                                        <form method="POST" action="{{ route('sites.comments.moderate', $site) }}" class="inline">
                                            @csrf
                                            <input type="hidden" name="comment_ids[]" value="{{ $cId }}">
                                            <input type="hidden" name="action" value="spam">
                                            <button type="submit" class="p-1.5 text-orange-600 hover:bg-orange-50 rounded" title="Mark as Spam">
                                                <i class="fa-solid fa-shield-virus"></i>
                                            </button>
                                        </form>
                                    @endif

                                    @if ($cStatus !== 'trash')
                                        <form method="POST" action="{{ route('sites.comments.moderate', $site) }}" class="inline">
                                            @csrf
                                            <input type="hidden" name="comment_ids[]" value="{{ $cId }}">
                                            <input type="hidden" name="action" value="trash">
                                            <button type="submit" class="p-1.5 text-rose-600 hover:bg-rose-50 rounded" title="Move to Trash">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('sites.comments.moderate', $site) }}" class="inline"
                                              onsubmit="return confirm('Permanently delete this comment?');">
                                            @csrf
                                            <input type="hidden" name="comment_ids[]" value="{{ $cId }}">
                                            <input type="hidden" name="action" value="delete">
                                            <button type="submit" class="p-1.5 text-red-700 hover:bg-red-50 rounded" title="Delete Permanently">
                                                <i class="fa-solid fa-skull"></i>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if ($totalPages > 1)
            <div class="flex items-center justify-between border-t border-[var(--color-border-light)] pt-4 mt-4 text-xs">
                <span class="text-[var(--color-ink-soft)]">
                    Showing page {{ $currentPage }} of {{ $totalPages }} ({{ $total }} total comments)
                </span>
                <div class="flex items-center gap-1">
                    @if ($currentPage > 1)
                        <a href="{{ route('sites.show', ['site' => $site, 'tab' => 'comments', 'status' => $currentStatus, 'page' => $currentPage - 1, 'search' => $searchQuery]) }}"
                           class="btn-pill-nav text-xs">
                            <i class="fa-solid fa-chevron-left mr-1"></i> Previous
                        </a>
                    @endif
                    @if ($currentPage < $totalPages)
                        <a href="{{ route('sites.show', ['site' => $site, 'tab' => 'comments', 'status' => $currentStatus, 'page' => $currentPage + 1, 'search' => $searchQuery]) }}"
                           class="btn-pill-nav text-xs">
                            Next <i class="fa-solid fa-chevron-right ml-1"></i>
                        </a>
                    @endif
                </div>
            </div>
        @endif
    @endif
</div>
