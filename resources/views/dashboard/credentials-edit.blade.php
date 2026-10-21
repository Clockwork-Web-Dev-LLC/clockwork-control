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

        <div class="mt-5" x-data="{
            show: false,
            copied: false,
            copy() {
                const val = this.$refs.pwdInput.value;
                if (!val) return;
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(val).then(() => {
                        this.copied = true;
                        setTimeout(() => this.copied = false, 2000);
                    });
                } else {
                    this.$refs.pwdInput.select();
                    document.execCommand('copy');
                    this.copied = true;
                    setTimeout(() => this.copied = false, 2000);
                }
            }
        }">
            <label class="block">
                <div class="flex items-center justify-between">
                    <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">SSH password</span>
                    @if ($server->ssh_password)
                        <span class="text-[11px] text-emerald-600 dark:text-emerald-400 font-medium flex items-center gap-1">
                            <i class="fa-solid fa-shield-check text-[10px]"></i> Password stored & encrypted
                        </span>
                    @endif
                </div>
                <div class="relative flex items-center mt-1">
                    <input :type="show ? 'text' : 'password'"
                           x-ref="pwdInput"
                           name="ssh_password"
                           value="{{ old('ssh_password', $server->ssh_password ?? '') }}"
                           autocomplete="new-password"
                           placeholder="{{ $server->ssh_password ? 'Currently set — leave as-is to keep, or type a new value' : 'Enter password' }}"
                           class="w-full font-data border border-[var(--color-border)] rounded-md pl-3 pr-28 py-2 bg-[var(--color-surface)] text-[var(--color-ink-strong)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">

                    <div class="absolute right-1.5 flex items-center bg-[var(--color-surface)] rounded border border-[var(--color-border)] shadow-xs divide-x divide-[var(--color-border)]">
                        <button type="button"
                                @click="show = !show"
                                class="px-2.5 py-1 text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-hover)] transition-colors inline-flex items-center gap-1 cursor-pointer"
                                :title="show ? 'Hide password' : 'View password in plain text'">
                            <i class="fa-solid w-3.5 text-center" :class="show ? 'fa-eye-slash' : 'fa-eye'"></i>
                            <span class="text-[11px] font-medium" x-text="show ? 'Hide' : 'View'">View</span>
                        </button>
                        <button type="button"
                                @click="copy()"
                                class="px-2.5 py-1 text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-hover)] transition-colors inline-flex items-center gap-1 cursor-pointer"
                                :title="copied ? 'Copied to clipboard!' : 'Copy password to clipboard'">
                            <i class="fa-solid w-3.5 text-center" :class="copied ? 'fa-check text-emerald-500' : 'fa-copy'"></i>
                            <span class="text-[11px] font-medium" :class="copied ? 'text-emerald-500 font-semibold' : ''" x-text="copied ? 'Copied!' : 'Copy'">Copy</span>
                        </button>
                    </div>
                </div>
                <span class="text-xs text-[var(--color-ink-soft)] mt-1 block">Stored encrypted (AES-256-GCM, keyed by APP_KEY). Click <strong>View</strong> to reveal or <strong>Copy</strong> to copy to your clipboard.</span>
            </label>

            @if ($server->ssh_password)
                <div class="mt-2 text-xs text-[var(--color-ink-muted)] flex flex-wrap items-center gap-2">
                    <span>Password age:</span>
                    @if ($server->ssh_password_updated_at)
                        <span class="font-medium text-[var(--color-ink-strong)]">{{ $server->ssh_password_updated_at->diffForHumans() }} ({{ $server->ssh_password_updated_at->format('M j, Y') }})</span>
                        @if ($server->ssh_password_updated_at->diffInDays(now()) >= 90)
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-amber-500/15 text-amber-600 dark:text-amber-400 border border-amber-500/30">
                                <i class="fa-solid fa-triangle-exclamation mr-1"></i> Over 90 days old — rotation recommended
                            </span>
                        @endif
                    @else
                        <span class="italic text-[var(--color-ink-soft)]">Update date not recorded</span>
                    @endif
                </div>

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
