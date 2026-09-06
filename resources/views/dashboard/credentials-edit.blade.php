@extends('layouts.app')

@section('title', 'Edit credentials · ' . $server->name . ' · Clockwork')

@section('content')
    <x-page-header title="Edit SSH credentials"
        :subtitle="$server->display_name . ' · ' . $server->hostname">
        <x-slot:actions>
            <a href="{{ route('servers.show', $server) }}" class="btn-pill-nav text-sm">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Back to {{ $server->display_name }}</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('servers.credentials.update', $server) }}" autocomplete="off" class="card p-6 max-w-2xl">
        @csrf
        @method('PATCH')

        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <label class="block">
                <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">SSH user</span>
                <input type="text" name="ssh_user" value="{{ old('ssh_user', $server->ssh_user) }}"
                       class="mt-1 w-full font-data border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]"
                       required>
                @error('ssh_user') <span class="text-xs text-[var(--color-status-red)] mt-1 block">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">SSH port</span>
                <input type="number" name="ssh_port" value="{{ old('ssh_port', $server->ssh_port) }}" min="1" max="65535"
                       class="mt-1 w-full font-data border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]"
                       required>
                @error('ssh_port') <span class="text-xs text-[var(--color-status-red)] mt-1 block">{{ $message }}</span> @enderror
            </label>
        </div>

        <div class="mt-5">
            <label class="block">
                <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">SSH password</span>
                <input type="password" name="ssh_password"
                       autocomplete="new-password"
                       placeholder="{{ $server->ssh_password ? 'Currently set — leave empty to keep, or type a new value' : 'Enter password' }}"
                       class="mt-1 w-full font-data border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                <span class="text-xs text-[var(--color-ink-soft)] mt-1 block">Stored encrypted (AES-256-GCM, keyed by APP_KEY).</span>
            </label>

            @if ($server->ssh_password)
                <label class="flex items-center gap-2 mt-3 text-sm text-[var(--color-ink-muted)]">
                    <input type="checkbox" name="clear_password" value="1" class="rounded border-[var(--color-border)]">
                    Clear the stored password
                </label>
            @endif
        </div>

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn-primary">
                <i class="fa-solid fa-floppy-disk"></i> Save
            </button>
            <a href="{{ route('servers.show', $server) }}" class="btn-pill-nav">Cancel</a>
        </div>
    </form>

    {{-- Server tags — separate form since the tag set is independent of SSH creds and posts to a different route --}}
    <form method="POST" action="{{ route('servers.tags.sync', $server) }}" id="tags" class="card p-6 max-w-2xl mt-6 scroll-mt-20">
        @csrf
        @method('PATCH')

        <div class="flex items-start justify-between gap-4 mb-3">
            <div>
                <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">Tags</h2>
                <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Used for filtering on the dashboard.</p>
            </div>
            <a href="{{ route('settings.tags.index') }}" class="text-xs text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]">
                Manage tags →
            </a>
        </div>

        @if ($allTags->isEmpty())
            <p class="text-sm text-[var(--color-ink-muted)]">
                No tags defined yet. <a href="{{ route('settings.tags.index') }}" class="underline">Create your first tag</a> to start labelling servers.
            </p>
        @else
            @php $selected = $server->tags->pluck('id')->all(); @endphp
            <div class="flex flex-wrap gap-2">
                @foreach ($allTags as $tag)
                    @php $isOn = in_array($tag->id, $selected, true); @endphp
                    <label class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full border cursor-pointer text-sm transition-colors"
                           style="border-color: {{ $isOn ? $tag->color : 'var(--color-border-light)' }}; background: {{ $isOn ? $tag->color . '15' : 'transparent' }};">
                        <input type="checkbox" name="tags[]" value="{{ $tag->id }}" {{ $isOn ? 'checked' : '' }}
                               class="rounded border-[var(--color-border)]"
                               style="accent-color: {{ $tag->color }}">
                        <span class="inline-flex w-2 h-2 rounded-full" style="background: {{ $tag->color }}"></span>
                        <span class="font-medium" style="color: {{ $isOn ? $tag->color : 'var(--color-ink-strong)' }}">{{ $tag->name }}</span>
                    </label>
                @endforeach
            </div>
            <div class="mt-5">
                <button type="submit" class="btn-primary">
                    <i class="fa-solid fa-floppy-disk"></i> Save tags
                </button>
            </div>
        @endif
    </form>
@endsection
