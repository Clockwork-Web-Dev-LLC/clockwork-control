@extends('layouts.app')

@section('title', 'Team · Clockwork')

@section('content')
    <x-page-header title="Team"
        subtitle="Allowlist of Clockwork employees who can sign in via Google. Adding an email here is what grants access — until then, Google sign-in is rejected with a 'not on the team' message." />

    @if (session('status'))
        <div class="card p-4 mb-6 status-green flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i> {{ session('status') }}
        </div>
    @endif
    @if (session('error'))
        <div class="card p-4 mb-6 status-red flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation"></i> {{ session('error') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="card p-4 mb-6 status-red text-sm">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Roll-up metric tiles --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6 max-w-3xl">
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Active Teammates</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">{{ $users->whereNull('revoked_at')->count() }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Authorized to log in</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Total Accounts</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">{{ $users->count() }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Configured allowlist</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Revoked</div>
            <div class="text-2xl font-display {{ $users->whereNotNull('revoked_at')->count() > 0 ? 'text-[var(--color-status-yellow)]' : 'text-[var(--color-ink-strong)]' }} font-data">
                {{ $users->whereNotNull('revoked_at')->count() }}
            </div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Access disabled</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Recent (30d)</div>
            <div class="text-2xl font-display text-[var(--color-status-green)] font-data">
                {{ $users->filter(fn ($u) => $u->last_login_at && $u->last_login_at->gt(now()->subDays(30)))->count() }}
            </div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Active logins</div>
        </div>
    </div>

    <form method="POST" action="{{ route('settings.users.store') }}" class="card p-6 max-w-3xl mb-6">
        @csrf
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-3">Add a teammate</h2>
        <p class="text-sm text-[var(--color-ink-muted)] mb-4">
            Use the email of the Google account they'll sign in with. Name is optional — Google overwrites it on first login.
        </p>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <input type="email" name="email" required placeholder="person@example.com"
                   class="md:col-span-1 px-3 py-2 rounded-md border border-[var(--color-border)] text-sm font-data focus:outline-none focus:border-[var(--color-brand)]">
            <input type="text" name="name" placeholder="Display name (optional)"
                   class="md:col-span-1 px-3 py-2 rounded-md border border-[var(--color-border)] text-sm focus:outline-none focus:border-[var(--color-brand)]">
            <button type="submit"
                    class="md:col-span-1 px-4 py-2 rounded-md bg-[var(--color-primary-600)] text-white text-sm font-medium hover:bg-[var(--color-primary-700)]">
                Add to team
            </button>
        </div>
    </form>

    <div class="card max-w-3xl overflow-hidden">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] px-6 pt-6 pb-2">
            Allowlist
            <span class="text-xs text-[var(--color-ink-muted)] font-normal">·
                {{ $users->whereNull('revoked_at')->count() }} active /
                {{ $users->count() }} total
            </span>
        </h2>
        <table class="w-full text-sm">
            <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)] text-xs uppercase tracking-wide">
                <tr>
                    <th class="text-left px-6 py-2">Email</th>
                    <th class="text-left px-6 py-2">Name</th>
                    <th class="text-left px-6 py-2">Last login</th>
                    <th class="text-left px-6 py-2">Status</th>
                    <th class="text-right px-6 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $u)
                    <tr class="border-t border-[var(--color-border-light)] {{ $u->revoked_at ? 'opacity-60' : '' }}">
                        <td class="px-6 py-3 font-data text-[var(--color-ink-strong)]">{{ $u->email }}</td>
                        <td class="px-6 py-3">{{ $u->name }}</td>
                        <td class="px-6 py-3 text-[var(--color-ink-muted)] text-xs font-data">
                            {{ $u->last_login_at ? $u->last_login_at->diffForHumans() : 'never' }}
                        </td>
                        <td class="px-6 py-3">
                            @if ($u->revoked_at)
                                <span class="status-pill status-red text-xs"><span class="status-dot"></span> Revoked</span>
                            @else
                                <span class="status-pill status-green text-xs"><span class="status-dot"></span> Active</span>
                            @endif
                        </td>
                        <td class="px-6 py-3 text-right">
                            @if ($u->revoked_at)
                                <form method="POST" action="{{ route('settings.users.restore', $u) }}" class="inline">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="text-xs text-[var(--color-primary-600)] hover:underline">
                                        Restore
                                    </button>
                                </form>
                            @elseif (auth()->id() !== $u->id)
                                <form method="POST" action="{{ route('settings.users.revoke', $u) }}" class="inline"
                                      onsubmit="return confirm('Revoke {{ $u->email }}? They will be denied at next sign-in attempt.');">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="text-xs text-[var(--color-status-red)] hover:underline">
                                        Revoke
                                    </button>
                                </form>
                            @else
                                <span class="text-xs text-[var(--color-ink-muted)] italic">that's you</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center text-[var(--color-ink-muted)]">
                            No users on the allowlist yet. Add one above, or run<br>
                            <code class="font-data text-xs">php artisan clockwork:add-user you@example.com</code>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
