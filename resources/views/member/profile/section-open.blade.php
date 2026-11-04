{{-- The confirmation of the last save, inside the section it belongs to. --}}
@if (session('profile_saved') === $section && session('status'))
    <p class="ppx-notice ppx-notice--success ppx-profile__notice" role="status">{{ session('status') }}</p>
@endif
