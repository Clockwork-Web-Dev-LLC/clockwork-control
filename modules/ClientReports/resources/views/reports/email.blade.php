<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email · {{ $report->title }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-100 text-slate-900 py-8 px-4 sm:px-6">
@php
    // What pressing Send would do right now, so the reviewer sees the real "To".
    $to = $report->recipientsToSend();
    $toLabel = match (true) {
        $to !== [] => implode(', ', $to),
        $report->recipients() !== [] => 'Everyone on this report already has it',
        default => 'No recipient saved — Send will ask for one',
    };
    $reasons = $report->status === \Modules\ClientReports\Models\ClientReport::STATUS_SENT ? [] : ($report->sections_data['meta']['review_reasons'] ?? []);
@endphp
<div class="max-w-3xl mx-auto space-y-4">
    <div class="flex items-center justify-between">
        <a href="{{ route('client-reports.show', $report) }}" class="text-sm font-semibold text-slate-600 hover:text-slate-900"><i class="fa-solid fa-arrow-left"></i> Back to the report</a>
        <span class="text-xs text-slate-500">This is the email exactly as the client gets it.</span>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-4 text-sm space-y-1.5">
        <div><span class="inline-block w-20 text-slate-500">To</span>{{ $toLabel }}</div>
        @if (! empty($report->sent_to))
            <div><span class="inline-block w-20 text-slate-500">Sent to</span>{{ implode(', ', $report->sent_to) }}</div>
        @endif
        <div><span class="inline-block w-20 text-slate-500">Subject</span><span class="font-semibold">{{ $subject }}</span></div>
    </div>

    @if ($report->status === \Modules\ClientReports\Models\ClientReport::STATUS_SUPERSEDED)
        <div class="bg-slate-200 border border-slate-300 text-slate-700 rounded-xl p-4 text-sm">A newer report for this schedule and period replaced this one. It can't be sent.</div>
    @elseif ($reasons !== [])
        <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-xl p-4 text-sm">
            <div class="font-semibold mb-1"><i class="fa-solid fa-hand"></i> Check these before sending</div>
            <ul class="list-disc ml-5 space-y-0.5">
                @foreach ($reasons as $reason)
                    <li>{{ $reason }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- No scripts inside; allow-same-origin only so the frame can be sized to its content. --}}
    <iframe srcdoc="{{ $html }}" sandbox="allow-same-origin" title="Report email" style="display: block; width: 100%; height: 80vh; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px;"
        onload="this.style.height = this.contentDocument.documentElement.scrollHeight + 'px'"></iframe>

    <details class="bg-white rounded-xl border border-slate-200 p-4 text-sm">
        <summary class="cursor-pointer font-semibold text-slate-700">Plain-text version</summary>
        <p class="mt-2 text-xs text-slate-500">What mail apps that don't show HTML display instead.</p>
        <pre style="white-space: pre-wrap; font-family: ui-monospace, monospace; font-size: 12px; margin-top: 8px;">{{ $text }}</pre>
    </details>
</div>
</body>
</html>
