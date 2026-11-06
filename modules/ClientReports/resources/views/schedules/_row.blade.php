                    @php
                        $recips = array_values(array_filter((array) $s->recipients));
                        $eligible = $runnerClass::siteIsEligible($s->site);
                        $latest = $latestReports->get($s->id);
                    @endphp
                    <tr class="hover:bg-[var(--color-surface-alt)]/50 transition-colors {{ ! $s->is_enabled ? 'opacity-70' : '' }}">
                        <td class="px-4 py-3 align-top">
                            <a href="{{ route('sites.show', $s->site) }}" class="font-mono text-xs font-semibold text-[var(--color-ink-strong)] hover:text-[var(--color-brand)] break-all">{{ $s->site->domain }}</a>
                            <div class="mt-1 flex flex-wrap gap-1 items-center">
                                @if (! $eligible)
                                    <span class="cr-pill status-unknown" title="Reports are only sent for care-plan sites. Each month without one is skipped; reports resume the month after it's turned back on.">
                                        <i class="fa-solid fa-ban"></i>No care plan — won't send
                                    </span>
                                @elseif (\App\Models\Site::areCarePlansEnabled())
                                    <span class="cr-pill status-green"><i class="fa-solid fa-shield-heart"></i>Care plan</span>
                                @endif
                                @if ($s->site->client)
                                    <a href="{{ route('client-reports.clients.show', $s->site->client_id) }}" class="text-[11px] text-[var(--color-ink-muted)] hover:underline">{{ $s->site->client->company_name ?: $s->site->client->name }}</a>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap align-top">
                            @if ($s->delivery_mode === $modeDraft)
                                <span class="cr-pill status-yellow"><i class="fa-solid fa-file-pen"></i>Drafts for review</span>
                            @else
                                <span class="cr-pill status-green"><i class="fa-solid fa-paper-plane"></i>Auto-Send</span>
                            @endif
                            <div class="text-[10px] text-[var(--color-ink-muted)] mt-1">
                                {{ ucfirst($s->frequency) }} · {{ $s->template?->name ?? 'Default (all sections)' }}
                            </div>
                        </td>
                        <td class="px-4 py-3 text-[var(--color-ink-soft)] align-top">
                            @if ($recips !== [])
                                <div class="max-w-[16rem] break-all text-[11px]">{{ implode(', ', $recips) }}</div>
                            @else
                                <span class="cr-pill status-yellow"><i class="fa-solid fa-at"></i>No recipients</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap align-top">
                            @if ($s->next_run_at)
                                <div class="font-medium text-[var(--color-ink-strong)]">{{ $s->next_run_at->format('M j, Y') }}</div>
                                <div class="text-[10px] text-[var(--color-ink-muted)]">{{ $s->last_sent_at ? 'Last sent '.$s->last_sent_at->format('M j') : 'Never sent yet' }}</div>
                            @else
                                <span class="text-[var(--color-ink-muted)]">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 align-top">
                            @if ($latest)
                                @php($latestState = $stateClass::of($latest))
                                <a href="{{ route('client-reports.show', $latest) }}" class="block hover:opacity-80">
                                    <div class="text-[11px] font-semibold text-[var(--color-ink-strong)] mb-0.5">{{ $latest->period_start->format('M Y') }}</div>
                                    @include('client-reports::partials.state-pill', ['state' => $latestState])
                                </a>
                            @else
                                <span class="text-[var(--color-ink-muted)]">None yet</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap align-top">
                            <form method="POST" action="{{ route('client-reports.schedules.toggle', $s) }}" class="inline">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="cursor-pointer" title="{{ $s->is_enabled ? 'Pause this schedule' : 'Resume this schedule' }}">
                                    @if ($s->is_enabled)
                                        <span class="status-pill status-green text-xs hover:opacity-80"><span class="status-dot"></span> Active</span>
                                    @else
                                        <span class="status-pill status-red text-xs hover:opacity-80"><span class="status-dot"></span> Paused</span>
                                    @endif
                                </button>
                            </form>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap align-top">
                            <div class="flex items-center justify-end gap-1.5">
                                <form method="POST" action="{{ route('client-reports.schedules.send-now', $s) }}" class="inline"
                                      data-confirm="Run the schedule for {{ $s->site->domain }} now?"
                                      data-confirm-details="{{ ! \Modules\ClientReports\Services\ScheduledReportRunner::sendsByItself($s) ? 'It compiles last month\'s report and leaves it waiting for approval. Nothing is sent.' : ($s->last_sent_at === null ? 'It compiles last month\'s report. As this schedule\'s first report it is held for review, not sent.' : 'It compiles last month\'s report and, if nothing needs review, emails it to '.(implode(', ', $recips) ?: 'its recipients').' straight away.') }}"
                                      data-confirm-btn="Run now" data-confirm-variant="warning">
                                    @csrf
                                    <button type="submit" class="cr-btn" title="Compile last month's report now">
                                        <i class="fa-solid fa-play"></i>Run
                                    </button>
                                </form>
                                <a href="{{ route('client-reports.schedules.edit', $s) }}" class="cr-btn" title="Edit schedule">
                                    <i class="fa-solid fa-pen-to-square"></i>Edit
                                </a>
                                <form method="POST" action="{{ route('client-reports.schedules.destroy', $s) }}" class="inline"
                                      data-confirm="Delete the report schedule for {{ $s->site->domain }}?" data-confirm-details="Its past reports are kept. No more reports are compiled for this site." data-confirm-variant="danger">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="cr-btn text-[var(--color-status-red)]" title="Delete schedule" aria-label="Delete schedule">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
