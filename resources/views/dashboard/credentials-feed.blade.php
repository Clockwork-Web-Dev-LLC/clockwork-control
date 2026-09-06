@extends('layouts.app')

@section('title', 'Paste credentials feed · Clockwork')

@php
    $statusMeta = [
        'matched' => ['label' => 'Matched', 'class' => 'status-green', 'icon' => 'fa-circle-check'],
        'unmatched' => ['label' => 'Will create', 'class' => 'status-yellow', 'icon' => 'fa-plus'],
        'ambiguous' => ['label' => 'Ambiguous', 'class' => 'status-yellow', 'icon' => 'fa-triangle-exclamation'],
        'name_ip_conflict' => ['label' => 'Name/IP conflict', 'class' => 'status-yellow', 'icon' => 'fa-triangle-exclamation'],
    ];
@endphp

@section('content')
    <x-page-header title="Paste credentials feed"
        subtitle="Paste a block-separated text feed of servers, IP addresses, and SSH passwords to bulk configure credentials.">
        <x-slot:actions>
            <a href="{{ route('servers.credentials.bulk') }}" class="btn-pill-nav text-sm">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Back to credentials</span>
            </a>
            @if ($entries !== null)
                <a href="{{ route('servers.credentials.feed') }}" class="btn-pill-nav text-sm">
                    <i class="fa-solid fa-arrow-rotate-left"></i>
                    <span>Start over</span>
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($entries === null)
        <div class="mb-6">
            <p class="text-[var(--color-ink-muted)] text-sm max-w-2xl mb-2">
                Paste a block-separated text feed in this format:
            </p>
            <pre class="bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] rounded-md p-3 font-data text-xs text-[var(--color-ink-muted)] max-w-2xl">srv01.example.com
