{{--
    The member's profile (docs/design/Main.jpg): banner, then one card per section. A section opens
    for editing in place with ?edit=section; Save posts a plain form, Cancel is a link back.
    App\Member\ProfilePage says what this person may edit.
--}}
<x-member.layout :page="$page" :title="$title">
    <div class="ppx-profile">
        <section class="ppx-profile-banner" aria-label="Your membership">
            <span class="ppx-profile-banner__avatar" aria-hidden="true">{{ $profile->initials() }}</span>
            <div class="ppx-profile-banner__text">
                <h2 class="ppx-profile-banner__name">{{ $member?->fullName() ?: auth()->user()->name }}</h2>
                <p class="ppx-profile-banner__caption">{{ $profile->caption() }}</p>
                <p class="ppx-profile-banner__badges">
                    @if ($member?->member_number)
                        <span class="ppx-profile-banner__number">Member #{{ $member->member_number }}</span>
                    @endif
                    <x-ppx.badge :variant="'on-navy-'.$membership['variant']">{{ $membership['label'] }}</x-ppx.badge>
                    @if ($membership['founding'])
                        <x-ppx.badge variant="founding" :dot="false">Founding member</x-ppx.badge>
                    @endif
                </p>
            </div>
        </section>

        @if ($member)
            @include('member.profile.contact')
            @include('member.profile.business')
            @include('member.profile.license')
        @endif
        @include('member.profile.security')
        @if ($member)
            @include('member.profile.notifications')
        @endif
    </div>
</x-member.layout>
