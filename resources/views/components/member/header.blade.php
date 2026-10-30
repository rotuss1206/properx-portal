@props(['title'])
{{--
    The ProPerx header (public/portal/header.css), the same as in Nova (resources/js/nova/header.js):
    data from App\Support\PortalHeader. The bell shows the unread count (.ppx-header__count, the same look as Nova's bell) and leads to the
    dashboard's Notifications panel, which marks them as read.
--}}
@php($header = \App\Support\PortalHeader::for(auth()->user(), 'member'))
<header class="ppx-header">
    <button type="button" class="ppx-header__menu" data-ppx-drawer-open aria-controls="ppx-sidebar" aria-expanded="false" aria-label="Open menu">
        <span class="ppx-header__glyph ppx-header__glyph--menu" aria-hidden="true"></span>
    </button>

    <h1 class="ppx-header__title">{{ $title }}</h1>

    <div class="ppx-header__tools">
        <time class="ppx-header__date" datetime="{{ $header['dateIso'] }}">{{ $header['date'] }}</time>

        @if ($header['search'])
            <label class="ppx-header__search">
                <x-member.icon name="search" class="ppx-header__search-icon" />
                <span class="ppx-visually-hidden">Search</span>
                <input type="search" placeholder="Search" disabled>
            </label>
        @endif

        <a class="ppx-header__button ppx-header__bell" href="{{ route('member.dashboard') }}#ppx-notifications" aria-label="Notifications{{ $header['unread'] ? ', '.$header['unread'].' new' : '' }}">
            <span class="ppx-header__glyph ppx-header__glyph--bell" aria-hidden="true"></span>
            @if ($header['unread'])
                <span class="ppx-header__count" aria-hidden="true">{{ $header['unread'] > 99 ? '99+' : $header['unread'] }}</span>
            @endif
        </a>

        <div class="ppx-header__account">
            <button type="button" class="ppx-header__avatar-button" data-ppx-account aria-haspopup="true" aria-expanded="false" aria-controls="ppx-account-menu">
                <span class="ppx-header__avatar" aria-hidden="true">{{ $header['initials'] }}</span>
                <span class="ppx-visually-hidden">Account menu for {{ $header['name'] }}</span>
                <x-member.icon name="chevron-down" class="ppx-header__chevron" />
            </button>

            <div class="ppx-header__menu-panel" id="ppx-account-menu" hidden>
                <a class="ppx-header__menu-item" href="{{ $header['profileUrl'] }}">
                    <x-member.icon name="profile" class="ppx-header__menu-icon" /> Profile
                </a>
                <a class="ppx-header__menu-item" href="{{ $header['securityUrl'] }}">
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
