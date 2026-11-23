@extends('layouts.app')

@section('title', 'Let Claude into Control · Clockwork')

@php
    $redirectHost = parse_url((string) $request->query('redirect_uri', ''), PHP_URL_HOST) ?: 'unknown';
@endphp

@section('content')
<div class="max-w-xl mx-auto mt-6">
    <div class="card p-6" data-claude-access="consent">
        <h1 class="display-heading text-2xl text-[var(--color-ink-strong)] mb-2">Let {{ $client->name }} into Clockwork Control?</h1>

        <p class="text-sm text-[var(--color-ink)] mb-4">
            You are signed in as <strong>{{ $user->name }}</strong> <span class="font-data text-xs text-[var(--color-ink-soft)]">({{ $user->email }})</span>.
            If you allow this, {{ $client->name }} can use Control <strong>as you</strong>, with your role.
        </p>

        <div class="text-sm text-[var(--color-ink)] space-y-2 mb-5">
            <p class="font-semibold text-[var(--color-ink-strong)]">What it can do</p>
            <ul class="list-disc ml-5 space-y-1">
                <li>Read SEO numbers Control has stored: Search Console, GA4 and AI impressions.</li>
                <li>Record AI impressions and change a site's brand terms or key events. Each change is logged under your name.</li>
            </ul>
            <p class="font-semibold text-[var(--color-ink-strong)] pt-2">What it can't do</p>
            <ul class="list-disc ml-5 space-y-1">
                <li>See passwords, servers, or clients' contact details.</li>
                <li>Change anything on a client's website.</li>
            </ul>
        </div>

        <p class="text-xs text-[var(--color-ink-muted)] mb-5">
            After you choose, you are sent back to <span class="font-data">{{ $redirectHost }}</span>.
            Only continue if you started this from Claude yourself. You can disconnect at any time on Settings → Claude access.
        </p>

        <div class="flex items-center justify-end gap-3">
            <form method="POST" action="{{ route('passport.authorizations.deny') }}">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="{{ $request->query('state') }}">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="btn-pill-nav text-sm">Don't allow</button>
            </form>

            <form method="POST" action="{{ route('passport.authorizations.approve') }}">
                @csrf
                <input type="hidden" name="state" value="{{ $request->query('state') }}">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="btn-primary text-sm">Allow</button>
            </form>
        </div>
    </div>
</div>
@endsection
