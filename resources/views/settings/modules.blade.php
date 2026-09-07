@extends('layouts.app')

@section('title', 'Module Settings')

@section('content')
<div class="max-w-4xl mx-auto px-6 py-12">
    <div class="mb-8">
        <h1 class="text-3xl font-display font-bold text-[var(--color-ink-strong)]">Module Management</h1>
        <p class="text-[var(--color-ink-muted)] mt-2">Enable or disable optional modules to customize your Clockwork Control installation.</p>
    </div>

    @if (session('status'))
    <div class="mb-6 p-4 rounded-[var(--radius-card)] bg-[var(--color-status-green)]/10 border border-[var(--color-status-green)]/30 text-[var(--color-status-green)]">
        {{ session('status') }}
    </div>
    @endif

    <div class="grid grid-cols-1 gap-4">
        @forelse ($modules as $module)
        <div class="p-6 rounded-[var(--radius-card)] border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]">
            <div class="flex items-start justify-between gap-4">
                <div class="flex-1">
                    <h3 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ $module->name() }}</h3>
                    <p class="text-sm text-[var(--color-ink-muted)] mt-1">{{ $module->description() }}</p>

                    @if (!empty($module->capabilities()))
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($module->capabilities() as $cap)
                        <span class="inline-block px-2 py-1 rounded text-xs font-medium bg-[var(--color-brand)]/10 text-[var(--color-brand)]">
                            {{ $cap }}
                        </span>
                        @endforeach
                    </div>
                    @endif
                </div>

                <div class="flex items-center gap-4">
                    <span class="text-sm text-[var(--color-ink-muted)]">
                        @if ($moduleStatus[$module->id()])
                        <span class="inline-flex items-center gap-1 text-[var(--color-status-green)]">
                            <i class="fa-solid fa-check-circle"></i>
                            Enabled
                        </span>
                        @else
                        <span class="inline-flex items-center gap-1 text-[var(--color-ink-muted)]">
                            <i class="fa-solid fa-circle"></i>
                            Disabled
                        </span>
                        @endif
                    </span>

                    <form action="{{ route('settings.modules.toggle') }}" method="POST" class="inline">
                        @csrf
                        <input type="hidden" name="module_id" value="{{ $module->id() }}">
                        <input type="hidden" name="enabled" value="{{ !$moduleStatus[$module->id()] ? '1' : '0' }}">
                        <button type="submit" class="btn btn-sm {{ $moduleStatus[$module->id()] ? 'btn-secondary' : 'btn-primary' }}">
                            {{ $moduleStatus[$module->id()] ? 'Disable' : 'Enable' }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
        @empty
        <div class="p-6 rounded-[var(--radius-card)] border border-[var(--color-border-light)] text-center text-[var(--color-ink-muted)]">
            No modules available.
        </div>
        @endforelse
    </div>
</div>
@endsection
