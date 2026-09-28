<div id="cw-feedback-overlay-root" x-data="feedbackOverlay()" x-cloak class="cw-feedback-no-intercept select-none">
    <style>
        .cw-feedback-target-pulse {
            outline: 2px dashed var(--color-brand) !important;
            outline-offset: 3px !important;
            animation: cw-feedback-pulse 1.5s infinite alternate ease-in-out;
        }
        @keyframes cw-feedback-pulse {
            0% { outline-color: var(--color-brand); }
            100% { outline-color: rgba(99, 102, 241, 0.2); }
        }
    </style>
    {{-- 1. Context Menu on Right Click --}}
    <div id="cw-feedback-context-menu"
         x-show="contextMenu.visible"
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-100"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="fixed z-50 min-w-64 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-2xl p-2 text-xs"
         :style="'left: ' + contextMenu.x + 'px; top: ' + contextMenu.y + 'px;'"
         @click.stop>
        <button type="button"
                @click="openNewFeedbackModal()"
                class="w-full flex items-start gap-2.5 p-2 rounded-lg hover:bg-[var(--color-brand)] hover:text-white text-[var(--color-ink-strong)] text-left transition-colors cursor-pointer group">
            <div class="w-6 h-6 rounded-md bg-[var(--color-brand)]/15 group-hover:bg-white/20 text-[var(--color-brand)] group-hover:text-white flex items-center justify-center shrink-0 mt-0.5">
                <i class="fa-solid fa-comment-dots text-xs"></i>
            </div>
            <div class="overflow-hidden">
                <div class="font-semibold text-xs leading-tight">Leave Feedback Here</div>
                <div class="text-[10px] opacity-75 font-data truncate mt-0.5" x-text="'&lt;' + contextMenu.tag + '&gt; ' + (contextMenu.text || contextMenu.selector)"></div>
            </div>
        </button>

        <div class="mt-1 pt-1 border-t border-[var(--color-border-light)] flex items-center justify-between text-[10px] text-[var(--color-ink-muted)] px-2">
            <span>Shift + Right-Click for browser menu</span>
            <button type="button" @click="contextMenu.visible = false" class="hover:text-[var(--color-ink)] cursor-pointer">Esc</button>
        </div>
    </div>

    {{-- 2. Visual Pin Badges on Page --}}
    <template x-if="pinsVisible">
        <div>
            <template x-for="pin in pins" :key="pin.id">
                <div class="absolute z-40 cursor-pointer transition-transform hover:scale-125"
                     :style="'left: ' + (pin.screenX || 0) + 'px; top: ' + (pin.screenY || 0) + 'px;'"
                     @click.stop="openPin(pin)"
                     :title="pin.title + ' (' + pin.type_label + ')'">
                    <div class="w-6 h-6 rounded-full shadow-lg flex items-center justify-center font-data font-bold text-[11px] ring-2 ring-white dark:ring-black text-white"
                         :class="{
                             'bg-amber-500': pin.status === 'open',
                             'bg-blue-500': pin.status === 'in_progress',
                             'bg-emerald-500': pin.status === 'resolved',
                             'bg-neutral-500': pin.status === 'dismissed'
                         }">
                        <span x-text="pin.number"></span>
                    </div>
                </div>
            </template>
        </div>
    </template>

    {{-- 3. Atarim-Style Thread Popover / Drawer --}}
    <div x-show="activePin"
         x-transition:enter="transition ease-out duration-250"
         x-transition:enter-start="opacity-0 translate-y-4 scale-95"
         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0 scale-100"
         x-transition:leave-end="opacity-0 translate-y-4 scale-95"
         class="fixed bottom-6 right-6 z-50 w-96 max-w-[calc(100vw-2rem)] max-h-[85vh] rounded-2xl border-2 border-[var(--color-brand)]/40 bg-[var(--color-surface)] shadow-2xl flex flex-col overflow-hidden text-xs"
         @click.stop>
        {{-- Thread Header --}}
        <div class="px-4 py-3 bg-[var(--color-surface-alt)]/90 border-b border-[var(--color-border-light)] flex items-center justify-between gap-2 shrink-0">
            <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold font-data bg-[var(--color-brand)] text-white" x-text="'Pin #' + activePin?.number"></span>
                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold font-data" :class="activePin?.type_class" x-text="activePin?.type_label"></span>
            </div>
            <div class="flex items-center gap-1.5">
                <button type="button"
                        @click="copyClaudePrompt()"
                        class="btn-pill-nav text-[11px] py-0.5 px-2 text-[var(--color-brand)] hover:bg-[var(--color-brand)] hover:text-white flex items-center gap-1 cursor-pointer"
                        title="Copy full Claude/Antigravity prompt to clipboard">
                    <i class="fa-solid fa-wand-magic-sparkles text-[10px]"></i> Prompt
                </button>
                <button type="button" @click="closeActivePin()" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] p-1 cursor-pointer" aria-label="Close thread">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>
        </div>

        {{-- Thread Content (Scrollable) --}}
        <div class="p-4 overflow-y-auto space-y-4 flex-1">
            {{-- Target Element Snippet --}}
            <div class="p-2 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] flex items-start gap-2">
                <i class="fa-solid fa-crosshairs text-[var(--color-brand)] text-[10px] mt-0.5 shrink-0"></i>
                <div class="overflow-hidden">
                    <span class="text-[10px] text-[var(--color-ink-muted)] uppercase tracking-wider font-semibold">Element:</span>
                    <p class="font-data text-[11px] text-[var(--color-ink-strong)] truncate mt-0.5" x-text="activePin?.element_tag + (activePin?.element_text ? ': &quot;' + activePin.element_text + '&quot;' : (activePin?.selector || ''))"></p>
                </div>
            </div>

            {{-- Initial Note --}}
            <div>
                <div class="flex items-center justify-between mb-1">
                    <div class="flex items-center gap-2">
                        <template x-if="activePin?.author?.avatar">
                            <img :src="activePin.author.avatar" alt="" class="w-5 h-5 rounded-full">
                        </template>
                        <span class="font-semibold text-[var(--color-ink-strong)]" x-text="activePin?.author?.name"></span>
                    </div>
                    <span class="text-[10px] text-[var(--color-ink-muted)]" x-text="activePin?.created_at"></span>
                </div>
                <h4 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] mb-1" x-text="activePin?.title"></h4>
                <div class="p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60 border border-[var(--color-border-light)] text-[var(--color-ink)] leading-relaxed whitespace-pre-wrap" x-text="activePin?.content"></div>
            </div>

            {{-- Status Switcher --}}
            <div class="flex items-center justify-between pt-2 border-t border-[var(--color-border-light)]">
                <span class="text-[11px] text-[var(--color-ink-muted)] font-medium">Status:</span>
                <select :value="activePin?.status"
                        @change="updateStatus($event.target.value)"
                        class="text-xs rounded border border-[var(--color-border)] bg-[var(--color-surface)] py-1 px-2 text-[var(--color-ink-strong)] font-data cursor-pointer focus:outline-none focus:border-[var(--color-brand)]">
                    <option value="open">Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="resolved">Resolved</option>
                    <option value="dismissed">Dismissed</option>
                </select>
            </div>

            {{-- Discussion Replies --}}
            <div class="space-y-3 pt-2 border-t border-[var(--color-border-light)]">
                <div class="text-[10px] font-bold uppercase tracking-wider text-[var(--color-ink-muted)] flex items-center justify-between">
                    <span>Discussion</span>
                    <span x-text="(activePin?.comments_count || 0) + ' replies'"></span>
                </div>

                <template x-for="comment in (activePin?.comments || [])" :key="comment.id">
                    <div class="p-2.5 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] space-y-1">
                        <div class="flex items-center justify-between text-[10px]">
                            <div class="flex items-center gap-1.5">
                                <template x-if="comment.author?.avatar">
                                    <img :src="comment.author.avatar" alt="" class="w-4 h-4 rounded-full">
                                </template>
                                <span class="font-semibold text-[var(--color-ink-strong)]" x-text="comment.author?.name"></span>
                            </div>
                            <span class="text-[var(--color-ink-muted)]" x-text="comment.created_at"></span>
                        </div>
                        <p class="text-[var(--color-ink)] text-xs whitespace-pre-wrap" x-text="comment.content"></p>
                    </div>
                </template>
            </div>
        </div>

        {{-- Reply Form --}}
        <div class="p-3 bg-[var(--color-surface-alt)]/60 border-t border-[var(--color-border-light)] shrink-0">
            <form @submit.prevent="submitReply()" class="space-y-2">
                <textarea
                    x-model="replyContent"
                    @keydown.cmd.enter.prevent="submitReply()"
                    @keydown.ctrl.enter.prevent="submitReply()"
                    placeholder="Write a reply… (Cmd+Enter to send)"
                    rows="2"
                    class="w-full text-xs rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-2 text-[var(--color-ink-strong)] focus:outline-none focus:border-[var(--color-brand)] resize-none"
                ></textarea>
                <div class="flex items-center justify-between">
                    <a :href="'/feedback?q=' + encodeURIComponent(activePin?.title || '')" class="text-[10px] text-[var(--color-brand)] hover:underline flex items-center gap-1">
                        <i class="fa-solid fa-list-check"></i> View in Backlog
                    </a>
                    <button type="submit"
                            :disabled="!replyContent.trim() || replySubmitting"
                            class="btn-primary text-xs py-1 px-3 disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer">
                        <span x-text="replySubmitting ? 'Sending…' : 'Reply'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- 4. New Feedback Creation Modal --}}
    <div x-show="newModalOpen"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs text-xs"
         @click.self="closeNewFeedbackModal()">
        <div class="card p-6 max-w-md w-full shadow-2xl relative bg-[var(--color-surface)] border border-[var(--color-border)] space-y-4"
             @click.stop>
            <div class="flex items-start justify-between">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-full bg-[var(--color-brand)]/15 text-[var(--color-brand)] flex items-center justify-center shrink-0">
                        <i class="fa-solid fa-comment-dots text-sm"></i>
                    </div>
                    <div>
                        <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)]">New Feedback Note</h3>
                        <p class="text-[10px] text-[var(--color-ink-muted)]">Attach an in-app note to this element for the team & Claude.</p>
                    </div>
                </div>
                <button type="button" @click="closeNewFeedbackModal()" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] cursor-pointer" aria-label="Close modal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            {{-- Element context info --}}
            <div class="p-2 rounded bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] text-[11px] text-[var(--color-ink-muted)]">
                <span class="font-semibold text-[var(--color-ink-strong)]">Target:</span>
                <code class="font-data text-[10px]" x-text="'&lt;' + contextMenu.tag + '&gt; ' + (contextMenu.text ? '&quot;' + contextMenu.text + '&quot;' : contextMenu.selector)"></code>
            </div>

            <form @submit.prevent="submitNewFeedback()" class="space-y-3">
                <div>
                    <label class="block text-[11px] font-semibold text-[var(--color-ink-strong)] mb-1">Category</label>
                    <div class="grid grid-cols-4 gap-1.5 text-center">
                        <label class="p-1.5 rounded border border-[var(--color-border-light)] cursor-pointer hover:border-[var(--color-brand)]"
                               :class="newForm.type === 'bug' ? 'bg-rose-500/15 border-rose-500 text-rose-600 font-bold' : ''">
                            <input type="radio" value="bug" x-model="newForm.type" class="sr-only">
                            <div>🐛 Bug</div>
                        </label>
                        <label class="p-1.5 rounded border border-[var(--color-border-light)] cursor-pointer hover:border-[var(--color-brand)]"
                               :class="newForm.type === 'tweak' ? 'bg-amber-500/15 border-amber-500 text-amber-600 font-bold' : ''">
                            <input type="radio" value="tweak" x-model="newForm.type" class="sr-only">
                            <div>✨ Tweak</div>
                        </label>
                        <label class="p-1.5 rounded border border-[var(--color-border-light)] cursor-pointer hover:border-[var(--color-brand)]"
                               :class="newForm.type === 'feature' ? 'bg-purple-500/15 border-purple-500 text-purple-600 font-bold' : ''">
                            <input type="radio" value="feature" x-model="newForm.type" class="sr-only">
                            <div>💡 Idea</div>
                        </label>
                        <label class="p-1.5 rounded border border-[var(--color-border-light)] cursor-pointer hover:border-[var(--color-brand)]"
                               :class="newForm.type === 'copy' ? 'bg-sky-500/15 border-sky-500 text-sky-600 font-bold' : ''">
                            <input type="radio" value="copy" x-model="newForm.type" class="sr-only">
                            <div>✍️ Copy</div>
                        </label>
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-[var(--color-ink-strong)] mb-1">Title / Short Summary</label>
                    <input type="text"
                           x-model="newForm.title"
                           placeholder="e.g. Export CSV button missing"
                           required
                           class="w-full text-xs rounded border border-[var(--color-border)] bg-[var(--color-surface)] p-2 text-[var(--color-ink-strong)] focus:outline-none focus:border-[var(--color-brand)]">
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-[var(--color-ink-strong)] mb-1">Description & Details</label>
                    <textarea
                        x-model="newForm.content"
                        placeholder="Explain what is needed, expected behavior, or steps to reproduce…"
                        rows="3"
                        required
                        class="w-full text-xs rounded border border-[var(--color-border)] bg-[var(--color-surface)] p-2 text-[var(--color-ink-strong)] focus:outline-none focus:border-[var(--color-brand)] resize-none"
                    ></textarea>
                </div>

                <div class="flex items-center justify-end gap-2 pt-2 border-t border-[var(--color-border-light)]">
                    <button type="button" @click="closeNewFeedbackModal()" class="btn-pill-nav text-xs cursor-pointer">
                        Cancel
                    </button>
                    <button type="submit"
                            :disabled="newForm.submitting || !newForm.title.trim() || !newForm.content.trim()"
                            class="btn-primary text-xs flex items-center gap-1.5 cursor-pointer disabled:opacity-50">
                        <i class="fa-solid fa-thumbtack text-[10px]"></i>
                        <span x-text="newForm.submitting ? 'Saving…' : 'Drop Pin & Save'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- 5. Floating Bottom-Right Toolbar Pill --}}
    <div class="fixed bottom-4 right-4 z-40 flex items-center gap-2">
        <template x-if="!minimized">
            <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-full border border-[var(--color-border)] bg-[var(--color-surface)]/95 backdrop-blur-md shadow-xl text-xs">
                <a href="{{ route('feedback.index') }}"
                   class="font-semibold text-[var(--color-ink-strong)] hover:text-[var(--color-brand)] flex items-center gap-1.5 mr-1"
                   title="Open Feedback Backlog Working List">
                    <span class="w-2 h-2 rounded-full bg-[var(--color-brand)]"></span>
                    <span>Feedback</span>
                    <span class="px-1.5 py-0.2 rounded-full bg-[var(--color-brand)]/15 text-[var(--color-brand)] font-data font-bold text-[10px]" x-text="pins.length"></span>
                </a>

                <div class="w-px h-3.5 bg-[var(--color-border-light)]"></div>

                <button type="button"
                        @click="togglePins()"
                        :class="pinsVisible ? 'text-[var(--color-brand)] font-medium' : 'text-[var(--color-ink-muted)]'"
                        class="px-2 py-0.5 rounded hover:bg-[var(--color-surface-alt)] transition-colors cursor-pointer flex items-center gap-1"
                        title="Toggle visibility of pins on this page">
                    <i class="fa-solid text-[10px]" :class="pinsVisible ? 'fa-eye' : 'fa-eye-slash'"></i>
                    <span x-text="pinsVisible ? 'Pins on' : 'Pins off'"></span>
                </button>

                <button type="button"
                        @click="minimized = true"
                        class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] p-1 cursor-pointer"
                        title="Minimize feedback toolbar">
                    <i class="fa-solid fa-chevron-down text-[10px]"></i>
                </button>
            </div>
        </template>

        <template x-if="minimized">
            <button type="button"
                    @click="minimized = false"
                    class="w-9 h-9 rounded-full border border-[var(--color-border)] bg-[var(--color-surface)]/95 shadow-xl text-[var(--color-brand)] flex items-center justify-center hover:scale-110 transition-transform cursor-pointer"
                    title="Expand feedback toolbar">
                <i class="fa-solid fa-comment-dots text-sm"></i>
            </button>
        </template>
    </div>

    {{-- 6. Toast Notification --}}
    <div x-show="toast.visible"
         x-transition:enter="transition ease-out duration-250"
         x-transition:enter-start="opacity-0 -translate-y-4"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 -translate-y-4"
         class="fixed top-6 left-1/2 -translate-x-1/2 z-50 pointer-events-none select-none">
        <div class="px-5 py-2.5 rounded-full border border-[var(--color-border)] bg-[var(--color-surface)]/95 backdrop-blur-md shadow-2xl flex items-center gap-2 text-xs font-semibold text-[var(--color-ink-strong)]">
            <i class="fa-solid fa-circle-check text-[var(--color-status-green)] text-sm"></i>
            <span x-text="toast.message"></span>
        </div>
    </div>
</div>
