@if (session('status'))
    <div class="card p-4 mb-6 status-green text-xs flex items-start gap-2" role="status">
        <i class="fa-solid fa-circle-check mt-0.5"></i>
        <span>{{ session('status') }}</span>
    </div>
@endif

@if (session('status_error'))
    <div class="card p-4 mb-6 status-red text-xs flex items-start gap-2" role="alert">
        <i class="fa-solid fa-triangle-exclamation mt-0.5"></i>
        <span>{{ session('status_error') }}</span>
    </div>
@endif
