@props(['title' => null, 'heading' => 'h3', 'variant' => null])
{{--
    Card with the green bar beside its title (public/portal/components.css). Slots: default
    (body), "action" (header right: a link or button). Variants: info. Reused for the Nova theme.
--}}
<section {{ $attributes->class(['ppx-card', 'ppx-card--'.$variant => $variant]) }}>
    @if ($title !== null || isset($action))
        <div class="ppx-card__header">
            @if ($title !== null)
                <{{ $heading }} class="ppx-card__title">{{ $title }}</{{ $heading }}>
            @endif
            @isset($action)
                <div class="ppx-card__action">{{ $action }}</div>
            @endisset
        </div>
    @endif
    {{ $slot }}
</section>
