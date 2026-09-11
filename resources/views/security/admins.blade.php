@extends('layouts.app')

@section('title', 'WordPress admins · Clockwork')

@section('content')
    @php $activeTab = 'admins'; @endphp

    <x-page-header title="Security"
        subtitle="Every administrator-role WordPress user across Companion-installed sites. Flag default logins and, once you set an allowlist, unknown emails." />

    @include('security._tabs')

    @if (session('status'))
        <div class="mb-4 px-4 py-2 rounded-md bg-[var(--color-status-green)]/10 text-[var(--color-status-green)] text-sm">
            {{ session('status') }}
        </div>
    @endif

    <div class="card p-5 mb-6">
        <h2 class="font-display text-base font-semibold text-[var(--color-ink-strong)] mb-1">Approved emails</h2>
        <p class="text-xs text-[var(--color-ink-muted)] mb-3">
            Leave empty to only flag the default <code>admin</code> login. Once domains or emails are set, any other administrator email is flagged.
        </p>
        <form method="POST" action="{{ route('security.admins.allowlist') }}" class="grid grid-cols-1 md:grid-cols-2 gap-3">
            @csrf
            @method('PATCH')
            <label class="block text-xs">
                <span class="font-medium text-[var(--color-ink-strong)]">Approved email domains</span>
                <input type="text" name="approved_domains" value="{{ $approvedDomains }}"
                       placeholder="agency.com, ops.agency.com"
                       class="mt-1 w-full px-2 py-1.5 rounded border border-[var(--color-border)] text-xs bg-[var(--color-surface)]">
            </label>
            <label class="block text-xs">
                <span class="font-medium text-[var(--color-ink-strong)]">Approved emails</span>
                <input type="text" name="approved_emails" value="{{ $approvedEmails }}"
                       placeholder="owner@client.com"
                       class="mt-1 w-full px-2 py-1.5 rounded border border-[var(--color-border)] text-xs bg-[var(--color-surface)]">
            </label>
            <div class="md:col-span-2">
                <button type="submit" class="btn-pill-primary text-xs">Save allowlist</button>
                @unless ($allowlistConfigured)
                    <span class="ml-2 text-[11px] text-[var(--color-ink-muted)]">Allowlist empty — client owners are not flagged.</span>
                @endunless
            </div>
        </form>
    </div>

    <div class="mb-3 text-sm text-[var(--color-ink-muted)]">
        {{ number_format(count($rows)) }} administrators
        @if ($flaggedCount > 0)
            · <span class="text-amber-700 font-medium">{{ $flaggedCount }} flagged</span>
        @endif
    </div>

    <div class="card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-2 text-left">Site</th>
                    <th class="px-4 py-2 text-left">Login</th>
                    <th class="px-4 py-2 text-left">Email</th>
                    <th class="px-4 py-2 text-left">Last seen</th>
                    <th class="px-4 py-2 text-left">Flags</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--color-border-light)]">
                @forelse ($rows as $row)
                    <tr class="{{ $row['flagged'] ? 'bg-amber-500/5' : '' }}">
                        <td class="px-4 py-2">
                            <a href="{{ route('sites.show', $row['site_id']) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $row['domain'] }}</a>
                        </td>
                        <td class="px-4 py-2 font-data text-xs">{{ $row['login'] ?: '—' }}</td>
                        <td class="px-4 py-2 text-xs">{{ $row['email'] ?: '—' }}</td>
                        <td class="px-4 py-2 text-xs text-[var(--color-ink-muted)]">{{ $row['last_seen_at'] ? \Illuminate\Support\Carbon::parse($row['last_seen_at'])->diffForHumans() : '—' }}</td>
                        <td class="px-4 py-2 text-xs">
                            @if ($row['ignored'])
                                <span class="status-pill status-unknown">Acknowledged</span>
                            @elseif ($row['flagged'])
                                @foreach ($row['flags'] as $flag)
                                    <span class="status-pill status-yellow">{{ $flag === 'default_login' ? 'default login' : 'unapproved email' }}</span>
                                @endforeach
                            @else
                                <span class="text-[var(--color-ink-muted)]">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            @if ($row['ignored'])
                                <form method="POST" action="{{ route('security.admins.unignore', [$row['site_id'], $row['ignored_id']]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs text-[var(--color-ink-muted)] hover:underline">Restore</button>
                                </form>
                            @elseif ($row['flagged'])
                                <form method="POST" action="{{ route('security.admins.ignore', $row['site_id']) }}">
                                    @csrf
                                    <input type="hidden" name="subject" value="{{ $row['subject'] }}">
                                    <button type="submit" class="text-xs text-[var(--color-primary-600)] hover:underline">Acknowledge</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-[var(--color-ink-muted)]">
                            No administrator snapshots yet. Refresh Companion on a site to populate this list.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
