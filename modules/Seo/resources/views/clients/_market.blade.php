@php
    $countries = \Modules\Seo\Http\Controllers\SeoClientsController::COUNTRIES;
    $location = (int) old('market_location_code', $profile?->market_location_code ?? \Modules\Seo\Keywords\Market::DEFAULT_LOCATION_CODE);
@endphp
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4" data-market-fields>
    <label class="block text-sm">
        <span class="font-medium text-[var(--color-ink-strong)]">Keyword market</span>
        <select name="market_location_code" class="{{ $inputClass }}">
            @foreach ($countries as $code => $name)
                <option value="{{ $code }}" @selected($location === $code)>{{ $name }}</option>
            @endforeach
            @if (! array_key_exists($location, $countries))
                <option value="{{ $location }}" selected>Location code {{ $location }}</option>
            @endif
        </select>
    </label>
    <label class="block text-sm">
        <span class="font-medium text-[var(--color-ink-strong)]">Language</span>
        <input type="text" name="market_language_code" maxlength="7" value="{{ old('market_language_code', $profile?->market_language_code ?? \Modules\Seo\Keywords\Market::DEFAULT_LANGUAGE_CODE) }}" class="{{ $inputClass }} font-data">
    </label>
    <label class="block text-sm">
        <span class="font-medium text-[var(--color-ink-strong)]">City <span class="font-normal text-[var(--color-ink-soft)]">(optional)</span></span>
        <input type="text" name="market_city" maxlength="191" value="{{ old('market_city', $profile?->market_city) }}" placeholder="Scottsdale, Arizona" class="{{ $inputClass }}">
    </label>
    <p class="sm:col-span-3 text-xs text-[var(--color-ink-soft)] -mt-2">Where this client's customers search. Keyword volume and difficulty are looked up for this country and language.</p>
</div>
