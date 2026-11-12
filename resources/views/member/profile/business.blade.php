<x-ppx.card title="Business details" heading="h2" id="business" class="ppx-profile__section">
    @unless ($editing === 'business')
        <x-slot:action>@include('member.profile.edit-button', ['section' => 'business', 'name' => 'business details'])</x-slot:action>
    @endunless

    @include('member.profile.section-open', ['section' => 'business'])

    @php
        // Staff change the agency and the role; agency employees hold no NPN.
        $inAgency = $member->agency_id !== null;
        $agencyRole = collect([$member->agency_role?->label(), $member->person_type?->label()])->filter()->implode(' · ');
    @endphp

    @if ($editing === 'business')
        @php
            $warned = (bool) session('license_review_warning');
            $focus = ! $errors->has('firm_name') && $errors->has('npn') ? 'npn' : 'firm_name';
        @endphp
        <form class="ppx-form" method="POST" action="{{ route('member.profile.business') }}" novalidate>
            @csrf
            @method('PATCH')

            @if ($warned)
                @include('member.profile.review-warning')
            @endif

            <x-ppx.form-row name="firm_name" label="Firm name" :value="old('firm_name', $member->firm_name)" autocomplete="organization" :autofocus="! $warned && $focus === 'firm_name'" />
            @if ($inAgency)
                <x-ppx.form-static label="Agency">{{ $member->agency?->legal_name ?: '—' }}</x-ppx.form-static>
                <x-ppx.form-static label="Role">{{ $agencyRole }}</x-ppx.form-static>
            @endif
            @if ($profile->holdsLicense())
                <x-ppx.form-row name="npn" label="National Producer Number" :value="old('npn', $member->npn)" inputmode="numeric" :hint="$member->agency_id !== null ? '7 to 10 digits, numbers only.' : 'Up to 10 digits, numbers only.'" required :autofocus="! $warned && $focus === 'npn'" />
            @endif

            {{-- After the warning the keyboard is on the decision, and it reads the warning out. --}}
            @include('member.profile.form-actions', ['section' => 'business', 'save' => $warned ? 'Save and send for review' : 'Save', 'focusSave' => $warned, 'describedBy' => $warned ? 'ppx-license-warning' : null])
        </form>
    @else
        <dl class="ppx-kv">
            <div class="ppx-kv__row"><dt>Firm name</dt><dd>{{ $member->firm_name ?: '—' }}</dd></div>
            @if ($inAgency)
                <div class="ppx-kv__row"><dt>Agency</dt><dd>{{ $member->agency?->legal_name ?: '—' }}</dd></div>
                <div class="ppx-kv__row"><dt>Role</dt><dd>{{ $agencyRole }}</dd></div>
            @endif
            @if ($profile->holdsLicense())
                <div class="ppx-kv__row"><dt>National Producer Number</dt><dd>{{ $member->npn ?: '—' }}</dd></div>
            @endif
        </dl>
    @endif
</x-ppx.card>
