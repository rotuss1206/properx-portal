<x-ppx.card title="Business details" heading="h2" id="business" class="ppx-profile__section">
    @unless ($editing === 'business')
        <x-slot:action>@include('member.profile.edit-button', ['section' => 'business', 'name' => 'business details'])</x-slot:action>
    @endunless

    @include('member.profile.section-open', ['section' => 'business'])

    @if ($editing === 'business')
        <form class="ppx-form" method="POST" action="{{ route('member.profile.business') }}" novalidate>
            @csrf
            @method('PATCH')
            <x-ppx.form-row name="firm_name" label="Firm name" :value="old('firm_name', $member->firm_name)" autocomplete="organization" autofocus />
            @include('member.profile.form-actions', ['section' => 'business'])
        </form>
    @else
        <dl class="ppx-kv">
            <div class="ppx-kv__row"><dt>Firm name</dt><dd>{{ $member->firm_name ?: '—' }}</dd></div>
        </dl>
    @endif

    {{-- Staff change these on the agency. --}}
    @if ($member->agency_id !== null)
        <dl class="ppx-kv ppx-kv--joined">
            <div class="ppx-kv__row"><dt>Agency</dt><dd>{{ $member->agency?->legal_name ?: '—' }}</dd></div>
            <div class="ppx-kv__row"><dt>Role</dt><dd>{{ collect([$member->agency_role?->label(), $member->person_type?->label()])->filter()->implode(' · ') }}</dd></div>
        </dl>
    @endif
</x-ppx.card>
