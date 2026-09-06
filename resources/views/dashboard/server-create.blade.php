@extends('layouts.app')

@section('title', 'Add server · Clockwork')

@section('content')
    <x-page-header title="Add a server"
        subtitle="For Hetzner, hand-rolled, or any host not in your SpinupWP fleet. SpinupWP servers sync automatically via scheduled import.">
        <x-slot:actions>
            <a href="{{ route('dashboard') }}" class="btn-pill-nav text-sm">
                <i class="fa-solid fa-arrow-left"></i>
                <span>All servers</span>
            </a>
            <a href="{{ route('servers.credentials.feed') }}" class="btn-pill-nav text-sm">
                <i class="fa-solid fa-paste text-[var(--color-ink-muted)]"></i>
                <span>Bulk import feed</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('servers.store') }}" autocomplete="off" class="card p-6 max-w-2xl">
        @csrf

        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <label class="block">
                <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">Name <span class="text-[var(--color-status-red)]">*</span></span>
                <input type="text" name="name" value="{{ old('name') }}" required
                       placeholder="e.g. server01.example.com"
                       class="mt-1 w-full font-data border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                @error('name') <span class="text-xs text-[var(--color-status-red)] mt-1 block">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">Hostname or IP <span class="text-[var(--color-status-red)]">*</span></span>
                <input type="text" name="hostname" value="{{ old('hostname') }}" required
                       placeholder="e.g. 203.0.113.10"
                       class="mt-1 w-full font-data border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                @error('hostname') <span class="text-xs text-[var(--color-status-red)] mt-1 block">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">SSH user</span>
                <input type="text" name="ssh_user" value="{{ old('ssh_user', config('clockwork.ssh.default_user')) }}"
                       class="mt-1 w-full font-data border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
            </label>

            <label class="block">
                <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">SSH port</span>
                <input type="number" name="ssh_port" value="{{ old('ssh_port', 22) }}" min="1" max="65535"
                       class="mt-1 w-full font-data border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
            </label>
        </div>

        <div class="mt-5">
            <label class="block">
                <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">SSH password</span>
                <input type="password" name="ssh_password"
                       autocomplete="new-password"
                       placeholder="Optional — can be added later"
                       class="mt-1 w-full font-data border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
            </label>
        </div>

        <div class="mt-5 pt-5 border-t border-[var(--color-border-light)]">
            <label class="flex items-center gap-2 text-sm text-[var(--color-ink-muted)]">
                <input type="checkbox" name="is_ignored" value="1" {{ old('is_ignored') ? 'checked' : '' }} class="rounded border-[var(--color-border)]">
                Mark as ignored — don't poll, exclude from stats
            </label>

            <label class="block mt-3">
                <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">Ignore reason</span>
                <input type="text" name="ignore_reason" value="{{ old('ignore_reason') }}"
                       placeholder="e.g. Behind firewall, customer-managed"
                       class="mt-1 w-full font-data border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
            </label>
        </div>

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn-primary">
                <i class="fa-solid fa-plus"></i> Create server
            </button>
            <a href="{{ route('dashboard') }}" class="btn-pill-nav">Cancel</a>
        </div>
    </form>
@endsection
