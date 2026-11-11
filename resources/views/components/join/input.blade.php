@props(['name', 'label', 'type' => 'text', 'required' => false, 'hint' => null, 'model' => 'wire:model'])
@php
    $id = str_replace('.', '-', $name);
    $describedBy = trim(($hint ? "{$id}-hint " : '').($errors->has($name) ? "{$id}-error" : ''));
@endphp
<div class="field">
    <label for="{{ $id }}">{{ $label }}@unless ($required) <span class="optional">(optional)</span>@endunless</label>
    <input id="{{ $id }}" type="{{ $type }}"
        {{ $attributes->merge([$model => $name, 'maxlength' => 255]) }}
        @if ($required) aria-required="true" @endif
        @if ($errors->has($name)) aria-invalid="true" @endif
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
    @if ($hint)
        <p class="hint" id="{{ $id }}-hint">{{ $hint }}</p>
    @endif
    <x-join.error :name="$name" />
</div>
