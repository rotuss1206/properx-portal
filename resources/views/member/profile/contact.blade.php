<x-ppx.card title="Contact information" heading="h2" id="contact" class="ppx-profile__section">
    @unless ($editing === 'contact')
        <x-slot:action>@include('member.profile.edit-button', ['section' => 'contact', 'name' => 'contact information'])</x-slot:action>
    @endunless

    @include('member.profile.section-open', ['section' => 'contact'])

    @if ($editing === 'contact')
        @php($focus = collect(['first_name', 'last_name', 'phone', 'address'])->first(fn ($field) => $errors->has($field)) ?? 'first_name')
        <form class="ppx-form" method="POST" action="{{ route('member.profile.contact') }}" novalidate>
            @csrf
            @method('PATCH')
            <x-ppx.form-row name="first_name" label="First name" :value="old('first_name', $member->first_name)" autocomplete="given-name" required :autofocus="$focus === 'first_name'" />
            <x-ppx.form-row name="last_name" label="Last name" :value="old('last_name', $member->last_name)" autocomplete="family-name" required :autofocus="$focus === 'last_name'" />
            <x-ppx.form-row name="phone" label="Phone" type="tel" :value="old('phone', $member->phone)" autocomplete="tel" hint="10-digit US number, e.g. (512) 555-0142." :autofocus="$focus === 'phone'" />
            <x-ppx.form-row name="address" label="Mailing address" type="textarea" :value="old('address', $member->address)" autocomplete="street-address" :autofocus="$focus === 'address'" />
            @include('member.profile.form-actions', ['section' => 'contact'])
        </form>
    @else
        <dl class="ppx-kv">
            <div class="ppx-kv__row"><dt>Full name</dt><dd>{{ $member->fullName() }}</dd></div>
            <div class="ppx-kv__row"><dt>Phone</dt><dd>{{ $member->phone ?: '—' }}</dd></div>
            <div class="ppx-kv__row"><dt>Mailing address</dt><dd class="ppx-kv__lines">{{ $member->address ?: '—' }}</dd></div>
        </dl>
    @endif
</x-ppx.card>
