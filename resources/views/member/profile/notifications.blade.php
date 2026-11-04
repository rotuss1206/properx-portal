{{-- The reminder email's unsubscribe link lands here, so the switches work without an Edit step. --}}
<x-ppx.card title="Notifications" heading="h2" id="notifications" class="ppx-profile__section">
    @include('member.profile.section-open', ['section' => 'notifications'])

    <form class="ppx-form" method="POST" action="{{ route('member.profile.notifications') }}">
        @csrf
        @method('PATCH')
        <p class="ppx-profile__text ppx-profile__text--lead">Choose which emails you get from ProPerX.</p>
        <ul class="ppx-switches">
            @foreach ($profile->notificationSettings() as ['category' => $category, 'enabled' => $enabled])
                <li class="ppx-switch">
                    <span class="ppx-switch__text">
                        <label class="ppx-switch__label" for="ppx-notify-{{ $category->value }}">{{ $category->label() }}</label>
                        <span class="ppx-switch__hint" id="ppx-notify-{{ $category->value }}-hint">{{ $category->description() }}</span>
                    </span>
                    <input class="ppx-switch__input" type="checkbox" role="switch" id="ppx-notify-{{ $category->value }}" name="{{ $category->value }}" value="1" aria-describedby="ppx-notify-{{ $category->value }}-hint" @checked($enabled)>
                </li>
            @endforeach
        </ul>
        <div class="ppx-form__actions ppx-form__actions--start">
            <x-ppx.button type="submit" variant="primary">Save</x-ppx.button>
            <x-ppx.button type="reset" variant="secondary">Cancel</x-ppx.button>
        </div>
    </form>
</x-ppx.card>
