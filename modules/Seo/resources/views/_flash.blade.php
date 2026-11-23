{{-- Flash messages. Class names are written out in full so Tailwind sees them. --}}
@if (session('status'))
    <div class="mb-4 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-700 dark:text-emerald-300 text-sm flex items-center gap-3" role="status">
        <i class="fa-solid fa-circle-check text-base"></i>
        <span>{{ session('status') }}</span>
    </div>
@endif
@if (session('warning'))
    <div class="mb-4 p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-700 dark:text-amber-300 text-sm flex items-center gap-3" role="alert">
        <i class="fa-solid fa-triangle-exclamation text-base"></i>
        <span>{{ session('warning') }}</span>
    </div>
@endif
@if (session('error'))
    <div class="mb-4 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-700 dark:text-red-300 text-sm flex items-center gap-3" role="alert">
        <i class="fa-solid fa-circle-xmark text-base"></i>
        <span>{{ session('error') }}</span>
    </div>
@endif
@if ($errors->any())
    <div class="mb-4 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-700 dark:text-red-300 text-sm" role="alert">
        <ul class="list-disc pl-5 space-y-1">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
