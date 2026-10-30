@props(['title'])
@php
    $user = auth()->user();
    $person = \App\Support\PortalSidebar::for($user)['user'];
    $today = now(config('portal.schedule_timezone'));
@endphp
<header class="ppx-header">
    <button type="button" class="ppx-header__menu" data-ppx-drawer-open aria-controls="ppx-sidebar" aria-expanded="false" aria-label="Open menu">
        <x-member.icon name="menu" class="ppx-header__icon" />
    </button>

    <h1 class="ppx-header__title">{{ $title }}</h1>

    <div class="ppx-header__tools">
        <time class="ppx-header__date" datetime="{{ $today->toDateString() }}">{{ $today->format('l, j F Y') }}</time>

        @if (config('portal.member_area.header_search'))
            <label class="ppx-header__search">
                <x-member.icon name="search" class="ppx-header__search-icon" />
                <span class="ppx-visually-hidden">Search</span>
                <input type="search" placeholder="Search" disabled>
            </label>
        @endif

        <a class="ppx-header__button" href="{{ route('member.notifications') }}" aria-label="Notifications">
            <x-member.icon name="bell" class="ppx-header__icon" />
        </a>

        <div class="ppx-header__account">
            <button type="button" class="ppx-header__avatar-button" data-ppx-account aria-haspopup="true" aria-expanded="false" aria-controls="ppx-account-menu">
                <span class="ppx-header__avatar" aria-hidden="true">{{ $person['initials'] ?? '' }}</span>
                <span class="ppx-visually-hidden">Account menu for {{ $person['name'] ?? '' }}</span>
                <x-member.icon name="chevron-down" class="ppx-header__chevron" />
            </button>

            <div class="ppx-header__menu-panel" id="ppx-account-menu" hidden>
                <a class="ppx-header__menu-item" href="{{ route('member.profile') }}">
                    <x-member.icon name="profile" class="ppx-header__menu-icon" /> Profile
                </a>
                <a class="ppx-header__menu-item" href="{{ route('nova.pages.user-security') }}">
                    <x-member.icon name="shield" class="ppx-header__menu-icon" /> Security
                </a>
                <form method="POST" action="{{ route('nova.logout') }}">
                    @csrf
                    <button type="submit" class="ppx-header__menu-item">
                        <x-member.icon name="sign-out" class="ppx-header__menu-icon" /> Sign out
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
