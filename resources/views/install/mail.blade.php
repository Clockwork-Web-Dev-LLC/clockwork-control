@extends('layouts.install')

@section('title', 'Mail Configuration')

@section('content')
<div class="card p-6 md:p-8 shadow-sm"
     x-data="{
        mailer: '{{ old('mailer', $data['mailer'] ?? 'smtp') }}',
        host: '{{ old('host', $data['host'] ?? '127.0.0.1') }}',
        port: '{{ old('port', $data['port'] ?? '587') }}',
        testing: false,
        statusMessage: '',
        isError: false,

        async testMail() {
            this.testing = true;
            this.statusMessage = '';
            this.isError = false;

            try {
                const token = document.querySelector('meta[name=\'csrf-token\']')?.getAttribute('content');
                const res = await fetch('{{ route('install.mail.test') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': token,
                    },
                    body: JSON.stringify({
                        host: this.host,
                        port: this.port,
                    }),
                });

                const data = await res.json();
                if (res.ok && data.ok) {
                    this.isError = false;
                    this.statusMessage = data.message;
                } else {
                    this.isError = true;
                    this.statusMessage = data.message || 'SMTP connection failed.';
                }
            } catch (err) {
                this.isError = true;
                this.statusMessage = 'Network error testing SMTP port.';
            } finally {
                this.testing = false;
            }
        }
     }">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="font-display text-2xl font-bold tracking-tight text-[var(--color-ink-strong)]">
                Step 4: Outbound Mail (Optional)
            </h1>
            <p class="text-sm text-[var(--color-ink-muted)] mt-1">
                Configure SMTP for system alert notifications, vulnerability digests, and form failure alerts.
            </p>
        </div>
        <form method="POST" action="{{ route('install.mail.skip') }}">
            @csrf
            <button type="submit" class="text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] underline cursor-pointer">
                Skip for now
            </button>
        </form>
    </div>

    @if ($errors->any())
        <div class="p-4 rounded-xl border border-[var(--color-status-red)]/30 bg-[var(--color-status-red-bg)] text-xs text-[var(--color-status-red)] mb-6">
            <i class="fa-solid fa-triangle-exclamation mr-1"></i>
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('install.mail.save') }}">
        @csrf

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
            <div>
                <label class="block text-xs font-semibold text-[var(--color-ink-strong)] uppercase tracking-wide mb-1.5">
                    Mailer
                </label>
                <select name="mailer"
                        x-model="mailer"
                        class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]">
                    <option value="smtp">SMTP</option>
                    <option value="sendmail">Sendmail</option>
                    <option value="log">Log (Testing)</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-[var(--color-ink-strong)] uppercase tracking-wide mb-1.5">
                    SMTP Host
                </label>
                <input type="text"
                       name="host"
                       x-model="host"
                       required
                       placeholder="smtp.mailgun.org"
                       class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]" />
            </div>

            <div>
                <label class="block text-xs font-semibold text-[var(--color-ink-strong)] uppercase tracking-wide mb-1.5">
                    Port
                </label>
                <input type="number"
                       name="port"
                       x-model="port"
                       required
                       placeholder="587"
                       class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]" />
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
            <div>
                <label class="block text-xs font-semibold text-[var(--color-ink-strong)] uppercase tracking-wide mb-1.5">
                    Username
                </label>
                <input type="text"
                       name="username"
                       value="{{ old('username', $data['username'] ?? '') }}"
                       placeholder="postmaster@youragency.com"
                       class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]" />
            </div>

            <div>
                <label class="block text-xs font-semibold text-[var(--color-ink-strong)] uppercase tracking-wide mb-1.5">
                    Password
                </label>
                <input type="password"
                       name="password"
                       value="{{ old('password', $data['password'] ?? '') }}"
                       placeholder="••••••••"
                       class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]" />
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div>
                <label class="block text-xs font-semibold text-[var(--color-ink-strong)] uppercase tracking-wide mb-1.5">
                    From Email
                </label>
                <input type="email"
                       name="from_address"
                       value="{{ old('from_address', $data['from_address'] ?? 'hello@example.com') }}"
                       required
                       placeholder="noreply@youragency.com"
                       class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]" />
            </div>

            <div>
                <label class="block text-xs font-semibold text-[var(--color-ink-strong)] uppercase tracking-wide mb-1.5">
                    From Name
                </label>
                <input type="text"
                       name="from_name"
                       value="{{ old('from_name', $data['from_name'] ?? 'Clockwork Control') }}"
                       required
                       placeholder="Clockwork Control"
                       class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]" />
            </div>
        </div>

        <!-- Connection Test Feedback Panel -->
        <div x-show="statusMessage"
             x-cloak
             class="p-3.5 rounded-xl border text-xs mb-6 flex items-start gap-2.5 transition-all"
             :class="isError ? 'bg-[var(--color-status-red-bg)] border-[var(--color-status-red)]/30 text-[var(--color-status-red)]' : 'bg-[var(--color-status-green-bg)] border-[var(--color-status-green)]/30 text-[var(--color-status-green)]'">
            <i class="fa-solid mt-0.5" :class="isError ? 'fa-circle-xmark' : 'fa-circle-check'"></i>
            <span x-text="statusMessage"></span>
        </div>

        <div class="flex items-center justify-between pt-5 border-t border-[var(--color-border-light)]">
            <a href="{{ route('install.app') }}"
               class="inline-flex items-center gap-2 text-xs font-medium text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-arrow-left text-[10px]"></i>
                <span>Back</span>
            </a>

            <div class="flex items-center gap-3">
                <button type="button"
                        @click="testMail()"
                        :disabled="testing"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-full border border-[var(--color-border)] bg-[var(--color-surface-alt)] text-xs font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)]/80 transition-all cursor-pointer">
                    <i class="fa-solid" :class="testing ? 'fa-spinner fa-spin' : 'fa-paper-plane'"></i>
                    <span x-text="testing ? 'Testing...' : 'Test SMTP Port'"></span>
                </button>

                <button type="submit"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-[var(--color-brand)] text-white font-medium text-sm hover:bg-[var(--color-brand)]/90 transition-all shadow-sm cursor-pointer">
                    <span>Continue</span>
                    <i class="fa-solid fa-arrow-right text-xs"></i>
                </button>
            </div>
        </div>
    </form>
</div>
@endsection
