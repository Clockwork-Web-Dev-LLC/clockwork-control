@extends('layouts.install')

@section('title', 'Database Connection')

@section('content')
<div class="card p-6 md:p-8 shadow-sm"
     x-data="{
        host: '{{ old('host', $data['host'] ?? '127.0.0.1') }}',
        port: '{{ old('port', $data['port'] ?? '3306') }}',
        database: '{{ old('database', $data['database'] ?? 'clockwork') }}',
        username: '{{ old('username', $data['username'] ?? 'root') }}',
        password: '{{ old('password', $data['password'] ?? '') }}',
        testing: false,
        testedOk: false,
        statusMessage: '',
        isError: false,

        async testConnection() {
            this.testing = true;
            this.statusMessage = '';
            this.isError = false;

            try {
                const token = document.querySelector('meta[name=\'csrf-token\']')?.getAttribute('content');
                const res = await fetch('{{ route('install.database.test') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': token,
                    },
                    body: JSON.stringify({
                        host: this.host,
                        port: this.port,
                        database: this.database,
                        username: this.username,
                        password: this.password,
                    }),
                });

                const data = await res.json();
                if (res.ok && data.ok) {
                    this.testedOk = true;
                    this.isError = false;
                    this.statusMessage = data.message;
                } else {
                    this.testedOk = false;
                    this.isError = true;
                    this.statusMessage = data.message || 'Connection failed.';
                }
            } catch (err) {
                this.testedOk = false;
                this.isError = true;
                this.statusMessage = 'Network error while attempting database connection.';
            } finally {
                this.testing = false;
            }
        }
     }">
    <div class="mb-6">
        <h1 class="font-display text-2xl font-bold tracking-tight text-[var(--color-ink-strong)]">
            Step 2: Database Connection
        </h1>
        <p class="text-sm text-[var(--color-ink-muted)] mt-1">
            Provide the connection parameters for your MySQL or MariaDB database. You can test the connection live before continuing.
        </p>
    </div>

    @if ($errors->any())
        <div class="p-4 rounded-xl border border-[var(--color-status-red)]/30 bg-[var(--color-status-red-bg)] text-xs text-[var(--color-status-red)] mb-6">
            <i class="fa-solid fa-triangle-exclamation mr-1"></i>
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('install.database.save') }}">
        @csrf

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
            <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-[var(--color-ink-strong)] uppercase tracking-wide mb-1.5">
                    Database Host
                </label>
                <input type="text"
                       name="host"
                       x-model="host"
                       required
                       placeholder="127.0.0.1"
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
                       placeholder="3306"
                       class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]" />
            </div>
        </div>

        <div class="mb-5">
            <label class="block text-xs font-semibold text-[var(--color-ink-strong)] uppercase tracking-wide mb-1.5">
                Database Name
            </label>
            <input type="text"
                   name="database"
                   x-model="database"
                   required
                   placeholder="clockwork"
                   class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]" />
            <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">Make sure the database already exists or has been created.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div>
                <label class="block text-xs font-semibold text-[var(--color-ink-strong)] uppercase tracking-wide mb-1.5">
                    Username
                </label>
                <input type="text"
                       name="username"
                       x-model="username"
                       required
                       placeholder="root"
                       class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]" />
            </div>

            <div>
                <label class="block text-xs font-semibold text-[var(--color-ink-strong)] uppercase tracking-wide mb-1.5">
                    Password
                </label>
                <input type="password"
                       name="password"
                       x-model="password"
                       placeholder="••••••••"
                       class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]" />
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
            <a href="{{ route('install.welcome') }}"
               class="inline-flex items-center gap-2 text-xs font-medium text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-arrow-left text-[10px]"></i>
                <span>Back</span>
            </a>

            <div class="flex items-center gap-3">
                <button type="button"
                        @click="testConnection()"
                        :disabled="testing"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-full border border-[var(--color-border)] bg-[var(--color-surface-alt)] text-xs font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)]/80 transition-all cursor-pointer">
                    <i class="fa-solid" :class="testing ? 'fa-spinner fa-spin' : 'fa-plug'"></i>
                    <span x-text="testing ? 'Testing...' : 'Test Connection'"></span>
                </button>

                <button type="submit"
                        :disabled="!testedOk"
                        :title="!testedOk ? 'Test the connection successfully before continuing' : ''"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-[var(--color-brand)] text-white font-medium text-sm hover:bg-[var(--color-brand)]/90 transition-all shadow-sm cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">
                    <span>Continue</span>
                    <i class="fa-solid fa-arrow-right text-xs"></i>
                </button>
            </div>
        </div>
    </form>
</div>
@endsection
