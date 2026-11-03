{{-- Operator preview only: why a section is left out of what the client
     sees. no-print, so it never reaches the PDF; the caller only includes it
     on the operator preview, so it never reaches the public view either.
     Optional: $badge (a short status pill, e.g. "Failing") and $detail (an
     error line). --}}
<div class="no-print flex items-start gap-2 text-xs text-slate-500 bg-slate-50 border border-dashed border-slate-300 rounded-lg px-3 py-2">
    <i class="fa-solid fa-eye-slash mt-0.5 text-slate-400"></i>
    <div>
        <div>{{ $note }}</div>
        @if (! empty($badge))
            <span class="inline-block mt-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-red-100 text-red-800">{{ $badge }}</span>
        @endif
        @if (! empty($detail))
            <div class="mt-1 text-red-700">{{ $detail }}</div>
        @endif
    </div>
</div>
