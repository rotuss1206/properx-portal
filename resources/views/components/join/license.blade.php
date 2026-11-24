{{--
    A licensed producer's license (the client's rules): license number, license types with "Other" and
    its words, and a copy of the license (PDF, JPG or PNG up to 10 MB), stored at once when chosen
    (Livewire upload into the documents vault; the form keeps only the record's id).
    $prefix: "principal" or "people.entry"; $upload: the component's upload property.
--}}
@props(["prefix", "upload", "fileName" => "", "types" => []])
@php
    $id = str_replace('.', '-', $prefix);
    $choices = \App\Support\LicenseTypes::choices();
    $other = in_array(\App\Support\LicenseTypes::OTHER, (array) $types, true);
    $copyRequired = \App\Support\LicenseCopy::required();
@endphp
<x-join.input :name="$prefix.'.license_number'" label="License number" required maxlength="50" autocomplete="off"
    hint="As printed on the resident license." />

<fieldset class="field license-types" @if ($errors->has($prefix.'.license_types')) aria-describedby="{{ $id }}-license_types-error" @endif>
    <legend>License type(s) held</legend>
    <div class="checks-inline">
        @foreach ($choices as $index => $choice)
            <label class="check-inline">
                <input type="checkbox" wire:model.live="{{ $prefix }}.license_types" value="{{ $choice }}" id="{{ $id }}-license_types-{{ $index }}">
                {{ $choice }}
            </label>
        @endforeach
    </div>
    <x-join.error :name="$prefix.'.license_types'" />
</fieldset>

@if ($other)
    <x-join.input :name="$prefix.'.license_type_other'" label="Other license type" required maxlength="100" autocomplete="off" />
@endif

<div class="field license-copy">
    <label for="{{ $id }}-license_file">Copy of the license @unless ($copyRequired)<span class="optional">(optional)</span>@endunless</label>
    @if ($fileName !== '')
        <p class="license-file" id="{{ $id }}-license_file-name"><span class="tag tag-ok">Uploaded</span> {{ $fileName }}</p>
    @endif
    <input id="{{ $id }}-license_file" type="file" wire:model="{{ $upload }}" accept="{{ \App\Services\Documents\DocumentVault::acceptedExtensions() }}"
        aria-describedby="{{ $id }}-license_file-hint" @if ($errors->has($prefix.'.license_file')) aria-invalid="true" @endif>
    <p class="hint" id="{{ $id }}-license_file-hint">PDF, JPG or PNG, up to 10 MB. {{ $fileName !== '' ? 'Choose another file to replace it.' : 'ProPerX checks it before the license is verified.' }}</p>
    <p class="hint" wire:loading wire:target="{{ $upload }}">Uploading…</p>
    <x-join.error :name="$prefix.'.license_file'" />
</div>
