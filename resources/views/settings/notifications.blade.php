@extends('layouts.app')

@section('title', 'Notification settings · Clockwork')

@section('content')
    @include('settings._tabs')

    <x-page-header title="SMS notifications"
        subtitle="Site-down + recovery alerts via Twilio. Recipients listed here get paged when a care-plan site goes down. Off-windows opt a recipient out for a recurring time period (e.g. a recurring religious observance)." />

    @if (session('status'))
        <div class="card p-4 mb-4 status-green flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i> {{ session('status') }}
        </div>
    @endif
    @if (session('status_error'))
        <div class="card p-4 mb-4 status-red flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation"></i> {{ session('status_error') }}
        </div>
    @endif

    {{-- Roll-up metric tiles --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6 max-w-5xl">
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">On-Call Now</div>
            <div class="text-2xl font-display {{ $onCall->isEmpty() ? 'text-[var(--color-status-yellow)]' : 'text-[var(--color-status-green)]' }} font-data">
                {{ $onCall->count() }}
            </div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5 truncate">
                {{ $onCall->isEmpty() ? 'Nobody — falls back to chat' : $onCall->pluck('name')->implode(', ') }}
            </div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Twilio SMS</div>
            <div class="text-2xl font-display {{ $twilioConfigured ? 'text-[var(--color-status-green)]' : 'text-[var(--color-status-red)]' }} font-data">
                {{ $twilioConfigured ? 'Active' : 'Unset' }}
            </div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5 font-data truncate">
                {{ $twilioConfigured ? $twilioFrom : 'Missing credentials' }}
            </div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Recipients</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">
                {{ $recipients->count() }}
            </div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Configured engineers</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Active Enabled</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">
                {{ $recipients->where('enabled', true)->count() }}
            </div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Receiving alerts</div>
        </div>
    </div>

    @unless ($twilioConfigured)
        <div class="card p-4 mb-5 border-l-4 border-[var(--color-status-yellow)] bg-[var(--color-status-yellow-bg)] text-xs flex items-start gap-3 max-w-5xl">
            <i class="fa-solid fa-triangle-exclamation text-[var(--color-status-yellow)] text-sm mt-0.5 flex-shrink-0"></i>
            <div class="leading-relaxed text-[var(--color-ink)]">
                <strong>Twilio not configured:</strong> Set <code class="font-data text-xs">TWILIO_ACCOUNT_SID</code>, <code class="font-data text-xs">TWILIO_AUTH_TOKEN</code>, <code class="font-data text-xs">TWILIO_FROM_NUMBER</code>, and <code class="font-data text-xs">TWILIO_ENABLED=true</code> in your <code class="font-data text-xs">.env</code> file. US business SMS also requires Twilio's A2P 10DLC brand + campaign registration.
            </div>
        </div>
    @endunless

    {{-- Recipients --}}
    <div class="card overflow-hidden mb-5 max-w-5xl">
        <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-mobile-screen-button text-[var(--color-ink-muted)] mr-1"></i>
                Recipients
            </h2>
            <span class="text-xs text-[var(--color-ink-soft)]">{{ $recipients->count() }} configured</span>
        </div>

        @if ($recipients->isEmpty())
            <div class="p-6 text-sm text-[var(--color-ink-muted)] text-center">
                No recipients yet. Add one below to start receiving SMS alerts.
            </div>
        @else
            <div class="divide-y divide-[var(--color-border-light)]">
                @foreach ($recipients as $recipient)
                    <div class="p-5">
                        @php
                            $isOnCall = $onCall->contains(fn ($r) => $r->id === $recipient->id);
                        @endphp
                        <div class="flex items-start justify-between gap-3 flex-wrap mb-3">
                            <div class="min-w-[18rem]">
                                <div class="font-display text-base text-[var(--color-ink-strong)] flex items-center gap-2">
                                    {{ $recipient->name }}
                                    @if ($isOnCall)
                                        <span class="status-pill status-green text-[10px]">
                                            <span class="status-dot"></span> on-call now
                                        </span>
                                    @elseif ($recipient->enabled)
                                        <span class="status-pill status-yellow text-[10px]">
                                            <span class="status-dot"></span> off right now
                                        </span>
                                    @else
                                        <span class="status-pill status-unknown text-[10px]">
                                            <span class="status-dot"></span> disabled
                                        </span>
                                    @endif
                                </div>
                                <div class="text-sm font-data text-[var(--color-ink-muted)] mt-1">{{ $recipient->phone }}</div>
                                @if ($recipient->email_fallback)
                                    <div class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                                        Fallback email: <span class="font-data">{{ $recipient->email_fallback }}</span>
                                    </div>
                                @endif
                            </div>
                            <div class="flex items-center gap-2 flex-wrap">
                                @if ($twilioConfigured && $recipient->enabled)
                                    <form method="POST" action="{{ route('settings.notifications.recipients.test', $recipient) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="btn-pill-nav text-xs">
                                            <i class="fa-solid fa-paper-plane"></i> Test SMS
                                        </button>
                                    </form>
                                @endif
                                <details class="inline-block">
                                    <summary class="btn-pill-nav text-xs cursor-pointer">
                                        <i class="fa-solid fa-pen-to-square"></i> Edit
                                    </summary>
                                    <form method="POST" action="{{ route('settings.notifications.recipients.update', $recipient) }}"
                                          class="mt-2 p-3 border border-[var(--color-border-light)] rounded space-y-2">
                                        @csrf
                                        @method('PATCH')
                                        <div class="grid grid-cols-2 gap-2 text-xs">
                                            <label class="block">
                                                <span class="text-[var(--color-ink-soft)]">Name</span>
                                                <input type="text" name="name" value="{{ $recipient->name }}" class="text-xs px-2 py-1 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full" required>
                                            </label>
                                            <label class="block">
                                                <span class="text-[var(--color-ink-soft)]">Phone (E.164)</span>
                                                <input type="text" name="phone" value="{{ $recipient->phone }}" class="text-xs px-2 py-1 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full font-data" required pattern="\+\d{8,15}">
                                            </label>
                                            <label class="block col-span-2">
                                                <span class="text-[var(--color-ink-soft)]">Email fallback (optional)</span>
                                                <input type="email" name="email_fallback" value="{{ $recipient->email_fallback }}" class="text-xs px-2 py-1 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full">
                                            </label>
                                            <label class="block col-span-2">
                                                <input type="checkbox" name="enabled" value="1" @checked($recipient->enabled)>
                                                <span class="text-[var(--color-ink-strong)]">Enabled (receives SMS)</span>
                                            </label>
                                        </div>
                                        <button type="submit" class="btn-pill-nav text-xs">Save</button>
                                    </form>
                                </details>
                                <form method="POST" action="{{ route('settings.notifications.recipients.destroy', $recipient) }}" class="inline"
                                      onsubmit="return confirm('Remove {{ $recipient->name }} and all their off-windows?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-pill-nav text-xs text-[var(--color-status-red)]" title="Remove this recipient">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>

                        {{-- Off-windows --}}
                        <div class="ml-2 pl-4 border-l-2 border-[var(--color-border-light)]">
                            <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-2">Off-windows</div>
                            @if ($recipient->offWindows->isEmpty())
                                <div class="text-xs text-[var(--color-ink-soft)] italic mb-2">None — on-call 24/7.</div>
                            @else
                                <ul class="text-xs space-y-1.5 mb-3">
                                    @foreach ($recipient->offWindows as $win)
                                        @php
                                            $dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                                            $startStr = $dayNames[$win->start_dow].' '.substr($win->start_time, 0, 5);
                                            $endStr   = $dayNames[$win->end_dow].' '.substr($win->end_time, 0, 5);
                                        @endphp
                                        <li class="flex items-center gap-2 flex-wrap">
                                            <span class="font-data text-[var(--color-ink-strong)]">{{ $win->label }}</span>
                                            <span class="text-[var(--color-ink-muted)]">{{ $startStr }} → {{ $endStr }} {{ $win->timezone }}</span>
                                            @if (! $win->enabled)
                                                <span class="status-pill status-unknown text-[10px]">disabled</span>
                                            @endif
                                            <form method="POST" action="{{ route('settings.notifications.windows.destroy', $win) }}" class="inline ml-auto"
                                                  onsubmit="return confirm('Remove off-window {{ $win->label }}?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-[var(--color-ink-soft)] hover:text-[var(--color-status-red)] text-xs">
                                                    <i class="fa-solid fa-xmark"></i>
                                                </button>
                                            </form>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            <details>
                                <summary class="text-xs text-[var(--color-primary-600)] hover:underline cursor-pointer">+ Add off-window</summary>
                                <form method="POST" action="{{ route('settings.notifications.windows.store', $recipient) }}"
                                      class="mt-2 p-3 border border-[var(--color-border-light)] rounded space-y-2">
                                    @csrf
                                    <div class="grid grid-cols-2 gap-2 text-xs">
                                        <label class="block col-span-2">
                                            <span class="text-[var(--color-ink-soft)]">Label</span>
                                            <input type="text" name="label" placeholder="Shabbat / Vacation Aug 5–10 / etc." class="text-xs px-2 py-1 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full" required>
                                        </label>
                                        <label class="block">
                                            <span class="text-[var(--color-ink-soft)]">Start day</span>
                                            <select name="start_dow" class="text-xs px-2 py-1 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full">
                                                <option value="0">Sunday</option>
                                                <option value="1">Monday</option>
                                                <option value="2">Tuesday</option>
                                                <option value="3">Wednesday</option>
                                                <option value="4">Thursday</option>
                                                <option value="5" selected>Friday</option>
                                                <option value="6">Saturday</option>
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-[var(--color-ink-soft)]">Start time</span>
                                            <input type="time" name="start_time" value="17:00" class="text-xs px-2 py-1 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full" required>
                                        </label>
                                        <label class="block">
                                            <span class="text-[var(--color-ink-soft)]">End day</span>
                                            <select name="end_dow" class="text-xs px-2 py-1 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full">
                                                <option value="0">Sunday</option>
                                                <option value="1">Monday</option>
                                                <option value="2">Tuesday</option>
                                                <option value="3">Wednesday</option>
                                                <option value="4">Thursday</option>
                                                <option value="5">Friday</option>
                                                <option value="6" selected>Saturday</option>
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-[var(--color-ink-soft)]">End time</span>
                                            <input type="time" name="end_time" value="20:00" class="text-xs px-2 py-1 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full" required>
                                        </label>
                                        <label class="block col-span-2">
                                            <span class="text-[var(--color-ink-soft)]">Timezone</span>
                                            <select name="timezone" class="text-xs px-2 py-1 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full">
                                                <option value="America/New_York" selected>America/New_York (Eastern)</option>
                                                <option value="America/Chicago">America/Chicago (Central)</option>
                                                <option value="America/Denver">America/Denver (Mountain)</option>
                                                <option value="America/Los_Angeles">America/Los_Angeles (Pacific)</option>
                                                <option value="America/Phoenix">America/Phoenix (Arizona)</option>
                                                <option value="UTC">UTC</option>
                                            </select>
                                        </label>
                                        <label class="block col-span-2">
                                            <input type="checkbox" name="enabled" value="1" checked>
                                            <span class="text-[var(--color-ink-strong)]">Enabled</span>
                                        </label>
                                    </div>
                                    <button type="submit" class="btn-pill-nav text-xs">Add window</button>
                                </form>
                            </details>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Add recipient --}}
        <div class="p-5 bg-[var(--color-surface-alt)] border-t border-[var(--color-border-light)]">
            <details>
                <summary class="text-sm text-[var(--color-primary-600)] hover:underline cursor-pointer">+ Add recipient</summary>
                <form method="POST" action="{{ route('settings.notifications.recipients.store') }}" class="mt-3 space-y-2">
                    @csrf
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <label class="block">
                            <span class="text-xs text-[var(--color-ink-soft)]">Name</span>
                            <input type="text" name="name" class="text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full" required>
                        </label>
                        <label class="block">
                            <span class="text-xs text-[var(--color-ink-soft)]">Phone (E.164, e.g. +14045550100)</span>
                            <input type="text" name="phone" class="text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full font-data" required pattern="\+\d{8,15}">
                        </label>
                        <label class="block col-span-2">
                            <span class="text-xs text-[var(--color-ink-soft)]">Email fallback (optional)</span>
                            <input type="email" name="email_fallback" class="text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full">
                        </label>
                        <label class="block col-span-2">
                            <input type="checkbox" name="enabled" value="1" checked>
                            <span class="text-[var(--color-ink-strong)]">Enabled (start receiving SMS immediately)</span>
                        </label>
                    </div>
                    <button type="submit" class="btn-pill-nav text-xs">Add recipient</button>
                </form>
            </details>
        </div>
    </div>

    {{-- Recent activity --}}
    <div class="card overflow-hidden">
        <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-clock-rotate-left text-[var(--color-ink-muted)] mr-1"></i>
                Recent activity
            </h2>
            <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                Audit trail of every SMS attempt + fallback. Useful for "did the text actually go out at 3am?" questions.
            </p>
        </div>
        @if ($recentLogs->isEmpty())
            <div class="p-6 text-sm text-[var(--color-ink-muted)] text-center">
                No SMS activity recorded yet.
            </div>
        @else
            <table class="w-full text-sm">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <th class="text-left px-4 py-2">When</th>
                        <th class="text-left px-4 py-2">Event</th>
                        <th class="text-left px-4 py-2">Recipient</th>
                        <th class="text-left px-4 py-2">Site</th>
                        <th class="text-left px-4 py-2">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($recentLogs as $log)
                        <tr>
                            <td class="px-4 py-2 text-xs text-[var(--color-ink-muted)]" title="{{ $log->sent_at }}">{{ $log->sent_at->diffForHumans() }}</td>
                            <td class="px-4 py-2 text-xs font-data text-[var(--color-ink-strong)]">{{ $log->event }}</td>
                            <td class="px-4 py-2 text-xs">
                                @if ($log->recipient)
                                    {{ $log->recipient->name }}
                                @elseif ($log->phone)
                                    <span class="font-data">{{ $log->phone }}</span>
                                @else
                                    <span class="text-[var(--color-ink-soft)]">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-xs">
                                @if ($log->site)
                                    <span class="font-data">{{ $log->site->domain }}</span>
                                @else
                                    <span class="text-[var(--color-ink-soft)]">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-xs">
                                @if ($log->ok)
                                    <span class="status-pill status-green text-[10px]">
                                        <i class="fa-solid fa-circle-check"></i> sent
                                    </span>
                                @else
                                    <span class="status-pill status-red text-[10px]" title="{{ $log->error }}">
                                        <i class="fa-solid fa-circle-xmark"></i> failed
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
