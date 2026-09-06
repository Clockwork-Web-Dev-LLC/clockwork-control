@props([
    'service' => '',
    'class' => 'w-8 h-8',
])

@php
    $id = strtolower((string) $service);
@endphp

@if ($id === 'digitalocean')
    <svg class="{{ $class }}" viewBox="0 0 24 24" fill="#0080FF" aria-hidden="true">
        <path d="M12.0003 0C5.3727 0 0 5.3727 0 12.0003C0 18.6279 5.3727 24 12.0003 24C18.6279 24 24 18.6279 24 12.0003H17.8447C17.8447 15.228 15.228 17.8447 12.0003 17.8447C8.77259 17.8447 6.15585 15.228 6.15585 12.0003C6.15585 8.77259 8.77259 6.15585 12.0003 6.15585V0Z"/>
        <path d="M12 17.8447V13.8447H8V17.8447H12Z" fill="#0080FF"/>
        <path d="M8 17.8447V21.8447H4V17.8447H8Z" fill="#0080FF"/>
        <path d="M8 13.8447V10.8447H5V13.8447H8Z" fill="#0080FF"/>
    </svg>
@elseif ($id === 'hetzner')
    <svg class="{{ $class }}" viewBox="0 0 24 24" fill="#D50C2D" aria-hidden="true">
        <path d="M0 0h6v9h12V0h6v24h-6v-9H6v9H0V0z"/>
    </svg>
@elseif ($id === 'vultr')
    <svg class="{{ $class }}" viewBox="0 0 24 24" fill="#007BFC" aria-hidden="true">
        <path d="M22.56 3H16.8L12 14.86 7.2 3H1.44l7.68 18h5.76L22.56 3z"/>
    </svg>
@elseif ($id === 'linode')
    <svg class="{{ $class }}" viewBox="0 0 24 24" fill="#00A95C" aria-hidden="true">
        <path d="M20.6 8.3L13.7 1.4c-.9-.9-2.5-.9-3.4 0L3.4 8.3c-.9.9-.9 2.5 0 3.4l6.9 6.9c.9.9 2.5.9 3.4 0l6.9-6.9c.9-.9.9-2.5 0-3.4zm-8.6 8.6l-5.2-5.2 5.2-5.2 5.2 5.2-5.2 5.2z"/>
    </svg>
@elseif ($id === 'azure')
    <svg class="{{ $class }}" viewBox="0 0 24 24" fill="#0089D6" aria-hidden="true">
        <path d="M13.05 2.4L6.96 15.6H13.6L12.05 21.6L20.8 7.2H14.16L17.04 2.4H13.05Z"/>
    </svg>
@elseif ($id === 'spinupwp')
    <svg class="{{ $class }}" viewBox="0 0 24 24" fill="#0EA5E9" aria-hidden="true">
        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 14.5v-3h-2v3H9l3 3 3-3h-2zm0-6V7.5h-2V10.5H9l3-3 3 3h-2z"/>
    </svg>
@elseif ($id === 'pressable')
    <svg class="{{ $class }}" viewBox="0 0 24 24" fill="#4F46E5" aria-hidden="true">
        <rect x="2" y="3" width="20" height="18" rx="4" fill="#4F46E5"/>
        <path d="M8 8h5a3.5 3.5 0 010 7H8V8zm3 4.5h2a1 1 0 000-2h-2v2z" fill="#FFFFFF"/>
    </svg>
@elseif ($id === 'wpengine')
    <svg class="{{ $class }}" viewBox="0 0 24 24" fill="#00D5A0" aria-hidden="true">
        <path d="M12 2L2 7v10l10 5 10-5V7L12 2zm0 3.3l6.5 3.2v6.9L12 18.7l-6.5-3.3V8.5L12 5.3z"/>
    </svg>
@elseif ($id === 'kinsta')
    <svg class="{{ $class }}" viewBox="0 0 24 24" fill="#5333ED" aria-hidden="true">
        <path d="M12 2L2 7l10 5 10-5-10-5zm0 8.5L4.5 7 12 3.5 19.5 7 12 10.5zM2 9.5v5l10 5v-5L2 9.5zm20 0l-10 5v5l10-5v-5z"/>
    </svg>
@elseif ($id === 'cloudways')
    <svg class="{{ $class }}" viewBox="0 0 24 24" fill="#2C39D5" aria-hidden="true">
        <path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z"/>
    </svg>
@elseif ($id === 'gridpane')
    <svg class="{{ $class }}" viewBox="0 0 24 24" fill="#10B981" aria-hidden="true">
        <rect x="2" y="2" width="9" height="9" rx="2"/>
        <rect x="13" y="2" width="9" height="9" rx="2"/>
        <rect x="2" y="13" width="9" height="9" rx="2"/>
        <rect x="13" y="13" width="9" height="9" rx="2"/>
    </svg>
