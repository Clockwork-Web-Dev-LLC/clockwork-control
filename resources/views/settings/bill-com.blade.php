@extends('layouts.app')

@section('title', 'Bill.com sync · Clockwork')

@section('content')
    <x-page-header title="Bill.com sync"
        subtitle="Read-only sync. Auto-links sites to Bill.com customers via domains in invoice line item descriptions, and auto-flips care_plan_enabled based on care-plan-Item invoicing." />

    @if (session('bill_com_status'))
        <div class="card p-4 mb-4 status-green text-sm flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i> {{ session('bill_com_status') }}
        </div>
    @endif
    @if (session('bill_com_error'))
        <div class="card p-4 mb-4 status-red text-sm flex items-center gap-2">
            <i class="fa-solid fa-circle-xmark"></i> {{ session('bill_com_error') }}
        </div>
    @endif

    {{-- Credentials + sync status --}}
    <div class="card p-5 mb-4">
        <div class="flex items-start justify-between gap-3 flex-wrap mb-3">
            <h2 class="font-display text-base font-semibold text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-key text-[var(--color-ink-muted)] mr-1"></i>
                Credentials
            </h2>
            @if ($configured)
                <span class="status-pill status-green text-xs">
                    <span class="status-dot"></span> Configured
                </span>
            @else
                <span class="status-pill status-red text-xs">
                    <span class="status-dot"></span> Not configured
                </span>
            @endif
        </div>

        @if ($configured)
            <p class="text-sm text-[var(--color-ink-muted)]">
                Credentials are present in <code>.env</code>. Sync is currently
                <strong class="{{ $enabled ? 'text-[var(--color-status-green)]' : 'text-[var(--color-status-yellow)]' }}">
                    {{ $enabled ? 'enabled' : 'disabled' }}
                </strong>
                — toggle <code>CLOCKWORK_BILL_COM_ENABLED</code> in <code>.env</code> to flip.
            </p>
        @else
            <p class="text-sm text-[var(--color-ink-muted)]">
                Set the following in <code>.env</code>, then reload:
            </p>
            <ul class="text-xs font-data text-[var(--color-ink-muted)] mt-2 space-y-0.5">
                <li>CLOCKWORK_BILL_COM_USERNAME</li>
                <li>CLOCKWORK_BILL_COM_PASSWORD</li>
                <li>CLOCKWORK_BILL_COM_ORG_ID</li>
                <li>CLOCKWORK_BILL_COM_DEV_KEY <span class="text-[var(--color-ink-soft)]">— generate at <a href="https://developer.bill.com/" class="underline" target="_blank">developer.bill.com</a></span></li>
                <li>CLOCKWORK_BILL_COM_ENABLED=true</li>
            </ul>
        @endif

        <p class="text-xs text-[var(--color-ink-soft)] mt-3">
            Verify with <code>php artisan clockwork:bill-com-test</code>.
        </p>
    </div>

    {{-- Stats + run buttons --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
        <div class="card p-5">
            <h2 class="font-display text-base font-semibold text-[var(--color-ink-strong)] mb-3">
                <i class="fa-solid fa-link text-[var(--color-ink-muted)] mr-1"></i>
                Customer linking
            </h2>
            <p class="text-sm text-[var(--color-ink-muted)] mb-3">
                <strong>{{ $linkedSiteCount }}</strong> of {{ $totalSiteCount }} sites are linked to a Bill.com customer.<br>
                <strong>{{ $cachedCustomerCount }}</strong> Bill.com customers cached locally.
            </p>
            <form method="POST" action="{{ route('settings.bill-com.run-customer-sync') }}">
                @csrf
                <button type="submit"
                        class="btn-primary text-sm"
                        @disabled(! $configured)
                        onclick="return confirm('Run customer sync now? Pulls all customers + last 365 days of invoices from Bill.com. Takes ~30s.')">
                    <i class="fa-solid fa-rotate"></i> Run customer sync now
                </button>
            </form>
        </div>

        <div class="card p-5">
            <h2 class="font-display text-base font-semibold text-[var(--color-ink-strong)] mb-3">
                <i class="fa-solid fa-shield-heart text-[var(--color-ink-muted)] mr-1"></i>
                Care plan detection
            </h2>
            <p class="text-sm text-[var(--color-ink-muted)] mb-3">
                Item-name regex: <code>{{ $carePlanItemRegex }}</code><br>
                <strong>{{ $carePlanItems->count() }}</strong> items currently classified as care-plan items.
            </p>
            @if ($carePlanItems->isNotEmpty())
                <ul class="text-xs text-[var(--color-ink-muted)] mb-3 space-y-0.5">
                    @foreach ($carePlanItems as $item)
                        <li>· {{ $item->name }}</li>
                    @endforeach
                </ul>
            @endif
            <form method="POST" action="{{ route('settings.bill-com.run-care-plan-sync') }}">
                @csrf
                <button type="submit"
                        class="btn-primary text-sm"
                        @disabled(! $configured)
                        onclick="return confirm('Run care plan sync now? Walks last 60 days of invoices, may flip care_plan_enabled on linked sites (manual overrides preserved).')">
                    <i class="fa-solid fa-rotate"></i> Run care plan sync now
                </button>
            </form>
        </div>
    </div>

    {{-- Recent sync runs --}}
    <div class="card overflow-hidden">
        <div class="px-5 py-4 border-b border-[var(--color-border-light)]">
            <h2 class="font-display text-base font-semibold text-[var(--color-ink-strong)]">Recent sync runs</h2>
        </div>
        @if ($lastRun->isEmpty())
            <div class="p-6 text-center text-sm text-[var(--color-ink-soft)]">
                No sync runs yet. Use the buttons above to run one manually, or wait for the daily 01:00/01:30 schedule.
            </div>
        @else
            <ul class="divide-y divide-[var(--color-border-light)]">
                @foreach ($lastRun as $row)
                    <li class="px-5 py-2 text-sm">
                        <div class="text-[var(--color-ink-strong)]">
                            {{ $row->summary }}
                            @unless ($row->ok)
                                <span class="status-pill status-red text-[10px] ml-1">failed</span>
                            @endunless
                        </div>
                        <div class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                            {{ $row->ran_at?->diffForHumans() }} · {{ $row->target }} · {{ $row->actor }}
                            @if ($row->elapsed_ms) · {{ number_format($row->elapsed_ms / 1000, 1) }}s @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
