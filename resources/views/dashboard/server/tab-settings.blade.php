{{-- Connection & provisioning + auto-ban toggles. The "ignore" action lives in the
     header banner since it's mutually exclusive with most of what's on this tab. --}}

<div class="card mb-6 divide-y divide-[var(--color-border-light)]">
    {{-- SSH credentials --}}
    <div class="px-5 py-4 flex items-start justify-between gap-4 flex-wrap">
        <div>
            <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">SSH credentials</div>
            <div class="flex items-center gap-3 mt-1">
                @if ($server->ssh_password)
                    <span class="status-pill status-green">
                        <i class="fa-solid fa-key"></i> Password set
                    </span>
                @else
                    <span class="status-pill status-yellow">
                        <i class="fa-solid fa-circle-exclamation"></i> No password
                    </span>
                @endif
                <span class="text-sm font-data text-[var(--color-ink-muted)]">{{ $server->ssh_user . '@' . $server->hostname }}:{{ $server->ssh_port }}</span>
            </div>
            <div id="ssh-test-result" class="mt-3 hidden text-sm"></div>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" id="ssh-test-btn"
                    data-test-url="{{ route('servers.test', $server) }}"
                    class="btn-pill-nav">
                <i class="fa-solid fa-plug"></i> Test SSH
            </button>
            <a href="{{ route('servers.credentials.edit', $server) }}" class="btn-primary">
                <i class="fa-solid fa-pencil"></i> Edit credentials
            </a>
        </div>
    </div>

    @unless ($server->is_ignored)
        {{-- fail2ban jail --}}
        <div class="px-5 py-4">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">fail2ban jail</div>
                    <div class="flex items-center gap-3 mt-1">
                        @if ($server->clockwork_jail_provisioned_at)
                            <span class="status-pill status-green">
                                <i class="fa-solid fa-shield-halved"></i> Provisioned
                            </span>
                            <span class="text-sm text-[var(--color-ink-muted)]">
                                {{ $server->clockwork_jail_provisioned_at->diffForHumans() }}
                            </span>
                        @else
                            <span class="status-pill status-yellow">
                                <i class="fa-solid fa-circle-exclamation"></i> Not provisioned
                            </span>
                            <span class="text-sm text-[var(--color-ink-muted)]">Install fail2ban + the <code class="font-data text-xs">clockwork</code> jail to enable banning.</span>
                        @endif
                    </div>
                </div>
                <button type="button" id="provision-btn"
                        data-url="{{ route('servers.provision.fail2ban', $server) }}"
                        class="btn-primary">
                    <i class="fa-solid fa-shield-halved"></i>
                    {{ $server->clockwork_jail_provisioned_at ? 'Re-verify' : 'Provision fail2ban' }}
                </button>
            </div>

            <div id="provision-result" class="mt-4 hidden">
                <div id="provision-message" class="text-sm mb-2"></div>
                <details>
                    <summary class="text-xs text-[var(--color-ink-muted)] cursor-pointer">Show output</summary>
                    <pre id="provision-output" class="mt-2 bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] rounded-md p-3 font-data text-xs text-[var(--color-ink-muted)] overflow-x-auto whitespace-pre-wrap max-h-96"></pre>
                </details>
            </div>

            @if ($server->last_provision_log)
                <details class="mt-3">
                    <summary class="text-xs text-[var(--color-ink-soft)] cursor-pointer">Last provisioning output ({{ $server->updated_at->diffForHumans() }})</summary>
                    <pre class="mt-2 bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] rounded-md p-3 font-data text-xs text-[var(--color-ink-muted)] overflow-x-auto whitespace-pre-wrap max-h-96">{{ $server->last_provision_log }}</pre>
                </details>
            @endif
        </div>

        {{-- LLAR auto-ban --}}
        <div class="px-5 py-4">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">LLAR auto-ban</div>
                    <div class="flex items-center gap-3 mt-1">
                        @if ($server->auto_ban_llar)
                            <span class="status-pill status-green">
                                <i class="fa-solid fa-bolt"></i> Auto-ban on
                            </span>
                            <span class="text-sm text-[var(--color-ink-muted)]">
                                Lockouts on this server are banned via fail2ban automatically.
                            </span>
                        @else
                            <span class="status-pill status-unknown">
                                <i class="fa-solid fa-hand"></i> Manual review
                            </span>
                            <span class="text-sm text-[var(--color-ink-muted)]">
                                Lockouts on this server go to the <a href="{{ route('review-queue.index') }}" class="underline">review queue</a> for approval.
                            </span>
                        @endif
                    </div>
                    @if ($server->last_llar_pull_at)
                        <div class="text-xs text-[var(--color-ink-soft)] mt-2">
                            Last LLAR pull: {{ $server->last_llar_pull_at->diffForHumans() }}
                        </div>
                    @endif
                </div>
                <form method="POST" action="{{ route('servers.toggleAutoBanLlar', $server) }}">
                    @csrf
                    <button type="submit" class="btn-pill-nav"
                            onclick="return confirm('{{ $server->auto_ban_llar ? 'Switch back to manual review for LLAR lockouts?' : 'Auto-ban LLAR lockouts on ' . $server->name . '? IPs already locked out by Limit Login Attempts Reloaded will be banned via fail2ban as soon as they are seen.' }}')">
                        <i class="fa-solid {{ $server->auto_ban_llar ? 'fa-toggle-on' : 'fa-toggle-off' }}"></i>
                        {{ $server->auto_ban_llar ? 'Disable auto-ban' : 'Enable auto-ban' }}
                    </button>
                </form>
            </div>
        </div>

        {{-- Wordfence auto-ban --}}
        <div class="px-5 py-4">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">Wordfence auto-ban</div>
                    <div class="flex items-center gap-3 mt-1">
                        @if ($server->auto_ban_wordfence)
                            <span class="status-pill status-green">
                                <i class="fa-solid fa-bolt"></i> Auto-ban on
                            </span>
                            <span class="text-sm text-[var(--color-ink-muted)]">
                                Wordfence blocks on this server are banned via fail2ban automatically.
                            </span>
                        @else
                            <span class="status-pill status-unknown">
                                <i class="fa-solid fa-hand"></i> Manual review
                            </span>
                            <span class="text-sm text-[var(--color-ink-muted)]">
                                Wordfence blocks go to the <a href="{{ route('review-queue.index') }}" class="underline">review queue</a> for approval.
                            </span>
                        @endif
                    </div>
                    @if ($server->last_wordfence_pull_at)
                        <div class="text-xs text-[var(--color-ink-soft)] mt-2">
                            Last Wordfence pull: {{ $server->last_wordfence_pull_at->diffForHumans() }}
                        </div>
                    @endif
                </div>
                <form method="POST" action="{{ route('servers.toggleAutoBanWordfence', $server) }}">
                    @csrf
                    <button type="submit" class="btn-pill-nav"
                            onclick="return confirm('{{ $server->auto_ban_wordfence ? 'Switch back to manual review for Wordfence blocks?' : 'Auto-ban Wordfence blocks on ' . $server->name . '? IPs blocked by Wordfence will be banned via fail2ban as soon as they are seen.' }}')">
                        <i class="fa-solid {{ $server->auto_ban_wordfence ? 'fa-toggle-on' : 'fa-toggle-off' }}"></i>
                        {{ $server->auto_ban_wordfence ? 'Disable auto-ban' : 'Enable auto-ban' }}
                    </button>
                </form>
            </div>
        </div>

        {{-- Ignore --}}
        <div class="px-5 py-4">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">Monitoring</div>
                    <div class="text-sm text-[var(--color-ink-muted)] mt-1">
                        Stop polling this server, hide it from health stats, and exclude it from the issues count.
                    </div>
                </div>
                <form method="POST" action="{{ route('servers.toggleIgnore', $server) }}">
                    @csrf
                    <button type="submit" class="btn-pill-nav"
                            onclick="return confirm('Ignore {{ $server->name }}? Polling stops; existing data is kept.')">
                        <i class="fa-solid fa-eye-slash"></i> Ignore this server
                    </button>
                </form>
            </div>
        </div>
    @endunless

    {{-- Danger zone — only relevant once a server is decommissioned at the
         infra level. Behind a typed-name confirmation because the cascade
         takes sites + metrics + ban history with it. --}}
    <div class="px-5 py-4 border-t-4 border-[var(--color-status-red)]" style="background-color: rgba(194, 46, 46, 0.04);">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div class="flex-1 min-w-0">
                <div class="text-xs uppercase tracking-wide text-[var(--color-status-red)] mb-1">Danger zone</div>
                <div class="text-sm text-[var(--color-ink-strong)] font-medium mb-1">Remove this server from Clockwork</div>
                <div class="text-xs text-[var(--color-ink-muted)] mb-1">
                    Use this only when the server has been decommissioned at the infrastructure level (DigitalOcean droplet destroyed, SpinupWP server deleted) and you want Clockwork to stop tracking it entirely.
                </div>
                <div class="text-xs text-[var(--color-ink-muted)]">
                    Cascades: <strong>{{ $server->sites()->count() }} site(s)</strong>, all server metrics, all blocked-IP records, all tag assignments. <strong class="text-[var(--color-status-red)]">Not undoable.</strong>
                </div>
                <div class="text-xs text-[var(--color-ink-soft)] mt-2">
                    For temporary 'don't poll' status, use the Ignore button above instead.
                </div>
            </div>
            <form method="POST" action="{{ route('servers.destroy', $server) }}"
                  onsubmit="
                      var name = prompt('Type the server name to confirm deletion:\n\n{{ $server->name }}');
                      if (name === null) return false;
                      this.querySelector('input[name=confirm_name]').value = name;
                      return confirm('FINAL CONFIRMATION: permanently remove {{ $server->name }} from Clockwork? This cascade-deletes {{ $server->sites()->count() }} site(s) and all related data.');
                  ">
                @csrf
                @method('DELETE')
                <input type="hidden" name="confirm_name" value="">
                <button type="submit" class="btn-pill-nav" style="color: var(--color-status-red); border-color: var(--color-status-red);">
                    <i class="fa-solid fa-triangle-exclamation"></i> Remove from Clockwork
                </button>
            </form>
        </div>
    </div>
