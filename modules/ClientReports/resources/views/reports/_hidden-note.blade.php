{{-- Operator preview only: why a section is left out of what the client
     sees. no-print, so it never reaches the PDF; the caller only includes it
     on the operator preview, so it never reaches the public view either. --}}
<div class="no-print flex items-start gap-2 text-xs text-slate-500 bg-slate-50 border border-dashed border-slate-300 rounded-lg px-3 py-2">
    <i class="fa-solid fa-eye-slash mt-0.5 text-slate-400"></i>
    <div>
        <div>{{ $note }}</div>
        @if (! empty($detail))
            <div class="mt-1 text-red-700">{{ $detail }}</div>
        @endif
    </div>
</div>
