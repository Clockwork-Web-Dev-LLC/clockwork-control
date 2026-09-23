{{--
  Clockwork Control — Global Confirmation & Prompt Modal Component
  Theme-aware (light / dark / midnight), accessible, and animated.
  Included in base layouts: layouts/app.blade.php and layouts/install.blade.php.
--}}
<div x-data="confirmModal()"
     x-show="open"
     x-cloak
     @keydown.window="handleKeydown($event)"
     class="fixed inset-0 z-50 overflow-y-auto p-4 sm:p-6 md:p-20 flex items-center justify-center"
     role="dialog"
     aria-modal="true">

    {{-- Backdrop --}}
    <div x-show="open"
         x-transition:enter="ease-out duration-150"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-100"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-black/60 backdrop-blur-xs transition-opacity"
         @click="handleCancel()"></div>

    {{-- Modal Panel --}}
    <div x-show="open"
         x-transition:enter="ease-out duration-150"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="ease-in duration-100"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         @click.away="handleCancel()"
         class="relative w-full max-w-lg transform overflow-hidden rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-2xl p-6 transition-all text-[var(--color-ink)] select-none sm:my-8">

        {{-- Top Right Close Button --}}
        <button type="button"
                @click="handleCancel()"
                class="absolute right-4 top-4 w-8 h-8 rounded-lg flex items-center justify-center text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] transition-colors focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]/20"
                aria-label="Close dialog">
            <i class="fa-solid fa-xmark text-sm"></i>
        </button>

        {{-- Header & Icon Badge --}}
        <div class="flex items-start gap-4">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0"
                 :class="iconColorClass">
                <i :class="iconClass" class="text-lg"></i>
            </div>

            <div class="flex-1 min-w-0 pt-0.5 pr-6">
                <h3 class="text-base font-display font-bold text-[var(--color-ink-strong)] leading-snug tracking-tight"
                    x-text="title"></h3>
                
                <p x-show="message"
                   x-text="message"
                   class="mt-1.5 text-sm text-[var(--color-ink-muted)] leading-relaxed"></p>

                {{-- Optional Inset Details Box --}}
                <div x-show="details"
                     class="mt-3.5 p-3 rounded-xl bg-[var(--color-surface-alt)]/70 border border-[var(--color-border-light)] text-xs text-[var(--color-ink-muted)] leading-relaxed flex items-start gap-2.5">
                    <i class="fa-solid fa-circle-info text-[var(--color-ink-soft)] mt-0.5 shrink-0 text-xs"></i>
                    <span x-text="details"></span>
                </div>

                {{-- Optional Typed Match Input (e.g. Type server name to confirm deletion) --}}
                <div x-show="requireMatch" class="mt-4">
                    <label class="block text-xs font-medium text-[var(--color-ink-strong)] mb-1.5">
                        To confirm, please type <code class="font-mono font-bold text-[var(--color-ink-strong)] bg-[var(--color-surface-alt)] px-1.5 py-0.5 rounded border border-[var(--color-border-light)] select-all" x-text="requireMatch"></code> below:
                    </label>
                    <input type="text"
                           x-model="inputValue"
                           x-ref="matchInput"
                           placeholder="Type to match..."
                           class="w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 text-sm text-[var(--color-ink-strong)] placeholder:text-[var(--color-ink-soft)] focus:border-[var(--color-brand)] focus:ring-1 focus:ring-[var(--color-brand)] font-sans transition-colors">
                </div>

                {{-- Optional Generic Prompt Input --}}
                <div x-show="isPrompt && !requireMatch" class="mt-4">
                    <input type="text"
                           x-model="inputValue"
                           x-ref="promptInput"
                           :placeholder="inputPlaceholder"
                           class="w-full rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 text-sm text-[var(--color-ink-strong)] placeholder:text-[var(--color-ink-soft)] focus:border-[var(--color-brand)] focus:ring-1 focus:ring-[var(--color-brand)] font-sans transition-colors">
                </div>
            </div>
        </div>

        {{-- Footer Actions --}}
        <div class="mt-6 flex items-center justify-end gap-3 pt-3 border-t border-[var(--color-border-light)]">
            <button type="button"
                    x-show="!isAlert"
                    @click="handleCancel()"
                    class="px-4 py-2 rounded-xl border border-[var(--color-border)] text-sm font-medium text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)] hover:text-[var(--color-ink-strong)] transition-colors focus:outline-none focus:ring-2 focus:ring-[var(--color-border)]">
                <span x-text="cancelText"></span>
            </button>

            <button type="button"
                    @click="handleConfirm()"
                    :disabled="!canConfirm || loading"
                    x-ref="confirmBtn"
                    :class="[confirmBtnClass, (!canConfirm || loading) ? 'opacity-50 cursor-not-allowed' : '']"
                    class="px-4 py-2 rounded-xl text-sm font-semibold transition-all flex items-center justify-center gap-2 min-w-22 focus:outline-none focus:ring-2 focus:ring-offset-1">
                <i class="fa-solid fa-spinner fa-spin text-xs" x-show="loading"></i>
                <span x-text="confirmText"></span>
            </button>
        </div>
    </div>
</div>
