@extends('layouts.app')

@section('title', 'Claude access · Clockwork')

@php
    $labelClass = 'text-xs uppercase tracking-wider text-[var(--color-ink-muted)]';
    $codeClass = 'font-data text-xs break-all block mt-1 p-2 rounded bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] select-all';
    $when = fn (?string $time) => $time ? \Illuminate\Support\Carbon::parse($time)->diffForHumans() : 'unknown';
@endphp

@section('content')
<x-page-header title="Claude access"
    subtitle="Lets Claude read Control's SEO numbers, so nobody has to copy them out of Search Console and GA4 by hand." />

@include('settings._tabs')

@if (session('status'))
    <div class="card p-4 mb-6 status-green flex items-center gap-2">
        <i class="fa-solid fa-circle-check"></i> {{ session('status') }}
    </div>
@endif
@if (session('warning'))
    <div class="card p-4 mb-6 status-yellow flex items-center gap-2">
        <i class="fa-solid fa-triangle-exclamation"></i> {{ session('warning') }}
    </div>
@endif

@if ($newSecret)
    <div class="card p-5 mb-6 status-yellow" data-claude-access="new-secret">
        <p class="font-semibold mb-1"><i class="fa-solid fa-key mr-1"></i> Copy the client secret now</p>
        <p class="text-sm mb-2">This is the only time it is shown. Control keeps a scrambled copy and can't show it again. If you lose it, issue a new one below.</p>
        <code class="{{ $codeClass }}">{{ $newSecret }}</code>
    </div>
@endif

