@props(['variant' => 'primary', 'href' => null, 'icon' => null, 'label' => null, 'type' => 'button', 'size' => null])
{{--
    Buttons (public/portal/components.css): primary (yellow), secondary (white with a border),
    icon (square, icon only: "label" becomes the accessible name). A link when "href" is given.
--}}
@php
    $classes = ['ppx-btn', 'ppx-btn--'.$variant, 'ppx-btn--'.$size => $size];
    $iconOnly = $variant === 'icon';
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }} @if ($iconOnly) aria-label="{{ $label }}" title="{{ $label }}" @endif>
        @if ($icon)<x-member.icon :name="$icon" class="ppx-btn__icon" />@endif
        @unless ($iconOnly){{ $slot }}@endunless
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }} @if ($iconOnly) aria-label="{{ $label }}" title="{{ $label }}" @endif>
        @if ($icon)<x-member.icon :name="$icon" class="ppx-btn__icon" />@endif
        @unless ($iconOnly){{ $slot }}@endunless
    </button>
@endif
