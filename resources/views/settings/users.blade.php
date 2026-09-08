@extends('layouts.app')

@section('title', 'Team · Clockwork')

@section('content')
    @include('settings._tabs')

    <x-page-header title="Team"
        subtitle="Allowlist of operators authorized to access Clockwork. Teammates can log in with their local password, or via configured Single Sign-On providers (Google, GitHub, Microsoft)." />

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
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6 max-w-4xl">
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
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Password Ready</div>
            <div class="text-2xl font-display text-[var(--color-brand)] font-data">{{ $users->filter(fn($u) => !empty($u->password))->count() }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Local login enabled</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Recent (30d)</div>
            <div class="text-2xl font-display text-[var(--color-status-green)] font-data">
                {{ $users->filter(fn ($u) => $u->last_login_at && $u->last_login_at->gt(now()->subDays(30)))->count() }}
            </div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Active logins</div>
        </div>
    </div>

    {{-- Add teammate form --}}
    <form method="POST" action="{{ route('settings.users.store') }}" class="card p-6 max-w-4xl mb-6">
        @csrf
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-1.5">Add a teammate</h2>
        <p class="text-sm text-[var(--color-ink-muted)] mb-4">
            Add an operator to the allowlist. You can set a local password immediately, or leave it blank to rely on SSO.
        </p>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
            <div>
                <label class="block text-xs font-semibold text-[var(--color-ink-muted)] uppercase tracking-wider mb-1">Email</label>
                <input type="email" name="email" required placeholder="teammate@agency.com"
                       class="w-full px-3 py-2 rounded-md border border-[var(--color-border)] text-sm font-data focus:outline-none focus:border-[var(--color-brand)]">
            </div>
            <div>
                <label class="block text-xs font-semibold text-[var(--color-ink-muted)] uppercase tracking-wider mb-1">Name (optional)</label>
                <input type="text" name="name" placeholder="Display name"
                       class="w-full px-3 py-2 rounded-md border border-[var(--color-border)] text-sm focus:outline-none focus:border-[var(--color-brand)]">
            </div>
            <div>
                <label class="block text-xs font-semibold text-[var(--color-ink-muted)] uppercase tracking-wider mb-1">Password (optional, min 8)</label>
                <input type="password" name="password" placeholder="••••••••••••"
                       class="w-full px-3 py-2 rounded-md border border-[var(--color-border)] text-sm font-mono focus:outline-none focus:border-[var(--color-brand)]">
            </div>
        </div>
        <div class="flex justify-end">
            <button type="submit"
                    class="px-4 py-2 rounded-md bg-[var(--color-primary-600)] text-white text-sm font-medium hover:bg-[var(--color-primary-700)] shadow-sm cursor-pointer">
                <i class="fa-solid fa-user-plus mr-1.5 text-xs"></i> Add to allowlist
            </button>
        </div>
    </form>

    {{-- Allowlist table with password reset modal --}}
    <div class="card max-w-4xl overflow-hidden" x-data="{ passwordModalUser: null, passwordModalEmail: '' }">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] px-6 pt-6 pb-2">
            Allowlist
            <span class="text-xs text-[var(--color-ink-muted)] font-normal">·
                {{ $users->whereNull('revoked_at')->count() }} active /
                {{ $users->count() }} total
            </span>
        </h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)] text-xs uppercase tracking-wide">
                    <tr>
                        <th class="text-left px-6 py-2.5">Operator</th>
                        <th class="text-left px-6 py-2.5">Authentication</th>
                        <th class="text-left px-6 py-2.5">Last login</th>
                        <th class="text-left px-6 py-2.5">Status</th>
                        <th class="text-right px-6 py-2.5">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $u)
                        <tr class="border-t border-[var(--color-border-light)] {{ $u->revoked_at ? 'opacity-60' : '' }}">
                            <td class="px-6 py-3.5">
                                <div class="font-medium text-[var(--color-ink-strong)]">{{ $u->name }}</div>
                                <div class="font-data text-xs text-[var(--color-ink-muted)]">{{ $u->email }}</div>
                            </td>
                            <td class="px-6 py-3.5">
                                @if (!empty($u->password))
                                    <span class="inline-flex items-center gap-1.5 text-xs px-2 py-0.5 rounded-full bg-[var(--color-brand)]/10 text-[var(--color-brand)] font-medium">
                                        <i class="fa-solid fa-key text-[10px]"></i> Password + SSO
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 text-xs px-2 py-0.5 rounded-full bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] border border-[var(--color-border)] font-medium">
                                        <i class="fa-solid fa-fingerprint text-[10px]"></i> SSO only
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-3.5 text-[var(--color-ink-muted)] text-xs font-data">
                                {{ $u->last_login_at ? $u->last_login_at->diffForHumans() : 'never' }}
                            </td>
                            <td class="px-6 py-3.5">
                                @if ($u->revoked_at)
                                    <span class="status-pill status-red text-xs"><span class="status-dot"></span> Revoked</span>
                                @else
                                    <span class="status-pill status-green text-xs"><span class="status-dot"></span> Active</span>
                                @endif
                            </td>
                            <td class="px-6 py-3.5 text-right whitespace-nowrap">
                                <div class="inline-flex items-center gap-3">
                                    <button type="button"
                                            @click="passwordModalUser = {{ $u->id }}; passwordModalEmail = '{{ addslashes($u->email) }}'"
                                            class="text-xs text-[var(--color-brand)] hover:underline flex items-center gap-1 cursor-pointer">
                                        <i class="fa-solid fa-key text-[10px]"></i> Set Password
                                    </button>

                                    @if ($u->revoked_at)
                                        <form method="POST" action="{{ route('settings.users.restore', $u) }}" class="inline">
                                            @csrf @method('PATCH')
                                            <button type="submit" class="text-xs text-[var(--color-primary-600)] hover:underline cursor-pointer">
                                                Restore
                                            </button>
                                        </form>
                                    @elseif (auth()->id() !== $u->id)
                                        <form method="POST" action="{{ route('settings.users.revoke', $u) }}" class="inline"
                                              onsubmit="return confirm('Revoke {{ $u->email }}? They will be denied at next sign-in attempt.');">
                                            @csrf @method('PATCH')
                                            <button type="submit" class="text-xs text-[var(--color-status-red)] hover:underline cursor-pointer">
                                                Revoke
                                            </button>
                                        </form>
                                    @else
                                        <span class="text-xs text-[var(--color-ink-muted)] italic">you</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-[var(--color-ink-muted)]">
                                No users on the allowlist yet. Add one above, or run<br>
                                <code class="font-data text-xs">php artisan clockwork:add-user you@example.com --password=secret</code>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Set Password Modal --}}
        <div x-show="passwordModalUser !== null"
             x-cloak
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
             @keydown.escape.window="passwordModalUser = null">
            <div class="card p-6 max-w-md w-full shadow-xl" @click.outside="passwordModalUser = null">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-semibold text-[var(--color-ink-strong)] flex items-center gap-2">
                        <i class="fa-solid fa-key text-[var(--color-brand)]"></i>
                        Set Password
                    </h3>
                    <button type="button" @click="passwordModalUser = null" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <p class="text-xs text-[var(--color-ink-muted)] mb-4">
                    Update local login password for <strong class="font-mono text-[var(--color-ink-strong)]" x-text="passwordModalEmail"></strong>.
                </p>
                <form :action="'{{ url('/settings/users') }}/' + passwordModalUser + '/password'" method="POST" class="space-y-4">
                    @csrf
                    @method('PATCH')
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink-muted)] uppercase tracking-wider mb-1">New Password (min 8)</label>
                        <input type="password" name="password" required minlength="8" placeholder="••••••••••••"
                               class="w-full px-3 py-2 rounded-md border border-[var(--color-border)] text-sm font-mono focus:outline-none focus:border-[var(--color-brand)]">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[var(--color-ink-muted)] uppercase tracking-wider mb-1">Confirm Password</label>
                        <input type="password" name="password_confirmation" required minlength="8" placeholder="••••••••••••"
                               class="w-full px-3 py-2 rounded-md border border-[var(--color-border)] text-sm font-mono focus:outline-none focus:border-[var(--color-brand)]">
                    </div>
                    <div class="flex items-center justify-end gap-2.5 pt-2">
                        <button type="button" @click="passwordModalUser = null"
                                class="px-3.5 py-2 text-xs font-medium text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]">
                            Cancel
                        </button>
                        <button type="submit"
                                class="px-4 py-2 rounded-md bg-[var(--color-brand)] text-white text-xs font-medium hover:opacity-90 transition-opacity">
                            Update Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