</div>

<script>
    (function () {
        const provisionBtn = document.getElementById('provision-btn');
        const provisionResult = document.getElementById('provision-result');
        const provisionMessage = document.getElementById('provision-message');
        const provisionOutput = document.getElementById('provision-output');

        if (provisionBtn && provisionResult) {
            provisionBtn.addEventListener('click', async () => {
                const original = provisionBtn.innerHTML;
                provisionBtn.disabled = true;
                provisionBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Provisioning… (this can take 30–60s)';
                provisionResult.classList.remove('hidden');
                provisionMessage.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Connecting…';
                provisionOutput.textContent = '';

                try {
                    const res = await fetch(provisionBtn.dataset.url, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'Accept': 'application/json',
                        },
                    });
                    const data = await res.json();

                    if (data.ok) {
                        provisionMessage.innerHTML = '<i class="fa-solid fa-circle-check" style="color: var(--color-status-green)"></i> ' + data.message + ' <span class="text-[var(--color-ink-soft)]">Refreshing…</span>';
                        provisionOutput.textContent = data.output || '(no output)';
                        // Reload so the status pill, button label, and last_provision_log
                        // block reflect the new state. Brief delay so the success message
                        // is visible long enough to read.
                        setTimeout(() => window.location.reload(), 900);
                        return;
                    }

                    provisionMessage.innerHTML = '<i class="fa-solid fa-circle-xmark" style="color: var(--color-status-red)"></i> ' + data.message;
                    provisionOutput.textContent = data.output || '(no output)';
                } catch (e) {
                    provisionMessage.innerHTML = '<i class="fa-solid fa-circle-xmark" style="color: var(--color-status-red)"></i> ' + e.message;
                }

                provisionBtn.disabled = false;
                provisionBtn.innerHTML = original;
            });
        }
    })();

    (function () {
        const btn = document.getElementById('ssh-test-btn');
        const result = document.getElementById('ssh-test-result');
        if (!btn || !result) return;

        btn.addEventListener('click', async () => {
            const original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Testing…';
            result.className = 'mt-3 text-sm';
            result.textContent = '';

            try {
                const res = await fetch(btn.dataset.testUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                });
                const data = await res.json();

                if (data.ok) {
                    result.innerHTML = '<i class="fa-solid fa-circle-check" style="color: var(--color-status-green)"></i> ' + data.message;
                } else {
                    result.innerHTML = '<i class="fa-solid fa-circle-xmark" style="color: var(--color-status-red)"></i> ' + data.message;
                }
            } catch (e) {
                result.innerHTML = '<i class="fa-solid fa-circle-xmark" style="color: var(--color-status-red)"></i> ' + e.message;
            } finally {
                btn.disabled = false;
                btn.innerHTML = original;
            }
        });
    })();
</script>
