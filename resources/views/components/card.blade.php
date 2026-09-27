{{-- Standard Card component.
     Renders a styled container card with optional header, icon, and actions slot.
     Example:
       <x-card title="Active Threats" icon="fa-shield-halved">
           <x-slot:actions>...</x-slot:actions>
           <div>Card body...</div>
       </x-card> --}}
@props([
    'title' => null,
    'subtitle' => null,
    'icon' => null,
])

<div {{ $attributes->merge(['class' => 'card p-5']) }}>
    @if ($title || isset($actions))
        <div class="flex items-center justify-between flex-wrap gap-3 mb-4">
            @if ($title)
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] flex items-center gap-2">
                        @if ($icon)
                            <i class="fa-solid {{ $icon }} text-[var(--color-ink-soft)] text-sm"></i>
                        @endif
                        <span>{{ $title }}</span>
                    </h2>
                    @if ($subtitle)
                        <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">{{ $subtitle }}</p>
                    @endif
                </div>
            @else
                <div></div>
            @endif

            @if (isset($actions))
                <div class="flex items-center gap-2 flex-wrap">
                    {{ $actions }}
                </div>
            @endif
        </div>
    @endif

    {{ $slot }}
</div>
