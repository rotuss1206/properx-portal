@props(['options', 'current', 'action' => 'filterBy', 'href' => fn ($key) => '?filter='.$key, 'label' => 'Filter'])
{{--
    Filter chips (public/portal/components.css): links with the selected one marked, calling
    "action" inside a Livewire component (false on a plain page; null would mean the default).
    The label is drawn once more, bold and invisible, so a chip is as wide selected as not.
    On plain pages public/portal/chips.js switches without reloading (see there).
--}}
<ul {{ $attributes->class('ppx-chips') }} aria-label="{{ $label }}" data-ppx-chips>
    @foreach ($options as $key => $text)
        <li>
            <a href="{{ $href($key) }}" @class(['ppx-chips__chip', 'is-active' => $key === $current]) @if ($key === $current) aria-current="true" @endif @if ($action) wire:click.prevent="{{ $action }}('{{ $key }}')" @endif><span class="ppx-chips__text" data-label="{{ $text }}">{{ $text }}</span></a>
        </li>
    @endforeach
</ul>
