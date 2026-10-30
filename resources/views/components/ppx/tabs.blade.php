@props(['tabs', 'current', 'action' => 'show', 'param' => 'tab', 'label' => 'Sections'])
{{--
    Underlined tabs (public/portal/components.css). Real links (?tab=…), so each tab has an
    address and works without JavaScript; inside a Livewire component the click calls
    "action" instead of reloading.
--}}
<nav {{ $attributes->class('ppx-tabs') }} aria-label="{{ $label }}">
    <ul class="ppx-tabs__list">
        @foreach ($tabs as $key => $text)
            <li>
                <a href="?{{ $param }}={{ $key }}" @class(['ppx-tabs__tab', 'is-active' => $key === $current]) @if ($key === $current) aria-current="page" @endif wire:click.prevent="{{ $action }}('{{ $key }}')">{{ $text }}</a>
            </li>
        @endforeach
    </ul>
</nav>
