@extends('layouts.app')

@section('title', 'Mattermost notifications · Clockwork')

@section('content')
    <x-page-header title="Mattermost notifications"
        subtitle="Per-event opt-out for chat notifications. Disabled events still log to the database and the Laravel log — only the Mattermost post is suppressed. Toggles save immediately." />

    {{-- Integration health pill — useful so the operator knows whether unchecking
         a box actually does anything (no-op if there's no webhook URL set). --}}
    <div class="card p-4 mb-4 flex items-start gap-3">
        @if ($webhookConfigured)
            <i class="fa-solid fa-circle-check text-[var(--color-status-green)] mt-0.5"></i>
            <div class="text-sm">
                <div class="font-medium text-[var(--color-ink-strong)]">Mattermost integration is configured.</div>
                <div class="text-[var(--color-ink-muted)]">
                    Toggles below take effect immediately on the next fired event.
                </div>
            </div>
        @else
            <i class="fa-solid fa-triangle-exclamation text-[var(--color-status-yellow)] mt-0.5"></i>
            <div class="text-sm">
                <div class="font-medium text-[var(--color-ink-strong)]">No webhook URL is set.</div>
                <div class="text-[var(--color-ink-muted)]">
                    Mattermost notifications are enabled but <code>CLOCKWORK_MATTERMOST_WEBHOOK_URL</code> is empty in <code>.env</code> — nothing will post until it's set.
                </div>
            </div>
        @endif
    </div>

    <div class="card overflow-hidden mb-4">
        <table class="w-full text-sm">
            <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                <tr>
                    <th class="px-5 py-3 text-left">Event</th>
                    <th class="px-5 py-3 text-left">What it posts about</th>
                    <th class="px-5 py-3 text-center">Notify</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--color-border-light)]">
                @foreach ($events as $key => $meta)
                    <tr>
                        <td class="px-5 py-3 align-top">
                            <div class="font-medium text-[var(--color-ink-strong)]">{{ $meta['label'] }}</div>
                            <div class="text-[10px] font-data text-[var(--color-ink-soft)] mt-0.5">{{ $key }}</div>
                        </td>
                        <td class="px-5 py-3 align-top text-[var(--color-ink-muted)]">
                            {{ $meta['description'] }}
                        </td>
                        <td class="px-5 py-3 align-top text-center">
                            {{-- Auto-saving toggle: click posts immediately via fetch, no
                                 separate Save button. The <noscript> form is a fallback if
                                 Alpine fails to boot. --}}
                            <div x-data="{
                                    on: {{ $meta['enabled'] ? 'true' : 'false' }},
                                    busy: false,
                                    async toggle() {
                                        if (this.busy) return;
                                        this.busy = true;
                                        const targetOn = ! this.on;
                                        try {
                                            const res = await fetch('{{ route('settings.mattermost.events.update', $key) }}', {
                                                method: 'PATCH',
                                                headers: {
                                                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                                    'Accept': 'application/json',
                                                    'Content-Type': 'application/x-www-form-urlencoded',
                                                },
                                                body: 'enabled=' + (targetOn ? '1' : '0'),
                                            });
                                            const data = await res.json();
                                            if (data.ok) {
                                                this.on = data.enabled;
                                            }
                                        } catch (e) { /* swallow; visual stays */ }
                                        finally { this.busy = false; }
                                    },
                                 }"
                                 style="display: inline-block;">
                                <button type="button"
                                        role="switch"
                                        :aria-checked="on ? 'true' : 'false'"
                                        aria-label="Toggle {{ $meta['label'] }} Mattermost notifications"
                                        @click="toggle()"
                                        :disabled="busy"
                                        class="cw-switch"
                                        :class="{ 'cw-switch--on': on, 'cw-switch--busy': busy }">
                                    <span class="cw-switch__knob"></span>
                                </button>
                            </div>
                            <noscript>
                                <form method="POST" action="{{ route('settings.mattermost.events.update', $key) }}" class="inline">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="enabled" value="{{ $meta['enabled'] ? '0' : '1' }}">
                                    <button type="submit" class="btn-pill-nav text-xs">
                                        {{ $meta['enabled'] ? 'Turn OFF' : 'Turn ON' }}
                                    </button>
                                </form>
                            </noscript>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
