@props([
    'name' => null,
    'model' => null,
    'label' => null,
    'help' => null,
    'presetType' => 'primary', // 'primary' or 'accent'
    'masterModel' => null,
    'placeholder' => '#2D2062',
])

@php
    $primaryPresets = [
        ['hex' => '#2D2062', 'title' => 'Clockwork Purple'],
        ['hex' => '#4F46E5', 'title' => 'Indigo'],
        ['hex' => '#0F172A', 'title' => 'Slate Navy'],
        ['hex' => '#042F2E', 'title' => 'Dark Teal'],
        ['hex' => '#065F46', 'title' => 'Forest Green'],
        ['hex' => '#18181B', 'title' => 'Zinc Charcoal'],
        ['hex' => '#334155', 'title' => 'Slate Gray'],
    ];

    $accentPresets = [
        ['hex' => '#7EFF83', 'title' => 'Clockwork Mint'],
        ['hex' => '#10B981', 'title' => 'Emerald'],
        ['hex' => '#0EA5E9', 'title' => 'Sky Blue'],
        ['hex' => '#F59E0B', 'title' => 'Amber'],
        ['hex' => '#F43F5E', 'title' => 'Rose'],
        ['hex' => '#A855F7', 'title' => 'Purple Accent'],
        ['hex' => '#38BDF8', 'title' => 'Cyan Accent'],
    ];

    $presets = ($presetType === 'accent') ? $accentPresets : $primaryPresets;
@endphp

{{-- No nested x-data: assignments must hit the parent hub Alpine state. --}}
<div class="space-y-1.5">
    @if($label)
        <div class="flex items-center justify-between">
            <label class="block text-xs font-medium text-[var(--color-ink-strong)]">{{ $label }}</label>
            @if($masterModel && $model)
                <button type="button" 
                    @click="{{ $model }} = {{ $masterModel }}"
                    class="text-[11px] text-[var(--color-brand)] hover:underline cursor-pointer flex items-center gap-1"
                    x-show="{{ $model }} !== {{ $masterModel }}"
                    title="Reset to Master Palette Color">
                    <i class="fa-solid fa-wand-magic-sparkles text-[9px]"></i> Match Master
                </button>
            @endif
        </div>
    @endif

    <div class="flex items-center gap-2.5">
        {{-- Native color swatch --}}
        <div class="relative shrink-0 w-9 h-9 rounded-lg border border-[var(--color-border)] shadow-xs overflow-hidden cursor-pointer hover:border-[var(--color-brand)] transition-colors">
            <input type="color" 
                @if($model) x-model="{{ $model }}" @endif
                class="absolute inset-0 w-full h-full opacity-0 cursor-pointer">
            <div class="w-full h-full" 
                @if($model) :style="'background-color: ' + ({{ $model }} || '{{ $placeholder }}')" @else style="background-color: {{ $placeholder }}" @endif>
            </div>
        </div>

        {{-- Hex text input --}}
        <div class="relative w-36">
            <input type="text" 
                @if($name) name="{{ $name }}" @endif
                @if($model) 
                    x-model="{{ $model }}" 
                    @input="{{ $model }} = $event.target.value.toUpperCase()"
                @endif
                placeholder="{{ $placeholder }}"
                class="w-full pl-3 pr-8 py-2 text-sm font-mono border border-[var(--color-border)] rounded-md focus:outline-none focus:border-[var(--color-brand)] uppercase">
            
            {{-- EyeDropper writes to the parent Alpine model (no nested x-data). --}}
            @if($model)
                <button type="button" 
                    x-show="'EyeDropper' in window" 
                    @click="if ('EyeDropper' in window) { new EyeDropper().open().then(result => { {{ $model }} = result.sRGBHex.toUpperCase() }).catch(() => {}) }"
                    title="Sample color from screen"
                    class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 cursor-pointer">
                    <i class="fa-solid fa-eye-dropper text-xs"></i>
                </button>
            @endif
        </div>

        {{-- Swatch presets --}}
        <div class="flex items-center gap-1.5 ml-1">
            @foreach($presets as $preset)
                <button type="button" 
                    @click="{{ $model }} = '{{ $preset['hex'] }}'" 
                    title="{{ $preset['title'] }} ({{ $preset['hex'] }})" 
                    class="w-6 h-6 rounded-full border border-white shadow-xs cursor-pointer hover:scale-110 transition-transform relative"
                    style="background-color: {{ $preset['hex'] }}">
                    <template x-if="{{ $model }} === '{{ $preset['hex'] }}'">
                        <span class="absolute inset-0 flex items-center justify-center text-[10px] {{ in_array($preset['hex'], ['#7EFF83', '#38BDF8']) ? 'text-slate-900' : 'text-white' }}">
                            <i class="fa-solid fa-check"></i>
                        </span>
                    </template>
                </button>
            @endforeach
        </div>
    </div>

    @if($help)
        <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">{{ $help }}</p>
    @endif
</div>
