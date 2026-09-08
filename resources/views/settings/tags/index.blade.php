@extends('layouts.app')

@section('title', 'Server tags · Settings')

@section('content')
    @include('settings._tabs')

    <x-page-header title="Server tags"
        subtitle="Predefined labels for sorting and filtering servers (dedicated, shared, client-specific, etc.). Tag servers from each server's edit page.">
        <x-slot:actions>
            <a href="{{ route('dashboard') }}" class="btn-pill-nav text-sm">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Back to dashboard</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="card p-4 mb-6 status-green flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i>
            {{ session('status') }}
        </div>
    @endif

    <div class="card mb-8 p-5">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-4">
            {{ $tags->isEmpty() ? 'Create your first tag' : 'Existing tags' }}
        </h2>

        @if ($tags->isNotEmpty())
            <div class="divide-y divide-[var(--color-border-light)] mb-6">
                @foreach ($tags as $tag)
                    <div class="py-3 flex items-center gap-3 flex-wrap">
                        <form method="POST" action="{{ route('settings.tags.update', $tag) }}" id="tag-edit-{{ $tag->id }}" class="contents">
                            @csrf
                            @method('PATCH')
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full" style="background: {{ $tag->color }}"></span>
                            <input type="text" name="name" value="{{ old('name', $tag->name) }}" required maxlength="64"
                                   class="px-3 py-1.5 rounded-md border border-[var(--color-border-light)] text-sm font-medium" placeholder="Name">
                            <input type="color" name="color" value="{{ old('color', $tag->color) }}" required
                                   class="w-10 h-9 rounded cursor-pointer border border-[var(--color-border-light)]" title="Tag color">
                            <input type="text" name="description" value="{{ old('description', $tag->description) }}" maxlength="255"
                                   class="flex-1 min-w-[200px] px-3 py-1.5 rounded-md border border-[var(--color-border-light)] text-sm" placeholder="Description (optional)">
                            <input type="number" name="sort_order" value="{{ old('sort_order', $tag->sort_order) }}" min="0" max="9999"
                                   class="w-20 px-3 py-1.5 rounded-md border border-[var(--color-border-light)] text-sm" placeholder="Sort" title="Sort order (lower first)">
                            <span class="text-xs text-[var(--color-ink-soft)] tabular-nums" title="Servers tagged">
                                {{ $tag->servers_count }} {{ Str::plural('server', $tag->servers_count) }}
                            </span>
                            <button type="submit" class="btn-pill-nav" title="Save">
                                <i class="fa-solid fa-floppy-disk"></i>
                            </button>
                        </form>
                        <form method="POST" action="{{ route('settings.tags.destroy', $tag) }}"
                              onsubmit="return confirm('Delete tag &quot;{{ $tag->name }}&quot;? Servers tagged with it will lose this tag.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn-pill-nav text-[var(--color-status-red)]" title="Delete tag">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif

        <h3 class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-2">Add new tag</h3>
        <form method="POST" action="{{ route('settings.tags.store') }}" class="flex items-center gap-3 flex-wrap">
            @csrf
            <input type="text" name="name" required maxlength="64" placeholder="Name (e.g. dedicated)"
                   class="px-3 py-1.5 rounded-md border border-[var(--color-border-light)] text-sm">
            <input type="color" name="color" value="#3b82f6"
                   class="w-10 h-9 rounded cursor-pointer border border-[var(--color-border-light)]" title="Tag color">
            <input type="text" name="description" maxlength="255" placeholder="Description (optional)"
                   class="flex-1 min-w-[200px] px-3 py-1.5 rounded-md border border-[var(--color-border-light)] text-sm">
            <input type="number" name="sort_order" min="0" max="9999" value="0" placeholder="Sort"
                   class="w-20 px-3 py-1.5 rounded-md border border-[var(--color-border-light)] text-sm" title="Sort order">
            <button type="submit" class="btn-primary">
                <i class="fa-solid fa-plus"></i> Add tag
            </button>
        </form>

        @if ($errors->any())
            <ul class="mt-3 text-sm text-[var(--color-status-red)] list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="text-sm text-[var(--color-ink-muted)]">
        <p><strong>Tip:</strong> common starter tags — <span class="font-data">dedicated</span>, <span class="font-data">shared</span>, <span class="font-data">staging</span>, <span class="font-data">retired</span>, or one tag per client name. The sort order controls the display order on server cards (lower = first).</p>
    </div>
@endsection
