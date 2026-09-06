@extends('layouts.install')

@section('title', 'Cloud Infrastructure & VPS Quick-Connect')

@section('content')
<div class="card p-6 md:p-8 shadow-sm">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="w-6 h-6 rounded-full bg-[var(--color-brand)]/10 text-[var(--color-brand)] inline-flex items-center justify-center text-xs">
                    <i class="fa-solid fa-microchip"></i>
                </span>
                <h1 class="font-display text-2xl font-bold tracking-tight text-[var(--color-ink-strong)]">
                    Step 8: Cloud Infrastructure &amp; VPS (Optional)
                </h1>
            </div>
            <p class="text-sm text-[var(--color-ink-muted)]">
                Connect the underlying cloud compute providers where your servers and virtual machines run.
            </p>
        </div>
        <form method="POST" action="{{ route('install.vps.skip') }}">
            @csrf
            <button type="submit" class="text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] underline whitespace-nowrap cursor-pointer">
                Skip for now
            </button>
        </form>
    </div>

    <!-- Hardware Telemetry Guidance Banner (Styled consistently with /setup) -->
    <div class="mb-6 p-4 rounded-xl border border-blue-500/30 bg-blue-500/10 text-[var(--color-ink-strong)] shadow-xs">
        <div class="flex items-start gap-3.5">
            <div class="w-8 h-8 rounded-lg bg-blue-500/20 text-blue-600 flex items-center justify-center flex-shrink-0 text-base mt-0.5 border border-blue-500/30">
                <i class="fa-solid fa-microchip"></i>
            </div>
            <div class="text-xs sm:text-sm leading-relaxed flex-1">
                <span class="font-bold block mb-0.5 text-blue-600 text-sm">Hardware Telemetry Pairing:</span>
                <div class="text-[var(--color-ink-muted)] leading-normal">
                    If you manage servers via <strong>SpinupWP</strong>, <strong>Cloudways</strong>, or <strong>GridPane</strong>, connecting your cloud compute provider (DigitalOcean, Hetzner, Vultr, Linode, Azure) unlocks 5-minute CPU, RAM, and droplet telemetry! Select all that apply.
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('install.vps.save') }}" class="space-y-8">
        @csrf

        <div class="space-y-3">
            <div class="flex items-center justify-between border-b border-[var(--color-border-light)] pb-2">
                <div class="flex items-center gap-2">
                    <span class="w-6 h-6 rounded-md bg-blue-500/10 text-blue-600 flex items-center justify-center text-xs">
                        <i class="fa-solid fa-server"></i>
                    </span>
                    <h2 class="font-display font-semibold text-sm text-[var(--color-ink-strong)]">
                        Cloud Compute Providers
                    </h2>
                    <span class="text-[10px] font-medium px-2 py-0.5 rounded-full bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] border border-[var(--color-border-light)]">
                        Hardware Telemetry
                    </span>
                </div>
                <span class="text-xs text-[var(--color-ink-soft)] hidden sm:inline">
                    Enables 5-minute CPU, memory, and hypervisor health polling.
                </span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3.5">
                @foreach ($providers as $key => $info)
                    @php
                        $isChecked = in_array($key, $selected, true);
                    @endphp
                    <label class="relative flex items-center justify-between p-4 rounded-xl border border-[var(--color-border-light)] hover:border-[var(--color-brand)] bg-[var(--color-surface-alt)]/40 hover:bg-[var(--color-surface-alt)] transition-all cursor-pointer has-[:checked]:border-[var(--color-brand)] has-[:checked]:ring-2 has-[:checked]:ring-[var(--color-brand)]/20 has-[:checked]:bg-[var(--color-surface)] shadow-xs"
                           x-data="{ checked: {{ $isChecked ? 'true' : 'false' }} }">
                        <input type="checkbox"
                               name="providers[]"
                               value="{{ $key }}"
                               class="sr-only"
                               @change="checked = $el.checked"
                               {{ $isChecked ? 'checked' : '' }} />

                        <!-- Left: Logo & Title (Matching /setup style) -->
                        <div class="flex items-center gap-3.5 min-w-0 pr-2">
                            <div class="w-10 h-10 flex items-center justify-center flex-shrink-0">
                                <x-service-logo :service="$key" class="w-8 h-8" />
                            </div>
                            <div class="min-w-0">
                                <span class="font-display font-bold text-base text-[var(--color-ink-strong)] leading-tight block">
                                    {{ $info['name'] }}
                                </span>
                            </div>
                        </div>

                        <!-- Right: Checkbox Indicator -->
                        <div class="flex items-center gap-2 flex-shrink-0 ml-3">
                            <span class="w-6 h-6 rounded-md border flex items-center justify-center text-xs transition-all"
                                  :class="checked ? 'bg-[var(--color-brand)] border-[var(--color-brand)] text-white' : 'border-[var(--color-border)] bg-[var(--color-surface)] text-transparent'">
                                <i class="fa-solid fa-check" :class="checked ? 'opacity-100' : 'opacity-0'"></i>
                            </span>
                        </div>
                    </label>
                @endforeach
            </div>
        </div>

        <div class="p-4 rounded-xl border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/50 text-xs text-[var(--color-ink-muted)] flex items-start gap-2.5">
            <i class="fa-solid fa-circle-info text-[var(--color-brand)] text-sm mt-0.5 shrink-0"></i>
            <div>
                <strong>Cloud Hardware Telemetry:</strong> Managed WordPress hosts (like Pressable, WP Engine, and Kinsta) manage their own infrastructure. If your fleet runs on dedicated VPS via SpinupWP, Cloudways, or GridPane, connecting your cloud provider lets Clockwork Control monitor server health and resource consumption.
            </div>
        </div>

        <div class="flex items-center justify-between pt-5 border-t border-[var(--color-border-light)]">
            <a href="{{ route('install.hosting') }}"
               class="inline-flex items-center gap-2 text-xs font-medium text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-arrow-left text-[10px]"></i>
                <span>Back to Hosting</span>
            </a>

            <button type="submit"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-[var(--color-brand)] text-white font-medium text-sm hover:bg-[var(--color-brand)]/90 transition-all shadow-sm cursor-pointer">
                <span>Continue to Review</span>
                <i class="fa-solid fa-arrow-right text-xs"></i>
            </button>
        </div>
    </form>
</div>
@endsection
