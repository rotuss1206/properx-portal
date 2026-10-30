@props(['name', 'viewBox' => '0 0 24 24', 'stroke' => '2'])
{{-- An icon from public/portal/img/icons.svg (shared with the Nova sidebar), in currentColor. --}}
<svg {{ $attributes }} viewBox="{{ $viewBox }}" fill="none" stroke="currentColor" stroke-width="{{ $stroke }}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><use href="{{ \App\Support\PortalAsset::url('img/icons.svg') }}#{{ $name }}"/></svg>