198.51.100.50
ssh deploy@198.51.100.50
&lt;password&gt;
-----------------------
srv02.example.com
198.51.100.51
ssh deploy@198.51.100.51
&lt;password&gt;</pre>
        </div>

        <form method="POST" action="{{ route('servers.credentials.feedParse') }}" class="card p-6">
            @csrf
            <label class="block">
                <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">Feed</span>
                <textarea name="feed" rows="14" required
                          autocomplete="off" spellcheck="false"
                          class="mt-2 w-full font-data text-sm border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]"
                          placeholder="Paste your full feed here…">{{ old('feed', $feed) }}</textarea>
            </label>
            <div class="mt-4 flex items-center gap-3">
                <button type="submit" class="btn-primary">
                    <i class="fa-solid fa-magnifying-glass"></i> Parse & preview
                </button>
                <span class="text-xs text-[var(--color-ink-soft)]">Nothing is saved until you confirm in the next step.</span>
            </div>
        </form>
    @else
        @php
            $matched = collect($entries)->where('status', 'matched')->count();
            $willCreate = collect($entries)->where('status', 'unmatched')->count();
            $other = count($entries) - $matched - $willCreate;
        @endphp

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
            <div class="card px-4 py-3">
                <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Total Parsed</div>
                <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">{{ count($entries) }}</div>
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Entries in feed</div>
            </div>
            <div class="card px-4 py-3">
                <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Matched Existing</div>
                <div class="text-2xl font-display text-[var(--color-status-green)] font-data">{{ $matched }}</div>
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Will update credentials</div>
            </div>
            <div class="card px-4 py-3">
                <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Will Create</div>
                <div class="text-2xl font-display {{ $willCreate > 0 ? 'text-[var(--color-status-yellow)]' : 'text-[var(--color-ink-strong)]' }} font-data">{{ $willCreate }}</div>
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">New servers added</div>
            </div>
            <div class="card px-4 py-3">
                <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Need Review</div>
                <div class="text-2xl font-display {{ $other > 0 ? 'text-[var(--color-status-red)]' : 'text-[var(--color-ink-strong)]' }} font-data">{{ $other }}</div>
                <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Ambiguous or conflict</div>
            </div>
        </div>

        <form method="POST" action="{{ route('servers.credentials.feedApply') }}" autocomplete="off">
            @csrf

            <div class="card overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                        <tr>
                            <th class="text-left px-4 py-3 w-10">
                                <input type="checkbox" id="check-all" checked class="rounded border-[var(--color-border)]" title="Toggle all">
                            </th>
                            <th class="text-left px-4 py-3">Feed entry</th>
                            <th class="text-left px-4 py-3">Match</th>
                            <th class="text-left px-4 py-3">Notes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @foreach ($entries as $i => $entry)
                            @php
                                $meta = $statusMeta[$entry['status']] ?? ['label' => $entry['status'], 'class' => 'status-unknown', 'icon' => 'fa-circle-question'];
                                // Apply is allowed for matched updates AND for unmatched creates.
                                // Ambiguous still blocks (operator must resolve which server is meant).
                                $canApply = in_array($entry['status'], ['matched', 'name_ip_conflict', 'unmatched'], true);
                                $isCreate = $entry['status'] === 'unmatched';
                            @endphp
                            <tr>
                                <td class="px-4 py-3 align-top">
                                    @if ($canApply)
                                        <input type="checkbox" name="entries[{{ $i }}][apply]" value="1" checked
                                               class="apply-cb rounded border-[var(--color-border)]">
                                        <input type="hidden" name="entries[{{ $i }}][server_id]" value="{{ $entry['server_id'] }}">
                                        <input type="hidden" name="entries[{{ $i }}][password]" value="{{ $entry['password'] }}">
                                        <input type="hidden" name="entries[{{ $i }}][user]" value="{{ $entry['user'] }}">
                                        <input type="hidden" name="entries[{{ $i }}][port]" value="{{ $entry['port'] }}">
                                        @if ($isCreate)
                                            {{-- Create-only fields: name + ip from the feed feed the new Server row's name + hostname --}}
                                            <input type="hidden" name="entries[{{ $i }}][name]" value="{{ $entry['name'] }}">
                                            <input type="hidden" name="entries[{{ $i }}][ip]" value="{{ $entry['ip'] }}">
                                        @endif
                                        @if ($entry['user_mismatch'])
                                            <label class="block mt-2 text-[10px] text-[var(--color-ink-muted)] whitespace-nowrap">
                                                <input type="checkbox" name="entries[{{ $i }}][update_user]" value="1" class="rounded border-[var(--color-border)]"> overwrite user
                                            </label>
                                        @endif
                                    @endif
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <div class="font-medium text-[var(--color-ink-strong)]">{{ $entry['name'] }}</div>
                                    <div class="text-xs font-data text-[var(--color-ink-muted)]">{{ $entry['user'] . '@' . $entry['ip'] }}<span class="text-[var(--color-ink-soft)]">:{{ $entry['port'] }}</span></div>
                                    <div class="text-xs text-[var(--color-ink-soft)] mt-1">password: {{ strlen((string) $entry['password']) }} chars</div>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <span class="status-pill {{ $meta['class'] }}">
                                        <i class="fa-solid {{ $meta['icon'] }}"></i>
                                        {{ $meta['label'] }}
                                    </span>
                                    @if ($entry['matched_name'])
                                        <div class="text-xs text-[var(--color-ink-muted)] mt-2">
                                            ↳ <span class="font-medium text-[var(--color-ink-strong)]">{{ $entry['matched_name'] }}</span>
                                            <span class="font-data text-[var(--color-ink-soft)]">{{ $entry['matched_hostname'] }}</span>
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 align-top text-xs text-[var(--color-ink-muted)] space-y-1">
                                    @if ($entry['ignored'])
                                        <div><i class="fa-solid fa-eye-slash"></i> Server is currently ignored — credentials will still be stored</div>
                                    @endif
                                    @if ($entry['has_password_already'])
                                        <div><i class="fa-solid fa-rotate"></i> Will overwrite an existing stored password</div>
                                    @endif
                                    @if ($entry['user_mismatch'])
                                        <div class="text-[var(--color-status-yellow)]">
                                            <i class="fa-solid fa-triangle-exclamation"></i>
                                            Feed user <code class="font-data">{{ $entry['user'] }}</code> differs from stored <code class="font-data">{{ $entry['matched_user'] }}</code>
                                        </div>
                                    @endif
                                    @if ($entry['status'] === 'unmatched')
                                        <div class="text-[var(--color-ink-strong)]"><i class="fa-solid fa-plus"></i> No existing server matches — a new server row will be created with this name + IP + credentials.</div>
                                    @elseif ($entry['status'] === 'ambiguous')
                                        <div>IP matches multiple servers. Resolve manually.</div>
                                    @elseif ($entry['status'] === 'name_ip_conflict')
                                        <div>Name and IP point to different servers — using the name match.</div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-6 flex items-center gap-3">
                <button type="submit" class="btn-primary">
                    <i class="fa-solid fa-floppy-disk"></i> Apply checked credentials
                </button>
                <span class="text-xs text-[var(--color-ink-soft)]">Passwords are encrypted on save and never displayed again.</span>
            </div>
        </form>

        <script>
            (function () {
                const all = document.getElementById('check-all');
                if (!all) return;
                const rows = document.querySelectorAll('.apply-cb');
                all.addEventListener('change', () => rows.forEach(r => { r.checked = all.checked; }));
            })();
        </script>
    @endif
@endsection
