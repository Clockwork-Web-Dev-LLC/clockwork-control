YOUR EMAIL SETUP — {{ $domain }}

Hi{{ $greetingName ? ' '.$greetingName : '' }},

{{ trim($note) }}
@if (count($items))

WHAT WE FOUND
@foreach ($items as $item)

* {{ $item['title'] }} ({{ ['fail' => 'needs attention', 'warn' => 'recommended', 'info' => 'for your information'][$item['severity']] ?? $item['severity'] }})
  {{ $item['explanation'] }}
@endforeach
@endif

Want us to take care of this? Just reply to this email and we'll handle it for you.
@if (count($items))

TECHNICAL DETAILS (checked {{ $check->checked_at?->format('M j, Y') }})
@foreach ($items as $item)
- {{ $item['check'] }}: {{ $item['technical'] }} ({{ $item['code'] }})
@endforeach
SPF record: {{ $check->spf_record ?: 'None published' }}
DMARC record: {{ $check->dmarc_record ?: 'None published' }}
DKIM: {{ ! empty($check->dkim_selectors_found) ? implode(', ', (array) $check->dkim_selectors_found) : 'No key found on common selectors' }}
Mail servers (MX): {{ $check->mx_present ? 'Present' : 'None' }}
@endif

Thanks,
— The {{ $branding['company_name'] }} team
@if ($branding['support_email'] !== '')
Questions? Reply here or write to {{ $branding['support_email'] }}.
@endif
