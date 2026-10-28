@props(['name'])
@error($name)
    <p class="error" id="{{ str_replace('.', '-', $name) }}-error">{{ $message }}</p>
@enderror
