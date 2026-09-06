{{-- Standard page header — large title, optional subtitle, optional right-side
     action slot. Used at the top of every top-level page (Servers, Issues,
     Updates, Security, Monitoring) so they all read the same. --}}
@props(['title', 'subtitle' => null])

<div {{ $attributes->merge(['class' => 'flex items-end justify-between flex-wrap gap-4 mb-6']) }}>
    <div class="min-w-0">
        <h1 class="display-heading text-4xl text-[var(--color-ink-strong)] mb-2">{{ $title }}</h1>
        @if ($subtitle)
            <p class="text-[var(--color-ink-muted)] text-base">{{ $subtitle }}</p>
        @endif
    </div>
    @if (isset($actions))
        <div class="flex items-center gap-2 flex-wrap">{{ $actions }}</div>
    @endif
</div>