@elseif ($id === 'slack' || $id === 'client_slack')
    <svg class="{{ $class }}" viewBox="0 0 24 24" aria-hidden="true">
        <path d="M5.042 15.165a2.528 2.528 0 0 1-2.52 2.523A2.528 2.528 0 0 1 0 15.165a2.527 2.527 0 0 1 2.522-2.52h2.52v2.52zM6.313 15.165a2.527 2.527 0 0 1 2.521-2.52 2.527 2.527 0 0 1 2.521 2.52v6.313A2.528 2.528 0 0 1 8.834 24a2.528 2.528 0 0 1-2.521-2.522v-6.313z" fill="#E01E5A"/>
        <path d="M8.834 5.042a2.528 2.528 0 0 1-2.521-2.52A2.528 2.528 0 0 1 8.834 0a2.528 2.528 0 0 1 2.521 2.522v2.52H8.834zM8.834 6.313a2.528 2.528 0 0 1 2.521 2.521 2.528 2.528 0 0 1-2.521 2.521H2.522A2.528 2.528 0 0 1 0 8.834a2.528 2.528 0 0 1 2.522-2.521h6.312z" fill="#36C5F0"/>
        <path d="M18.956 8.834a2.528 2.528 0 0 1 2.522-2.521A2.528 2.528 0 0 1 24 8.834a2.528 2.528 0 0 1-2.522 2.521h-2.522V8.834zM17.688 8.834a2.528 2.528 0 0 1-2.523 2.521 2.527 2.527 0 0 1-2.52-2.521V2.522A2.527 2.527 0 0 1 15.165 0a2.528 2.528 0 0 1 2.523 2.522v6.312z" fill="#2EB67D"/>
        <path d="M15.165 18.956a2.528 2.528 0 0 1 2.523 2.522A2.528 2.528 0 0 1 15.165 24a2.527 2.527 0 0 1-2.52-2.522v-2.522h2.52zM15.165 17.688a2.527 2.527 0 0 1-2.52-2.523 2.526 2.526 0 0 1 2.52-2.52h6.313A2.527 2.527 0 0 1 24 15.165a2.528 2.528 0 0 1-2.522 2.523h-6.313z" fill="#ECB22E"/>
    </svg>
@elseif ($id === 'mattermost')
    <svg class="{{ $class }}" viewBox="0 0 24 24" fill="#0058CC" aria-hidden="true">
        <path d="M12 2C6.48 2 2 6.48 2 12c0 4.42 2.87 8.17 6.84 9.5.5.08.66-.23.66-.5v-1.69c-2.77.6-3.36-1.34-3.36-1.34-.46-1.16-1.11-1.47-1.11-1.47-.91-.62.07-.6.07-.6 1 .07 1.53 1.03 1.53 1.03.87 1.52 2.34 1.07 2.91.83.09-.65.35-1.09.63-1.34-2.22-.25-4.55-1.11-4.55-4.92 0-1.11.38-2 1.03-2.71-.1-.25-.45-1.29.1-2.64 0 0 .84-.27 2.75 1.02.79-.22 1.65-.33 2.5-.33.85 0 1.71.11 2.5.33 1.91-1.29 2.75-1.02 2.75-1.02.55 1.35.2 2.39.1 2.64.65.71 1.03 1.6 1.03 2.71 0 3.82-2.34 4.66-4.57 4.91.36.31.69.92.69 1.85V21c0 .27.16.59.67.5C19.14 20.16 22 16.42 22 12A10 10 0 0012 2z"/>
    </svg>
@elseif ($id === 'twilio')
    <svg class="{{ $class }}" viewBox="0 0 24 24" fill="#F22F46" aria-hidden="true">
        <path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm0 4.8a2.4 2.4 0 110 4.8 2.4 2.4 0 010-4.8zm-4.8 4.8a2.4 2.4 0 110 4.8 2.4 2.4 0 010-4.8zm9.6 0a2.4 2.4 0 110 4.8 2.4 2.4 0 010-4.8zM12 14.4a2.4 2.4 0 110 4.8 2.4 2.4 0 010-4.8z"/>
    </svg>
@elseif ($id === 'bill_com')
    <div class="{{ $class }} rounded-lg bg-[var(--color-primary-500)] text-white font-bold flex items-center justify-center text-sm shadow-xs">
        B
    </div>
@elseif ($id === 'gtmetrix')
    <div class="{{ $class }} rounded-lg bg-[#0c2340] text-[#00b0ff] font-bold flex items-center justify-center text-sm shadow-xs">
        <i class="fa-solid fa-gauge-high text-sky-400"></i>
    </div>
@elseif ($id === 'auth_google')
    <svg class="{{ $class }}" viewBox="0 0 24 24" aria-hidden="true">
        <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
        <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
        <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z" fill="#FBBC05"/>
        <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z" fill="#EA4335"/>
    </svg>
@elseif ($id === 'auth_github')
    <svg class="{{ $class }}" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
        <path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.53 1.032 1.53 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/>
    </svg>
@elseif ($id === 'auth_microsoft')
    <svg class="{{ $class }}" viewBox="0 0 24 24" aria-hidden="true">
        <rect x="1" y="1" width="10" height="10" fill="#F25022"/>
        <rect x="13" y="1" width="10" height="10" fill="#7FBA00"/>
        <rect x="1" y="13" width="10" height="10" fill="#00A4EF"/>
        <rect x="13" y="13" width="10" height="10" fill="#FFB900"/>
    </svg>
@elseif ($id === 'psi')
    <div class="{{ $class }} rounded-lg bg-[#E8F0FE] text-[#1A73E8] font-bold flex items-center justify-center text-sm shadow-xs dark:bg-[#174EA6]/30 dark:text-[#8AB4F8]">
        <i class="fa-solid fa-gauge-simple-high text-blue-600 dark:text-blue-400"></i>
    </div>
@elseif ($id === 'sucuri')
    <div class="{{ $class }} rounded-lg bg-[#E6F4EA] text-[#137333] font-bold flex items-center justify-center text-sm shadow-xs dark:bg-[#0D652D]/30 dark:text-[#81C995]">
        <i class="fa-solid fa-shield-virus text-emerald-600 dark:text-emerald-400"></i>
    </div>
@else
    <div class="{{ $class }} text-[var(--color-ink-muted)] flex items-center justify-center text-base">
        <i class="fa-solid fa-layer-group"></i>
    </div>
@endif
