@extends('layouts.app')

@section('title', 'Email client · '.$domain->domain.' · Clockwork')

@section('content')
<div x-data="{
        previewHtml: '',
        loading: false,
        timer: null,
        async refresh() {
            this.loading = true;
            try {
                const res = await fetch(@js(route('email-auth.notify.preview', $domain->domain)), {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': @js(csrf_token()), 'Accept': 'text/html' },
                    body: new FormData(this.$refs.form),
                });
                this.previewHtml = await res.text();
            } catch (e) {
                console.error('Preview failed', e);
            } finally {
                this.loading = false;
            }
        },
        queue() { clearTimeout(this.timer); this.timer = setTimeout(() => this.refresh(), 400); },
     }"
     x-init="refresh()">

    <x-page-header title="Email client about {{ $domain->domain }}"
        subtitle="Write a note, choose which findings to include, and send a branded email. The client replies to your support address." >
        <x-slot:actions>
            <a href="{{ route('email-auth.index', ['search' => $domain->domain]) }}" class="text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-arrow-left mr-1"></i> Back to Email Authentication
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('error'))
        <div class="card p-4 mb-6 status-red flex items-center gap-2"><i class="fa-solid fa-circle-exclamation"></i> {{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="card p-4 mb-6 status-red text-sm">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif
    @if ($deliveryOff)
        <div class="card p-4 mb-6 status-yellow text-sm flex items-start gap-2">
            <i class="fa-solid fa-triangle-exclamation mt-0.5"></i>
            <div><strong>Email delivery is off.</strong> <code>MAIL_MAILER=log</code> is set, so “Send” writes this email to the log instead of delivering it. Switch to <code>mailgun</code> to actually reach the client.</div>
        </div>
    @endif

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 items-start">
        <form x-ref="form" method="POST" action="{{ route('email-auth.notify.send', $domain->domain) }}"
              class="card p-5 space-y-5" @input="queue()" @change="queue()"
              data-confirm="Send this email to the client now?">
            @csrf

            <div>
                <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">To</label>
                <input type="text" name="to" value="{{ old('to', implode(', ', $recipients)) }}"
                       class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 text-sm font-data"
                       placeholder="name@client.com, other@client.com">
                <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">
                    @if ($clients->isNotEmpty())
                        From client{{ $clients->count() > 1 ? 's' : '' }}: {{ $clients->map(fn ($c) => $c->company_name ?: $c->name)->implode(', ') }}. Separate addresses with commas.
                    @else
                        No client is linked to a site on this domain yet — enter addresses manually.
                    @endif
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Subject</label>
                    <input type="text" name="subject" value="{{ old('subject', $defaultSubject) }}" maxlength="200"
                           class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Greeting name</label>
                    <input type="text" name="greeting_name" value="{{ old('greeting_name', $greetingName) }}" maxlength="120"
                           class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 text-sm" placeholder="Hi …">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-[var(--color-ink-strong)] mb-1">Your note</label>
                <textarea name="note" rows="7" maxlength="5000"
                          class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 text-sm leading-relaxed">{{ old('note', $defaultNote) }}</textarea>
            </div>

            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="block text-xs font-semibold text-[var(--color-ink-strong)]">Findings to include</label>
                    <span class="text-[11px] text-[var(--color-ink-soft)]">Checked {{ $check->checked_at?->diffForHumans() }}</span>
                </div>
                @forelse ($findings as $f)
                    <label class="flex items-start gap-3 p-3 rounded-lg border border-[var(--color-border-light)] mb-2 cursor-pointer hover:bg-[var(--color-surface-alt)]">
                        <input type="checkbox" name="findings[]" value="{{ $f['code'] }}" class="mt-1"
                               @checked(old('findings') ? in_array($f['code'], old('findings', []), true) : $f['selected'])>
                        <span class="text-sm">
                            <span class="status-pill text-[10px] {{ $f['severity'] === 'fail' ? 'status-red' : ($f['severity'] === 'warn' ? 'status-yellow' : 'bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)]') }}">{{ strtoupper($f['severity']) }}</span>
                            <span class="font-semibold text-[var(--color-ink-strong)] ml-1">{{ $f['title'] }}</span>
                            <span class="block text-[11px] text-[var(--color-ink-muted)] mt-0.5 font-data">{{ $f['check'] }} · {{ $f['technical'] }}</span>
                        </span>
                    </label>
                @empty
                    <p class="text-sm text-[var(--color-ink-muted)]">No issues on this domain — the email will contain just your note.</p>
                @endforelse
            </div>

            <div class="flex items-center justify-between gap-4 pt-2 border-t border-[var(--color-border-light)]">
                <label class="flex items-center gap-2 text-sm text-[var(--color-ink-muted)]">
                    <input type="checkbox" name="copy_me" value="1" @checked(old('copy_me', true))> Send me a copy
                </label>
                <button type="submit" class="btn-primary text-sm px-5 py-2">
                    <i class="fa-solid fa-paper-plane mr-1.5"></i> Send to client
                </button>
            </div>
        </form>

        <div class="card overflow-hidden xl:sticky xl:top-4">
            <div class="px-4 py-2.5 border-b border-[var(--color-border-light)] flex items-center justify-between text-xs">
                <span class="font-semibold text-[var(--color-ink-strong)]"><i class="fa-regular fa-envelope mr-1"></i> Preview</span>
                <span class="text-[var(--color-ink-soft)]" x-show="loading">Updating…</span>
            </div>
            <iframe title="Email preview" :srcdoc="previewHtml" class="w-full bg-[#f1f5f9]" style="height: 78vh; border: 0;"></iframe>
        </div>
    </div>
</div>
@endsection
