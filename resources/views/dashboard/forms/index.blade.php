@extends('layouts.app')

@section('title', 'Forms · Clockwork')

@section('content')
    <x-page-header title="Forms"
        subtitle="Every configured contact-form test across the fleet. Care-plan benefit — non-care-plan sites can't schedule a test." />

    @if (session('status'))
        <div class="card p-3 mb-4 status-green text-sm">
            <i class="fa-solid fa-circle-check"></i> {{ session('status') }}
        </div>
    @endif
    @if (session('status_error'))
        <div class="card p-3 mb-4 status-red text-sm">
            <i class="fa-solid fa-triangle-exclamation"></i> {{ session('status_error') }}
        </div>
    @endif

    {{-- Filters --}}
    <form method="GET" action="{{ route('forms.index') }}" id="forms-filter-form" class="card p-4 mb-4 flex items-end gap-3 flex-wrap text-sm">
        <label class="block">
            <span class="block text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">State</span>
            <select name="state" id="forms-state-select" class="bg-[var(--color-surface)] border border-[var(--color-border)] rounded px-3 py-1.5 text-sm">
                <option value="all" @selected($stateFilter === 'all')>All</option>
                <option value="success" @selected($stateFilter === 'success')>Passing</option>
                <option value="failed" @selected($stateFilter === 'failed')>Failing</option>
                <option value="pending" @selected($stateFilter === 'pending')>Pending</option>
            </select>
        </label>
        <label class="block">
            <span class="block text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">Frequency</span>
            <select name="frequency" id="forms-frequency-select" class="bg-[var(--color-surface)] border border-[var(--color-border)] rounded px-3 py-1.5 text-sm">
                <option value="all" @selected($frequencyFilter === 'all')>All</option>
                <option value="weekly" @selected($frequencyFilter === 'weekly')>Weekly</option>
                <option value="daily" @selected($frequencyFilter === 'daily')>Daily</option>
            </select>
        </label>
        <label class="block flex-1 min-w-[12rem]">
            <span class="block text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">Site search</span>
            <div class="relative">
                <input type="text" name="q" id="forms-search" value="{{ $search }}" placeholder="example.com"
                    autocomplete="off"
                    class="bg-[var(--color-surface)] border border-[var(--color-border)] rounded pl-3 pr-8 py-1.5 text-sm w-full font-data focus:outline-none focus:border-[var(--color-brand)]">
                <button type="button" id="forms-search-clear" class="{{ $search !== '' ? '' : 'hidden' }} absolute right-2.5 top-1/2 -translate-y-1/2 text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] w-5 h-5 flex items-center justify-center text-xs" aria-label="Clear search">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </label>
        <button type="submit" class="btn-pill-nav text-sm">Filter</button>
        <a href="{{ route('forms.index') }}" id="forms-reset-link" class="text-xs text-[var(--color-ink-soft)] hover:underline">Reset</a>
    </form>

    {{-- Fleet table --}}
    <div class="card overflow-hidden" id="forms-card">
        <div id="forms-no-match" class="p-6 text-sm text-[var(--color-ink-muted)] text-center {{ $tests->isEmpty() ? '' : 'hidden' }}">
            No form-tests match. Add one from a site's Forms tab.
        </div>
        @if ($tests->isNotEmpty())
            <table class="w-full text-sm" id="forms-table">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <th class="px-5 py-2 text-left">Site</th>
                        <th class="px-5 py-2 text-left">Form</th>
                        <th class="px-5 py-2 text-left">Plugin</th>
                        <th class="px-5 py-2 text-left">Frequency</th>
                        <th class="px-5 py-2 text-left">Last run</th>
                        <th class="px-5 py-2 text-left">Streak</th>
                        <th class="px-5 py-2 text-left">State</th>
                        <th class="px-5 py-2 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($tests as $cft)
                        @php
                            $statePill = match ($cft->state) {
                                \App\Models\ContactFormTest::STATE_SUCCESS => ['class' => 'status-green', 'label' => 'Passing'],
                                \App\Models\ContactFormTest::STATE_FAILED => ['class' => 'status-red', 'label' => 'Failing'],
                                \App\Models\ContactFormTest::STATE_PENDING => ['class' => 'status-yellow', 'label' => 'Pending'],
                                default => ['class' => 'status-unknown', 'label' => '—'],
                            };
                        @endphp
                        <tr class="form-test-row" data-search="{{ strtolower($cft->site->domain . ' ' . $cft->form_id . ' ' . $cft->form_plugin) }}">
                            <td class="px-5 py-3 align-top">
                                <a href="{{ route('sites.show', ['site' => $cft->site, 'tab' => 'forms']) }}"
                                   class="font-data text-[var(--color-primary-600)] hover:underline">{{ $cft->site->domain }}</a>
                                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">slot {{ $cft->slot }}</div>
                            </td>
                            <td class="px-5 py-3 font-data text-[var(--color-ink-strong)]">{{ $cft->form_id }}</td>
                            <td class="px-5 py-3 font-data text-[var(--color-ink-muted)]">{{ $cft->form_plugin ?: '—' }}</td>
                            <td class="px-5 py-3 text-[var(--color-ink-muted)] capitalize">{{ $cft->frequency }}</td>
                            <td class="px-5 py-3 text-[var(--color-ink-muted)]"
                                title="{{ $cft->last_test_at?->toDateTimeString() }}">
                                {{ $cft->last_test_at?->diffForHumans() ?? '—' }}
                            </td>
                            <td class="px-5 py-3 text-[var(--color-ink-strong)]">{{ $cft->failure_streak }}</td>
                            <td class="px-5 py-3">
                                <span class="status-pill {{ $statePill['class'] }} text-[10px]">{{ $statePill['label'] }}</span>
                                @if (! $cft->enabled)
                                    <span class="status-pill status-unknown text-[10px] ml-1">disabled</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right">
                                <a href="{{ route('sites.show', ['site' => $cft->site, 'tab' => 'forms']) }}"
                                   class="btn-pill-nav text-xs">
                                    Open site →
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <script>
        (function () {
            const input = document.getElementById('forms-search');
            const clearBtn = document.getElementById('forms-search-clear');
            const form = document.getElementById('forms-filter-form');
            const stateSelect = document.getElementById('forms-state-select');
            const freqSelect = document.getElementById('forms-frequency-select');
            const card = document.getElementById('forms-card');
            let abortCtrl = null;
            let debounceTimer = null;

            function applyLocalFilter(q) {
                const query = (q || '').trim().toLowerCase();
                if (clearBtn) {
                    clearBtn.classList.toggle('hidden', query.length === 0);
                }

                const rows = card ? card.querySelectorAll('.form-test-row') : [];
                let visibleCount = 0;

                rows.forEach(row => {
                    const searchData = row.dataset.search || '';
                    const matched = query.length === 0 || searchData.includes(query);
                    row.style.display = matched ? '' : 'none';
                    if (matched) visibleCount++;
                });

                const noMatch = document.getElementById('forms-no-match');
                const table = document.getElementById('forms-table');
                if (noMatch) {
                    noMatch.classList.toggle('hidden', visibleCount > 0 || (rows.length === 0 && !query));
                }
                if (table) {
                    table.style.display = visibleCount > 0 ? '' : 'none';
                }
            }

            function fetchServerResults() {
                if (abortCtrl) {
                    abortCtrl.abort();
                }
                abortCtrl = new AbortController();

                const url = new URL(form.action, window.location.origin);
                const q = input ? input.value.trim() : '';
                if (q) {
                    url.searchParams.set('q', q);
                } else {
                    url.searchParams.delete('q');
                }

                if (stateSelect && stateSelect.value && stateSelect.value !== 'all') {
                    url.searchParams.set('state', stateSelect.value);
                } else {
                    url.searchParams.delete('state');
                }

                if (freqSelect && freqSelect.value && freqSelect.value !== 'all') {
                    url.searchParams.set('frequency', freqSelect.value);
                } else {
                    url.searchParams.delete('frequency');
                }

                window.history.replaceState(null, '', url.toString());

                fetch(url.toString(), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    signal: abortCtrl.signal
                })
                .then(res => res.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newCard = doc.getElementById('forms-card');

                    if (newCard && card) {
                        card.innerHTML = newCard.innerHTML;
                    }

                    applyLocalFilter(input ? input.value : '');
                })
                .catch(err => {
                    if (err.name !== 'AbortError') {
                        console.error('Error fetching forms:', err);
                    }
                });
            }

            function handleClear() {
                if (input) {
                    input.value = '';
                    applyLocalFilter('');
                    clearTimeout(debounceTimer);
                    fetchServerResults();
                    input.focus();
                }
            }

            if (input) {
                input.addEventListener('input', e => {
                    const val = e.target.value;
                    applyLocalFilter(val);

                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(() => {
                        fetchServerResults();
                    }, 250);
                });

                input.addEventListener('keydown', e => {
                    if (e.key === 'Escape') {
                        handleClear();
                    } else if (e.key === 'Enter') {
                        e.preventDefault();
                        clearTimeout(debounceTimer);
                        fetchServerResults();
                    }
                });
            }

            if (clearBtn) {
                clearBtn.addEventListener('click', handleClear);
            }

            if (stateSelect) {
                stateSelect.addEventListener('change', () => {
                    clearTimeout(debounceTimer);
                    fetchServerResults();
                });
            }

            if (freqSelect) {
                freqSelect.addEventListener('change', () => {
                    clearTimeout(debounceTimer);
                    fetchServerResults();
                });
            }

            if (form) {
                form.addEventListener('submit', e => {
                    e.preventDefault();
                    clearTimeout(debounceTimer);
                    fetchServerResults();
                });
            }

            // "/" shortcut
            document.addEventListener('keydown', e => {
                if (e.key === '/' && input && document.activeElement !== input
                        && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) {
                    e.preventDefault();
                    input.focus();
                    input.select();
                }
            });
        })();
    </script>
@endsection
