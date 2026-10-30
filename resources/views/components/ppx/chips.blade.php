@props(['options', 'current', 'action' => 'filterBy', 'href' => fn ($key) => '?filter='.$key, 'label' => 'Filter'])
{{--
    Filter chips (public/portal/components.css): links with the selected one marked, calling
    "action" inside a Livewire component.
--}}
<ul {{ $attributes->class('ppx-chips') }} aria-label="{{ $label }}">
    @foreach ($options as $key => $text)
        <li>
            <a href="{{ $href($key) }}" @class(['ppx-chips__chip', 'is-active' => $key === $current]) @if ($key === $current) aria-current="true" @endif wire:click.prevent="{{ $action }}('{{ $key }}')">{{ $text }}</a>
        </li>
    @endforeach
</ul>
