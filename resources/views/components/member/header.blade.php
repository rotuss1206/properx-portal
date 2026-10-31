@props(['title'])
{{--
    The ProPerx header (public/portal/header.css), the same as in Nova (resources/js/nova/header.js):
    data from App\Support\PortalHeader. The bell shows the unread count (.ppx-header__count, the same
    look as Nova's bell) and opens the notifications panel (x-member.notify-panel, public/portal/notify.js).
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
            {{-- Own documents and the library (App\Member\MemberSearch); suggestions by public/portal/search.js,
                 Enter opens the results page. The query goes by POST, never into a URL. --}}
            <form class="ppx-header__search" method="POST" action="{{ route('member.search.submit') }}" role="search" data-ppx-search data-suggest="{{ route('member.search.suggest') }}" data-icons="{{ \App\Support\PortalAsset::url('img/icons.svg') }}">
                @csrf
                <x-member.icon name="search" class="ppx-header__search-icon" />
                <label class="ppx-visually-hidden" for="ppx-search-input">Search your documents and the library</label>
                <input id="ppx-search-input" type="search" name="q" placeholder="Search" autocomplete="off" maxlength="{{ \App\Member\MemberSearch::MAX_LENGTH }}"
                       role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="ppx-search-list" aria-describedby="ppx-search-help">
                <span class="ppx-visually-hidden" id="ppx-search-help">Type at least {{ \App\Member\MemberSearch::MIN_LENGTH }} characters. Use the arrow keys to choose a result, Enter to open it or to see all results.</span>
                <ul class="ppx-search__list" id="ppx-search-list" role="listbox" aria-label="Search suggestions" hidden></ul>
                <p class="ppx-visually-hidden" aria-live="polite" data-ppx-search-status></p>
            </form>
        @endif

        <button type="button" class="ppx-header__button ppx-header__bell" data-ppx-notify-toggle aria-controls="ppx-notify" aria-expanded="false" aria-label="Notifications{{ $header['unread'] ? ', '.$header['unread'].' unread' : '' }}">
            <span class="ppx-header__glyph ppx-header__glyph--bell" aria-hidden="true"></span>
            @if ($header['unread'])
                <span class="ppx-header__count" aria-hidden="true">{{ $header['unread'] > 99 ? '99+' : $header['unread'] }}</span>
            @endif
        </button>

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
