@php
    $sslState = $site->sslState();
    $sslMeta = match (true) {
        $site->cert_source === 'redirect_only' => ['class' => 'status-unknown', 'icon' => 'fa-arrow-up-right-from-square', 'label' => 'SSL skipped (redirect-only)'],
        $sslState === 'green' => ['class' => 'status-green', 'icon' => 'fa-lock', 'label' => 'Cert OK'],
        $sslState === 'yellow' => ['class' => 'status-yellow', 'icon' => 'fa-clock-rotate-left', 'label' => 'Renewal needed'],
        $sslState === 'red' => ['class' => 'status-red', 'icon' => 'fa-lock-open', 'label' => 'Expired'],
        default => ['class' => 'status-unknown', 'icon' => 'fa-circle-question', 'label' => 'No SSL tracked'],
    };
@endphp

<div class="mb-2 text-sm">
    @if ($site->server)
        <a href="{{ route('servers.show', $site->server) }}" class="text-[var(--color-ink-soft)] hover:text-[var(--color-ink)]" title="{{ $site->server->name }}">
            <i class="fa-solid fa-arrow-left"></i> {{ $site->server->display_name }}
        </a>
    @else
        <a href="{{ route('sites.index') }}" class="text-[var(--color-ink-soft)] hover:text-[var(--color-ink)]">
            <i class="fa-solid fa-arrow-left"></i> Sites
        </a>
        @if ($site->isPressable())
            <span class="status-pill status-unknown ml-2" title="Hosted on Pressable — no server-level access (SSH, credentials) applies to this site.">
                <i class="fa-solid fa-cloud"></i> Pressable
            </span>
        @elseif ($site->isCustom())
            <span class="status-pill status-unknown ml-2" title="Custom hosting — standalone WordPress site managed purely via Clockwork Companion plugin.">
                <i class="fa-solid fa-plug"></i> Companion Only
            </span>
        @endif
    @endif
</div>

<div class="flex items-end justify-between flex-wrap gap-4 mb-6">
    <div class="min-w-0">
        <h1 class="display-heading text-3xl text-[var(--color-ink-strong)] mb-1 break-all">
            @if ($site->is_wordpress)
                <i class="fa-brands fa-wordpress text-[var(--color-brand)] mr-2"></i>
            @else
                <i class="fa-solid fa-globe text-[var(--color-ink-soft)] mr-2"></i>
            @endif
            <a href="https://{{ $site->domain }}"
               target="_blank"
               rel="noopener noreferrer"
               class="hover:text-[var(--color-brand)] transition-colors"
               title="Open {{ $site->domain }} in a new tab">
                {{ $site->domain }}<i class="fa-solid fa-arrow-up-right-from-square text-lg text-[var(--color-ink-soft)] ml-2 align-baseline"></i>
            </a>
        </h1>
        <p class="text-[var(--color-ink-muted)] text-sm font-data">
            {{ $site->wp_path ?: '—' }}
        </p>
    </div>

    <div class="flex items-center gap-2 flex-wrap">
        @php
            $cfDisplay = match ($site->cloudflare_state) {
                'proxied' => ['class' => 'status-cf', 'icon' => 'fa-cloud', 'label' => '', 'title' => 'Cloudflare proxy active'],
                'dns_only' => ['class' => 'status-yellow', 'icon' => 'fa-cloud', 'label' => 'CF DNS only', 'title' => 'On Cloudflare DNS but proxy is off — origin cert is user-facing'],
                'not_using' => ['class' => 'status-unknown', 'icon' => 'fa-cloud-slash', 'label' => 'No Cloudflare', 'title' => 'Not on Cloudflare'],
                default => null,
            };

            $ssoCapable = $site->companion_installed
                && is_array($site->companion_capabilities ?? null)
                && in_array('sso', $site->companion_capabilities, true);
            $ssoAdmins = is_array($site->companion_snapshot['admins']['admins'] ?? null)
                ? $site->companion_snapshot['admins']['admins']
                : [];
        @endphp
        @if ($site->is_inactive)
            <span class="status-pill status-unknown" title="{{ $site->inactive_reason ? 'Inactive: '.$site->inactive_reason : 'Marked inactive — excluded from Issues and routine-maintenance alerts.' }}">
                <i class="fa-solid fa-moon"></i>
                Inactive
            </span>
        @endif
        @if ($cfDisplay)
            <span class="status-pill {{ $cfDisplay['class'] }}" title="{{ $cfDisplay['title'] }}">
                <i class="fa-solid {{ $cfDisplay['icon'] }}"></i>
                @if ($cfDisplay['label'])
                    {{ $cfDisplay['label'] }}
                @endif
            </span>
        @endif
        <span class="status-pill {{ $sslMeta['class'] }}">
            <i class="fa-solid {{ $sslMeta['icon'] }}"></i>
            {{ $sslMeta['label'] }}
        </span>
        @if (\App\Models\Site::areCarePlansEnabled())
            @if ($site->care_plan_enabled)
                <span class="status-pill status-green" title="Updates and routine maintenance are included in this site's care plan.">
                    <i class="fa-solid fa-shield-heart"></i>
                    Care plan
                </span>
            @else
                <span class="status-pill status-unknown" title="Not on a care plan. Routine updates and maintenance are inactive for this site.">
                    <i class="fa-regular fa-circle"></i>
                    No care plan
                </span>
            @endif
        @endif

        @if ($ssoCapable && count($ssoAdmins) > 0)
            <form method="POST" action="{{ route('sites.companion.sso', $site) }}" target="_blank" class="inline">
                @csrf
                @if (count($ssoAdmins) > 1)
                    <select name="as"
                            onchange="this.form.submit()"
                            class="btn-pill-nav text-xs"
                            title="Pick which administrator to log in as. The link is one-time-use and expires in 60 seconds.">
                        <option value="" disabled selected>Log in as…</option>
                        @foreach ($ssoAdmins as $a)
                            <option value="{{ $a['login'] ?? '' }}">{{ $a['login'] ?? '?' }}</option>
                        @endforeach
                    </select>
                @else
                    <button type="submit" class="btn-pill-nav text-xs"
                            title="Open a one-time login URL in a new tab and land on /wp-admin already authenticated as {{ $ssoAdmins[0]['login'] ?? 'the admin' }}.">
                        <i class="fa-solid fa-right-to-bracket"></i> Log in as {{ $ssoAdmins[0]['login'] ?? 'admin' }}
                    </button>
                @endif
            </form>
        @endif
    </div>
</div>

@if (session('status'))
    <div class="card p-3 mb-4 status-green text-sm">
        <i class="fa-solid fa-circle-check"></i> {{ session('status') }}
    </div>
@endif
@if (session('ban_status'))
    <div class="card p-3 mb-4 status-green text-sm">
        <i class="fa-solid fa-circle-check"></i> {{ session('ban_status') }}
    </div>
@endif
@if (session('ban_error'))
    <div class="card p-3 mb-4 status-red text-sm">
        <i class="fa-solid fa-circle-xmark"></i> {{ session('ban_error') }}
    </div>
@endif
@if (session('status_error'))
    <div class="card p-3 mb-4 status-red text-sm">
        <i class="fa-solid fa-circle-xmark"></i> {{ session('status_error') }}
    </div>
@endif