<div class="max-w-4xl space-y-6">
    {{-- 1. The switch --}}
    <x-card title="Connection from claude.ai and Cowork" icon="fa-tower-broadcast"
        subtitle="Off by default. While it is off, Control has no address for Claude at all.">
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div class="flex items-center gap-3">
                <x-status-pill :status="$enabled ? 'green' : 'gray'" data-claude-access-state="{{ $enabled ? 'on' : 'off' }}">
                    {{ $enabled ? 'On' : 'Off' }}
                </x-status-pill>
                <span class="text-sm text-[var(--color-ink)]">
                    @if ($enabled)
                        People can connect Claude and sign in with their Control account.
                    @elseif (! $ready)
                        Finish the one-time setup below before turning this on.
                    @else
                        Ready to turn on.
                    @endif
                </span>
            </div>

            @if ($isAdmin)
                <form method="POST" action="{{ route('settings.claude-access.update') }}"
                      @if ($enabled) onsubmit="return confirm('Turn Claude access off? Nobody will be able to reach Control from Claude until it is turned on again.')" @endif>
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="enabled" value="{{ $enabled ? '0' : '1' }}">
                    <button type="submit" class="{{ $enabled ? 'btn-pill-nav' : 'btn-primary' }} text-sm disabled:opacity-40 disabled:cursor-not-allowed" @disabled(! $enabled && ! $ready)>
                        {{ $enabled ? 'Turn off' : 'Turn on' }}
                    </button>
                </form>
            @else
                <span class="text-xs text-[var(--color-ink-soft)]">Only an administrator can change this.</span>
            @endif
        </div>

        @if ($enabled && ! $isHttps)
            <p class="text-sm mt-4 p-3 rounded status-yellow">
                <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                You are viewing Control at an address that doesn't start with https. Claude connects from Anthropic's servers and needs a public https address for Control. Open this page at that address to see the right connector URL.
            </p>
        @endif

        <p class="text-xs text-[var(--color-ink-muted)] mt-4">
            Turning this on is not enough on its own. Claude calls Control from Anthropic's servers, so Control also needs a public https hostname that lets
            <span class="font-data">/mcp</span>, <span class="font-data">/oauth/*</span> and <span class="font-data">/.well-known/oauth-*</span> through and nothing else.
            That is set up on the network side (see the Claude access page in Docs).
        </p>
    </x-card>

    {{-- 2. Setup and the details to paste into Claude --}}
    <x-card title="Set up and connect" icon="fa-plug">
        <ol class="space-y-5 text-sm text-[var(--color-ink)]">
            <li>
                <p class="font-semibold text-[var(--color-ink-strong)]">1. One-time setup in Control</p>
                <ul class="mt-2 space-y-1">
                    <li data-claude-access-check="keys">
                        <i class="fa-solid {{ $keysExist ? 'fa-circle-check text-[var(--color-status-green)]' : 'fa-circle text-[var(--color-ink-soft)]' }} mr-1"></i>
                        Signing keys {{ $keysExist ? 'are in place' : 'not created yet' }}
                    </li>
                    <li data-claude-access-check="client">
                        <i class="fa-solid {{ $client ? 'fa-circle-check text-[var(--color-status-green)]' : 'fa-circle text-[var(--color-ink-soft)]' }} mr-1"></i>
                        Claude's sign-in details {{ $client ? 'are created' : 'not created yet' }}
                    </li>
                </ul>
                @if ($isAdmin && ! $ready)
                    <form method="POST" action="{{ route('settings.claude-access.setup') }}" class="mt-3">
                        @csrf
                        <button type="submit" class="btn-primary text-sm"><i class="fa-solid fa-wand-magic-sparkles mr-1.5"></i> Run setup</button>
                    </form>
                @elseif (! $ready)
                    <p class="text-xs text-[var(--color-ink-soft)] mt-2">An administrator runs this once.</p>
                @endif
            </li>

            <li>
                <p class="font-semibold text-[var(--color-ink-strong)]">2. Add Control to Claude as a custom connector</p>
                <p class="mt-1">
                    In Claude, an organization owner opens <strong>Settings → Connectors → Add custom connector</strong> and enters these.
                    On a personal plan, each person adds it for themselves.
                </p>
                <dl class="mt-3 grid grid-cols-1 gap-3">
                    <div>
                        <dt class="{{ $labelClass }}">Connector URL</dt>
                        <dd><code class="{{ $codeClass }}" data-claude-access="endpoint">{{ $endpointUrl }}</code></dd>
                    </div>
                    @if ($client && $isAdmin)
                        <div>
                            <dt class="{{ $labelClass }}">OAuth client ID</dt>
                            <dd><code class="{{ $codeClass }}" data-claude-access="client-id">{{ $client->getKey() }}</code></dd>
                        </div>
                        <div>
                            <dt class="{{ $labelClass }}">OAuth client secret</dt>
                            <dd class="mt-1 text-sm">
                                Shown once, when it is created. Lost it? Issue a new one. The old one stops working, so update the connector in Claude afterwards.
                                <form method="POST" action="{{ route('settings.claude-access.rotate-secret') }}" class="mt-2"
                                      onsubmit="return confirm('Issue a new client secret? The current one stops working at once, and the connector in Claude has to be updated with the new one.')">
                                    @csrf
                                    <button type="submit" class="btn-pill-nav text-sm"><i class="fa-solid fa-rotate mr-1.5"></i> Issue a new secret</button>
                                </form>
                            </dd>
                        </div>
                    @elseif ($client)
                        <div class="text-xs text-[var(--color-ink-soft)]">An administrator has the client ID and secret.</div>
                    @endif
                </dl>
            </li>

            <li>
                <p class="font-semibold text-[var(--color-ink-strong)]">3. Each person connects</p>
                <p class="mt-1">
                    In Claude, choose the Clockwork Control connector and click Connect. You sign in to Control as usual, see a page asking whether to let Claude in, and click Allow.
                    Claude then acts as you, with your role, and what it changes is logged under your name.
                </p>
            </li>
        </ol>
    </x-card>

    {{-- 3. Who is connected --}}
    <x-card :title="$isAdmin ? 'Who is connected' : 'Your connection'" icon="fa-user-check"
        :subtitle="$isAdmin ? 'Everyone who has let Claude into Control and can still use it.' : 'Whether you have let Claude into Control.'">
        @if ($isAdmin && $authorizations !== [])
            <x-slot:actions>
                <form method="POST" action="{{ route('settings.claude-access.revoke-all') }}"
                      onsubmit="return confirm('Disconnect everyone? Each person will have to sign in again the next time they use Claude with Control.')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-pill-nav text-sm text-[var(--color-status-red)]"><i class="fa-solid fa-link-slash mr-1.5"></i> Disconnect everyone</button>
                </form>
            </x-slot:actions>
        @endif

        @if ($authorizations === [])
            <p class="text-sm text-[var(--color-ink-muted)]">{{ $isAdmin ? 'Nobody is connected.' : 'You have not connected Claude.' }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">
                        <tr>
                            <th class="py-2 pr-4 font-semibold">Person</th>
                            <th class="py-2 pr-4 font-semibold">Connected</th>
                            <th class="py-2 pr-4 font-semibold">Last renewed</th>
                            <th class="py-2 font-semibold text-right"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @foreach ($authorizations as $row)
                            <tr data-claude-access-user="{{ $row['user_id'] }}">
                                <td class="py-2 pr-4">
                                    <span class="text-[var(--color-ink-strong)]">{{ $row['user_name'] ?? 'Unknown user' }}</span>
                                    <span class="block text-xs text-[var(--color-ink-soft)] font-data">{{ $row['user_email'] }}</span>
                                </td>
                                <td class="py-2 pr-4 text-[var(--color-ink)]">{{ $when($row['connected_at']) }}</td>
                                <td class="py-2 pr-4 text-[var(--color-ink)]">{{ $when($row['last_token_at']) }}</td>
                                <td class="py-2 text-right">
                                    <form method="POST" action="{{ route('settings.claude-access.revoke', ['user' => $row['user_id'], 'client' => $row['client_id']]) }}"
                                          onsubmit="return confirm('Disconnect Claude for this person?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-pill-nav text-xs">Disconnect</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <p class="text-xs text-[var(--color-ink-muted)] mt-4">
            Removing someone on Settings → Users disconnects their Claude too. A connection lasts 90 days without use before the person has to sign in again.
        </p>
    </x-card>

    {{-- 4. What Claude can do --}}
    <x-card title="What Claude can do" icon="fa-list-check"
        subtitle="Tools read data Control already stored. None can see a password, a server or a client's contact details.">
        <ul class="divide-y divide-[var(--color-border-light)] text-sm">
            @foreach ($tools as $tool)
                <li class="py-2 flex items-center justify-between gap-3">
                    <span>
                        <span class="text-[var(--color-ink-strong)]">{{ $tool['title'] }}</span>
                        <span class="block text-xs text-[var(--color-ink-soft)] font-data">{{ $tool['name'] }}</span>
                    </span>
                    <x-status-pill :status="$tool['writes'] ? 'yellow' : 'gray'">{{ $tool['writes'] ? 'Can change data' : 'Reads only' }}</x-status-pill>
                </li>
            @endforeach
        </ul>
        <p class="text-xs text-[var(--color-ink-muted)] mt-3">Tools come from enabled modules. Turning the SEO module off removes its tools.</p>
    </x-card>

    {{-- 5. The local route --}}
    <x-card title="Claude Code on this server" icon="fa-terminal"
        subtitle="A separate way in that needs no public address: Claude Code running on the Control host itself.">
        <p class="text-sm text-[var(--color-ink)]">
            @if ($localUser)
                On. It acts as <span class="font-data">{{ $localUser }}</span> (set by <span class="font-data">CLOCKWORK_MCP_LOCAL_USER</span>).
            @else
                Off. To use it, set <span class="font-data">CLOCKWORK_MCP_LOCAL_USER</span> in Control's <span class="font-data">.env</span> to an active user's email.
            @endif
            The switch above does not affect it.
        </p>
    </x-card>
</div>
@endsection
