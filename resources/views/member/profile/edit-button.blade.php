{{-- No anchor in the link: browsers ignore autofocus on a URL with one. --}}
<a class="ppx-btn ppx-btn--edit" href="{{ route('member.profile', ['edit' => $section]) }}" aria-label="{{ $label ?? 'Edit' }} {{ $name }}">
    <x-member.icon :name="$icon ?? 'pencil'" class="ppx-btn__icon" />
    {{ $label ?? 'Edit' }}
</a>
