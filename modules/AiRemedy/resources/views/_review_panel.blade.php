{{--
    Copilot approval panel. Include inside an Alpine scope and pass:
      $reviewExpr — JS expression for the review payload (RunApprovalPolicy::review())
      $runIdExpr  — JS expression for the run id
    Requires ai-remedy::_review_script to be included once on the page, outside any <template>.
--}}
<div x-data="aiRemedyReview({{ $reviewExpr }}, {{ $runIdExpr }})" class="space-y-3" id="review">
    <div class="flex items-center justify-between gap-2 flex-wrap">
        <h4 class="text-xs font-bold uppercase tracking-wider text-[var(--color-ink-muted)] flex items-center gap-1.5">
            <i class="fa-solid fa-list-check text-[10px]"></i>
            <span>Review &amp; run</span>
        </h4>
        <span class="text-[11px] text-[var(--color-ink-muted)]" x-show="review.can_execute && !done">
            <span x-text="selectedItems.length"></span> of <span x-text="items.length"></span> selected
        </span>
    </div>

    <template x-if="!review.executable && review.blocked_reason">
        <div class="p-3 rounded-lg border border-amber-500/30 bg-amber-500/10 text-amber-900 dark:text-amber-200 text-[11px] leading-relaxed flex items-start gap-2">
            <i class="fa-solid fa-circle-info mt-0.5"></i>
            <span x-text="review.blocked_reason"></span>
        </div>
    </template>

    <template x-if="review.executable && !review.can_execute">
        <div class="p-3 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] text-[11px] text-[var(--color-ink-muted)]">
            Only admins can run fixes. Ask an admin to review this incident.
        </div>
    </template>

    <div class="rounded-xl bg-slate-950 border border-slate-800 text-slate-100 overflow-hidden font-mono text-xs divide-y divide-slate-800/80">
        <template x-for="item in items" :key="item.index">
            <div class="p-3 space-y-1.5" :class="!item.allowed ? 'opacity-60' : ''">
                <div class="flex items-start gap-2.5">
                    <input type="checkbox"
                           class="mt-0.5 rounded border-slate-600 bg-slate-900 text-emerald-500 focus:ring-0 cursor-pointer disabled:cursor-not-allowed"
                           x-model="item.selected"
                           :disabled="!item.allowed || !review.can_execute || done || executing"
                           :aria-label="'Run: ' + item.original">
                    <span class="text-emerald-400 font-bold select-none">$</span>
                    <template x-if="item.allowed && review.can_execute && !done">
                        <input type="text"
                               x-model="item.command"
                               class="bg-transparent border-none p-0 text-slate-100 focus:text-white focus:outline-none focus:ring-0 flex-1 min-w-0 font-mono text-xs tracking-wide">
                    </template>
                    <template x-if="!(item.allowed && review.can_execute && !done)">
                        <span class="flex-1 min-w-0 break-all" :class="!item.allowed ? 'line-through text-slate-400' : 'text-slate-100'" x-text="item.command"></span>
                    </template>
                </div>
                <div class="flex items-center gap-2 flex-wrap pl-6 font-sans">
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded font-data text-[10px] font-semibold border" :class="tierClass(item.tier)" x-text="tierLabel(item.tier)"></span>
                    <span class="text-[10px] text-slate-400" x-show="item.allowed"
                          x-text="item.route === 'spinupwp_api' && !isEdited(item) ? 'via SpinupWP API' : 'via SSH'"></span>
                    <template x-if="isEdited(item)">
                        <span class="text-[10px] text-sky-300">edited · safety re-checked when you run it</span>
                    </template>
                    <template x-if="!item.allowed">
                        <span class="text-[10px] text-rose-300" x-text="item.reason"></span>
                    </template>
                    <template x-if="!item.allowed && item.suggestion && review.can_execute && !done">
                        <button type="button"
                                @click="useSuggestion(item)"
                                class="text-[10px] font-semibold text-sky-300 hover:text-sky-200 underline cursor-pointer">
                            Use <span class="font-mono" x-text="item.suggestion"></span>
                        </button>
                    </template>
                    <template x-if="done && resultFor(item)">
                        <span class="text-[10px] font-data"
                              :class="resultFor(item).not_run ? 'text-slate-400' : (resultFor(item).exit_status === 0 ? 'text-emerald-400' : 'text-rose-400')"
                              x-text="resultFor(item).not_run ? 'not run (an earlier command failed)' : ('exit ' + resultFor(item).exit_status)"></span>
                    </template>
                    <template x-if="done && !item.selected && results.length > 0">
                        <span class="text-[10px] text-slate-400">skipped</span>
                    </template>
                </div>
            </div>
        </template>
    </div>

    <template x-if="message">
        <div class="p-3 rounded-lg border text-[11px] leading-relaxed"
             :class="ok ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-800 dark:text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300'">
            <span x-text="message"></span>
        </div>
    </template>

    <template x-if="needsSudo()">
        <div class="rounded-lg bg-rose-500/15 border border-rose-500/30 p-2.5 text-[11px] text-rose-900 dark:text-rose-200 flex items-start gap-2 leading-relaxed">
            <i class="fa-solid fa-key mt-0.5 text-xs shrink-0"></i>
            <div>
                <strong>sudo needs a password:</strong> save this server's SSH/sudo password in its credentials (Servers → the server → SSH credentials) so AiRemedy can use it, or allow the SSH user passwordless sudo in <code class="font-mono">/etc/sudoers</code>. If a password is already saved, it may have been changed on the server.
            </div>
        </div>
    </template>

    <template x-if="output">
        <pre class="bg-slate-950 border border-slate-800 text-slate-200 p-3.5 rounded-xl font-mono text-[11px] max-h-60 overflow-auto whitespace-pre-wrap break-all" x-text="output"></pre>
    </template>

    <div class="flex items-center justify-end gap-2" x-show="review.can_execute && !done">
        <button type="button"
                @click="run()"
                :disabled="!canRun"
                class="btn-primary text-xs py-2 px-4 font-semibold inline-flex items-center gap-2 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">
            <i class="fa-solid" :class="executing ? 'fa-circle-notch fa-spin' : 'fa-play text-[10px]'"></i>
            <span x-text="executing ? 'Running via SSH…' : ('Run ' + selectedItems.length + ' selected command' + (selectedItems.length === 1 ? '' : 's'))"></span>
        </button>
    </div>
</div>
