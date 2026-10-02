@extends('layouts.app')

@section('title', 'Notification settings · Clockwork')

@section('content')
    @include('settings._tabs')

    <x-page-header title="Alert notifications"
        subtitle="Site outage and recovery alerting via Twilio SMS and email. Team engineers receive operational alerts for care-plan sites; client subscribers receive plain-language notices for their specific domains." />

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
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Team Engineers</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">
                {{ $teamRecipients->count() }}
            </div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">{{ $teamRecipients->where('enabled', true)->count() }} active engineers</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Client Contacts</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">
                {{ $clientRecipients->count() }}
            </div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">{{ $clientRecipients->where('enabled', true)->count() }} active subscribers</div>
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

    {{-- SECTION 1: On-Call Engineering Team --}}
    <div class="card overflow-hidden mb-6 max-w-5xl">
        <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
            <div>
                <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] flex items-center gap-2">
                    <i class="fa-solid fa-user-shield text-indigo-500"></i>
                    On-Call Engineering Team
                </h2>
                <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                    Internal engineers paged via SMS when any care-plan site goes down. Managed with off-windows (observances, nights, vacations).
                </p>
            </div>
            <span class="text-xs text-[var(--color-ink-soft)]">{{ $teamRecipients->count() }} engineers</span>
        </div>

        @if ($teamRecipients->isEmpty())
            <div class="p-6 text-sm text-[var(--color-ink-muted)] text-center">
                No team engineers configured yet. Add one below to start receiving internal SMS alerts.
            </div>
        @else
            <div class="divide-y divide-[var(--color-border-light)]">
                @foreach ($teamRecipients as $recipient)
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
                                          class="mt-2 p-3 border border-[var(--color-border-light)] rounded space-y-2 bg-[var(--color-surface)]">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="type" value="team">
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
                                                <span class="text-[var(--color-ink-strong)]">Enabled (receives SMS alerts)</span>
                                            </label>
                                        </div>
                                        <button type="submit" class="btn-pill-nav text-xs">Save Changes</button>
                                    </form>
                                </details>
                                <form method="POST" action="{{ route('settings.notifications.recipients.destroy', $recipient) }}" class="inline"
                                      data-confirm="Remove {{ $recipient->name }} and all their off-windows?"
                                      data-confirm-btn="Remove Recipient"
                                      data-confirm-variant="danger">
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
                                                  data-confirm="Remove off-window {{ $win->label }}?"
                                                  data-confirm-btn="Remove Off-Window"
                                                  data-confirm-variant="danger">
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
                                      class="mt-2 p-3 border border-[var(--color-border-light)] rounded space-y-2 bg-[var(--color-surface)]">
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

        {{-- Add Team Recipient --}}
        <div class="p-5 bg-[var(--color-surface-alt)] border-t border-[var(--color-border-light)]">
            <details>
                <summary class="text-sm font-medium text-[var(--color-primary-600)] hover:underline cursor-pointer flex items-center gap-1.5">
                    <i class="fa-solid fa-plus text-xs"></i> Add team engineer
                </summary>
                <form method="POST" action="{{ route('settings.notifications.recipients.store') }}" class="mt-3 space-y-2 max-w-xl">
                    @csrf
                    <input type="hidden" name="type" value="team">
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <label class="block">
                            <span class="text-xs text-[var(--color-ink-soft)]">Full Name</span>
                            <input type="text" name="name" placeholder="e.g. Sarah Connor" class="text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full" required>
                        </label>
                        <label class="block">
                            <span class="text-xs text-[var(--color-ink-soft)]">Phone (E.164, e.g. +14045550100)</span>
                            <input type="text" name="phone" placeholder="+1..." class="text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full font-data" required pattern="\+\d{8,15}">
                        </label>
                        <label class="block col-span-2">
                            <span class="text-xs text-[var(--color-ink-soft)]">Email fallback (optional)</span>
                            <input type="email" name="email_fallback" placeholder="engineer@clockwork.net" class="text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full">
                        </label>
                        <label class="block col-span-2">
                            <input type="checkbox" name="enabled" value="1" checked>
                            <span class="text-[var(--color-ink-strong)]">Enabled (participates in on-call rotation)</span>
                        </label>
                    </div>
                    <button type="submit" class="btn-pill-nav text-xs mt-1">Add Team Engineer</button>
                </form>
            </details>
        </div>
    </div>

    {{-- SECTION 2: Client & Site Subscribers --}}
    <div class="card overflow-hidden mb-6 max-w-5xl">
        <div class="px-5 py-4 border-b border-[var(--color-border-light)] flex items-center justify-between">
            <div>
                <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] flex items-center gap-2">
                    <i class="fa-solid fa-users text-sky-500"></i>
                    Client Contacts (Site Subscribers)
                </h2>
                <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                    Client stakeholders who receive clear, non-technical outage and recovery notices for their specific websites only.
                </p>
            </div>
            <span class="text-xs text-[var(--color-ink-soft)]">{{ $clientRecipients->count() }} contacts</span>
        </div>

        @if ($clientRecipients->isEmpty())
            <div class="p-6 text-sm text-[var(--color-ink-muted)] text-center">
                No client subscribers configured yet. Add a client contact below to start sending site-specific alerts.
            </div>
        @else
            <div class="divide-y divide-[var(--color-border-light)]">
                @foreach ($clientRecipients as $client)
                    <div class="p-5">
                        <div class="flex items-start justify-between gap-3 flex-wrap mb-2">
                            <div class="min-w-[18rem]">
                                <div class="font-display text-base text-[var(--color-ink-strong)] flex items-center gap-2">
                                    {{ $client->name }}
                                    @if ($client->company)
                                        <span class="text-xs font-normal text-[var(--color-ink-muted)]">
                                            ({{ $client->company }})
                                        </span>
                                    @endif
                                    @if ($client->enabled)
                                        <span class="status-pill status-green text-[10px]">
                                            <span class="status-dot"></span> active
                                        </span>
                                    @else
                                        <span class="status-pill status-unknown text-[10px]">
                                            <span class="status-dot"></span> paused
                                        </span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                                    @if ($client->phone)
                                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs font-data bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] {{ $client->notify_sms ? 'text-[var(--color-ink-strong)]' : 'text-[var(--color-ink-soft)] line-through' }}">
                                            <i class="fa-solid fa-mobile-screen text-[10px] {{ $client->notify_sms ? 'text-sky-500' : 'text-[var(--color-ink-soft)]' }}"></i>
                                            {{ $client->phone }}
                                            @if ($client->notify_sms)
                                                <span class="text-[9px] uppercase tracking-wider text-sky-600 dark:text-sky-400 font-sans font-semibold">SMS</span>
                                            @endif
                                        </span>
                                    @endif
                                    @if ($client->resolvedEmail())
                                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs font-data bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] {{ $client->notify_email ? 'text-[var(--color-ink-strong)]' : 'text-[var(--color-ink-soft)] line-through' }}">
                                            <i class="fa-solid fa-envelope text-[10px] {{ $client->notify_email ? 'text-violet-500' : 'text-[var(--color-ink-soft)]' }}"></i>
                                            {{ $client->resolvedEmail() }}
                                            @if ($client->notify_email)
                                                <span class="text-[9px] uppercase tracking-wider text-violet-600 dark:text-violet-400 font-sans font-semibold">Email</span>
                                            @endif
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center gap-2 flex-wrap">
                                @if ($twilioConfigured && $client->enabled && $client->phone)
                                    <form method="POST" action="{{ route('settings.notifications.recipients.test', $client) }}" class="inline">
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
                                    <form method="POST" action="{{ route('settings.notifications.recipients.update', $client) }}"
                                          class="mt-2 p-3 border border-[var(--color-border-light)] rounded space-y-3 bg-[var(--color-surface)] w-80 sm:w-96 text-xs">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="type" value="client">
                                        <div class="grid grid-cols-2 gap-2">
                                            <label class="block">
                                                <span class="text-[var(--color-ink-soft)]">Name</span>
                                                <input type="text" name="name" value="{{ $client->name }}" class="text-xs px-2 py-1 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full" required>
                                            </label>
                                            <label class="block">
                                                <span class="text-[var(--color-ink-soft)]">Company</span>
                                                <input type="text" name="company" value="{{ $client->company }}" placeholder="e.g. Acme Corp" class="text-xs px-2 py-1 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full">
                                            </label>
                                            <label class="block">
                                                <span class="text-[var(--color-ink-soft)]">Phone (E.164)</span>
                                                <input type="text" name="phone" value="{{ $client->phone }}" placeholder="+1..." class="text-xs px-2 py-1 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full font-data" pattern="\+\d{8,15}">
                                            </label>
                                            <label class="block">
                                                <span class="text-[var(--color-ink-soft)]">Email</span>
                                                <input type="email" name="email" value="{{ $client->resolvedEmail() }}" class="text-xs px-2 py-1 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full font-data">
                                            </label>
                                            <div class="col-span-2 pt-1 border-t border-[var(--color-border-light)] space-y-1">
                                                <label class="flex items-center gap-1.5 cursor-pointer">
                                                    <input type="checkbox" name="notify_sms" value="1" @checked($client->notify_sms)>
                                                    <span class="text-[var(--color-ink-strong)]">Send SMS alerts (when phone is provided)</span>
                                                </label>
                                                <label class="flex items-center gap-1.5 cursor-pointer">
                                                    <input type="checkbox" name="notify_email" value="1" @checked($client->notify_email)>
                                                    <span class="text-[var(--color-ink-strong)]">Send Email alerts (when email is provided)</span>
                                                </label>
                                                <label class="flex items-center gap-1.5 cursor-pointer">
                                                    <input type="checkbox" name="enabled" value="1" @checked($client->enabled)>
                                                    <span class="text-[var(--color-ink-strong)]">Enabled (active subscriber)</span>
                                                </label>
                                            </div>
                                            <div class="col-span-2 pt-1 border-t border-[var(--color-border-light)]">
                                                <span class="text-[var(--color-ink-soft)] block mb-1">Subscribed Sites</span>
                                                <div class="max-h-36 overflow-y-auto border border-[var(--color-border)] rounded p-2 bg-[var(--color-surface-alt)]/40 space-y-1">
                                                    @foreach ($allSites as $siteItem)
                                                        <label class="flex items-center gap-1.5 text-xs cursor-pointer hover:bg-[var(--color-surface)] px-1 py-0.5 rounded">
                                                            <input type="checkbox" name="site_ids[]" value="{{ $siteItem->id }}"
                                                                @checked($client->sites->contains($siteItem->id))>
                                                            <span class="font-data text-[var(--color-ink-strong)] truncate">{{ $siteItem->domain }}</span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn-pill-nav text-xs">Save Changes</button>
                                    </form>
                                </details>
                                <form method="POST" action="{{ route('settings.notifications.recipients.destroy', $client) }}" class="inline"
                                      data-confirm="Remove client contact {{ $client->name }}?"
                                      data-confirm-btn="Remove Contact"
                                      data-confirm-variant="danger">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-pill-nav text-xs text-[var(--color-status-red)]" title="Remove this client contact">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>

                        {{-- Subscribed Sites list --}}
                        <div class="mt-2.5 pt-2 border-t border-[var(--color-border-light)]">
                            @if ($client->sites->isEmpty())
                                <div class="text-xs text-amber-600 dark:text-amber-400 flex items-center gap-1">
                                    <i class="fa-solid fa-triangle-exclamation text-[11px]"></i>
                                    <span>Not subscribed to any sites yet. Click <strong>Edit</strong> to assign sites.</span>
                                </div>
                            @else
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <span class="text-[10px] uppercase font-semibold text-[var(--color-ink-soft)] tracking-wider mr-1">Sites:</span>
                                    @foreach ($client->sites as $subSite)
                                        <a href="{{ route('sites.show', $subSite->domain) }}" class="inline-flex items-center gap-1 px-2 py-0.5 text-xs rounded-full bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] text-[var(--color-ink-strong)] hover:border-[var(--color-brand)] hover:text-[var(--color-brand)] transition-colors">
                                            <i class="fa-solid fa-globe text-[10px] text-[var(--color-ink-soft)]"></i>
                                            <span class="font-data">{{ $subSite->domain }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Add Client Recipient --}}
        <div class="p-5 bg-[var(--color-surface-alt)] border-t border-[var(--color-border-light)]">
            <details>
                <summary class="text-sm font-medium text-[var(--color-primary-600)] hover:underline cursor-pointer flex items-center gap-1.5">
                    <i class="fa-solid fa-plus text-xs"></i> Add client contact
                </summary>
                <form method="POST" action="{{ route('settings.notifications.recipients.store') }}" class="mt-3 space-y-3 max-w-xl text-sm">
                    @csrf
                    <input type="hidden" name="type" value="client">
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block">
                            <span class="text-xs text-[var(--color-ink-soft)]">Contact Name</span>
                            <input type="text" name="name" placeholder="e.g. John Sandor" class="text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full" required>
                        </label>
                        <label class="block">
                            <span class="text-xs text-[var(--color-ink-soft)]">Company (optional)</span>
                            <input type="text" name="company" placeholder="e.g. Sandor Development" class="text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full">
                        </label>
                        <label class="block">
                            <span class="text-xs text-[var(--color-ink-soft)]">Phone (E.164, e.g. +14045550100)</span>
                            <input type="text" name="phone" placeholder="+1..." class="text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full font-data" pattern="\+\d{8,15}">
                        </label>
                        <label class="block">
                            <span class="text-xs text-[var(--color-ink-soft)]">Email Address</span>
                            <input type="email" name="email" placeholder="contact@example.com" class="text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full font-data">
                        </label>
                        <div class="col-span-2 pt-1 border-t border-[var(--color-border-light)] space-y-1.5">
                            <label class="flex items-center gap-2 cursor-pointer text-xs">
                                <input type="checkbox" name="notify_sms" value="1" checked>
                                <span class="text-[var(--color-ink-strong)]">Send SMS alerts (when phone is entered)</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer text-xs">
                                <input type="checkbox" name="notify_email" value="1" checked>
                                <span class="text-[var(--color-ink-strong)]">Send Email alerts (when email is entered)</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer text-xs">
                                <input type="checkbox" name="enabled" value="1" checked>
                                <span class="text-[var(--color-ink-strong)]">Enabled (receives alerts immediately)</span>
                            </label>
                        </div>
                        <div class="col-span-2 pt-1 border-t border-[var(--color-border-light)]">
                            <span class="text-xs text-[var(--color-ink-soft)] block mb-1">Assign to Sites:</span>
                            <div class="max-h-40 overflow-y-auto border border-[var(--color-border)] rounded p-2.5 bg-[var(--color-surface)] grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                                @foreach ($allSites as $siteItem)
                                    <label class="flex items-center gap-2 text-xs cursor-pointer hover:bg-[var(--color-surface-alt)] px-1.5 py-1 rounded">
                                        <input type="checkbox" name="site_ids[]" value="{{ $siteItem->id }}">
                                        <span class="font-data text-[var(--color-ink-strong)] truncate">{{ $siteItem->domain }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn-pill-nav text-xs">Add Client Contact</button>
                </form>
            </details>
        </div>
    </div>

    {{-- SECTION 3: Recent Activity --}}
    <div class="card overflow-hidden max-w-5xl">
        <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] flex items-center gap-2">
                <i class="fa-solid fa-clock-rotate-left text-[var(--color-ink-muted)]"></i>
                Recent Alert Activity
            </h2>
            <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                Audit trail of every SMS and email attempt. Useful for verifying dispatch timing and debugging carrier delivery issues.
            </p>
        </div>
        @if ($recentLogs->isEmpty())
            <div class="p-6 text-sm text-[var(--color-ink-muted)] text-center">
                No alert activity recorded yet.
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
                                    <div class="font-medium text-[var(--color-ink-strong)]">
                                        {{ $log->recipient->name }}
                                        @if ($log->recipient->isClient() && $log->recipient->company)
                                            <span class="text-[10px] text-[var(--color-ink-soft)]">({{ $log->recipient->company }})</span>
                                        @endif
                                    </div>
                                    @if ($log->phone)
                                        <div class="font-data text-[10px] text-[var(--color-ink-muted)]">{{ $log->phone }}</div>
                                    @endif
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
