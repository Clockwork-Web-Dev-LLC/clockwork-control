@extends('layouts.app')

@section('title', 'SSH credentials · Clockwork')

@section('content')
    <x-page-header title="SSH credentials"
        subtitle="Manage per-server SSH passwords for automation and update tasks. Default user: {{ config('clockwork.ssh.default_user') }}. Existing values are encrypted at rest.">
        <x-slot:actions>
            <a href="{{ route('dashboard') }}" class="btn-pill-nav text-sm">
                <i class="fa-solid fa-arrow-left"></i>
                <span>All servers</span>
            </a>
            <a href="{{ route('servers.credentials.feed') }}" class="btn-pill-nav text-sm">
                <i class="fa-solid fa-paste text-[var(--color-ink-muted)]"></i>
                <span>Paste from feed</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    @include('settings._tabs')

    @if (session('status'))
        <div class="card p-4 mb-6 status-green flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i>
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('servers.credentials.bulkUpdate') }}" autocomplete="off">
        @csrf

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <th class="text-left px-5 py-3">Server</th>
                        <th class="text-left px-5 py-3">Hostname</th>
                        <th class="text-left px-5 py-3">User</th>
                        <th class="text-left px-5 py-3">Status</th>
                        <th class="text-left px-5 py-3 w-[26rem]">SSH password</th>
                        <th class="px-5 py-3 w-24"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($servers as $server)
                        <tr>
                            <td class="px-5 py-3">
                                <a href="{{ route('servers.show', $server) }}" class="font-medium text-[var(--color-ink-strong)] hover:underline" title="{{ $server->name }}">{{ $server->display_name }}</a>
                            </td>
                            <td class="px-5 py-3 font-data text-[var(--color-ink-muted)]">{{ $server->hostname }}<span class="text-[var(--color-ink-soft)]">:{{ $server->ssh_port }}</span></td>
                            <td class="px-5 py-3 font-data text-[var(--color-ink-muted)]">{{ $server->ssh_user }}</td>
                            <td class="px-5 py-3">
                                @if ($server->ssh_password)
                                    <span class="status-pill status-green">
                                        <span class="status-dot"></span> Set
                                    </span>
                                @else
                                    <span class="status-pill status-yellow">
                                        <span class="status-dot"></span> Missing
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                <input type="password"
                                       name="passwords[{{ $server->id }}]"
                                       autocomplete="new-password"
                                       placeholder="{{ $server->ssh_password ? '•••• (leave empty to keep)' : 'Enter password' }}"
                                       class="w-full font-data text-sm border border-[var(--color-border)] rounded-md px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                            </td>
                            <td class="px-5 py-3 text-right">
                                <button type="submit"
                                        form="ignore-{{ $server->id }}"
                                        class="text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-status-red)] inline-flex items-center gap-1.5"
                                        title="Stop tracking this server">
                                    <i class="fa-solid fa-eye-slash"></i>
                                    Ignore
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn-primary">
                <i class="fa-solid fa-floppy-disk"></i> Save credentials
            </button>
            <span class="text-xs text-[var(--color-ink-soft)]">{{ $servers->count() }} active server(s) · ignored servers are not shown here</span>
        </div>
    </form>

    {{-- Per-row "Ignore" forms, kept outside the bulk-update form. --}}
    @foreach ($servers as $server)
        <form id="ignore-{{ $server->id }}" method="POST" action="{{ route('servers.toggleIgnore', $server) }}" class="hidden">
            @csrf
            <input type="hidden" name="reason" value="No SSH password — manually ignored from credentials page">
            <input type="hidden" name="return_to" value="{{ route('servers.credentials.bulk') }}">
        </form>
    @endforeach
@endsection
