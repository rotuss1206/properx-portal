@props(['name', 'label', 'options', 'required' => false, 'hint' => null, 'placeholder' => 'Select…', 'model' => 'wire:model'])
@php
    $id = str_replace('.', '-', $name);
    $describedBy = trim(($hint ? "{$id}-hint " : '').($errors->has($name) ? "{$id}-error" : ''));
@endphp
<div class="field">
    <label for="{{ $id }}">{{ $label }}@unless ($required) <span class="optional">(optional)</span>@endunless</label>
    @if ($hint)
        <p class="hint" id="{{ $id }}-hint">{{ $hint }}</p>
    @endif
    <select id="{{ $id }}"
        {{ $attributes->merge([$model => $name]) }}
        @if ($required) aria-required="true" @endif
        @if ($errors->has($name)) aria-invalid="true" @endif
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
        <option value="">{{ $placeholder }}</option>
        @foreach ($options as $value => $text)
            <option value="{{ $value }}">{{ $text }}</option>
        @endforeach
    </select>
    <x-join.error :name="$name" />
</div>
