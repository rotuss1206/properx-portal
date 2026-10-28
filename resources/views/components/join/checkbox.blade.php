@props(['name', 'model' => 'wire:model'])
@php($id = str_replace('.', '-', $name))
<div class="check">
    <input id="{{ $id }}" type="checkbox"
        {{ $attributes->merge([$model => $name]) }}
        @if ($errors->has($name)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
    <label for="{{ $id }}">{{ $slot }}</label>
</div>
<x-join.error :name="$name" />
