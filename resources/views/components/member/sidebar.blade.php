@props(['page'])
{{--
    The ProPerx sidebar: same markup and classes as the Nova sidebar (resources/js/nova/sidebar.js),
    styled by public/portal/sidebar.css. Items: App\Member\MemberMenu; bottom: App\Support\PortalSidebar.
--}}
@php
    $user = auth()->user();
    $items = \App\Member\MemberMenu::items($user, $page);
    $sidebar = \App\Support\PortalSidebar::for($user);
    $renewal = $sidebar['renewal'];
    $person = $sidebar['user'];
@endphp
<nav id="ppx-sidebar" @class(['ppx-sidebar', 'ppx-sidebar--desktop', 'has-renewal' => $renewal]) aria-label="Main">
    <img class="ppx-sidebar__decor" src="{{ asset('portal/img/login-art.svg') }}" alt="">

    <div class="ppx-sidebar__top">
        <a class="ppx-sidebar__logo" href="{{ route('member.dashboard') }}">
            <img class="ppx-sidebar__logo-image" src="{{ $sidebar['logo'] }}" alt="ProPerx Alliance">
        </a>
        <button type="button" class="ppx-sidebar__collapse" data-ppx-collapse aria-controls="ppx-sidebar" aria-expanded="true" aria-label="Collapse sidebar">
            <x-member.icon name="collapse" class="ppx-sidebar__collapse-svg" view-box="0 0 18 24" stroke="1.5" />
        </button>
        <button type="button" class="ppx-member__drawer-close" data-ppx-drawer-close aria-label="Close menu">
            <x-member.icon name="close" class="ppx-member__drawer-close-svg" />
        </button>
    </div>

    <div class="ppx-sidebar__menu">
        <p class="ppx-sidebar__label">Menu</p>
        <ul class="ppx-sidebar__list">
            @foreach ($items as $item)
                <li>
                    <a href="{{ $item['url'] }}" @class(['ppx-sidebar__item', 'is-active' => $item['active']]) @if ($item['active']) aria-current="page" @endif data-ppx-label="{{ $item['label'] }}">
                        <span class="ppx-sidebar__icon"><x-member.icon :name="$item['icon']" class="ppx-sidebar__svg" /></span>
                        <span class="ppx-sidebar__text">{{ $item['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>

    <div class="ppx-sidebar__bottom">
        @if ($renewal)
            <section class="ppx-renewal" aria-label="Renewal due">
                <div class="ppx-renewal__head">
                    <span class="ppx-renewal__icon"><x-member.icon name="calendar" class="ppx-renewal__svg" /></span>
                    <strong class="ppx-renewal__title">Renewal due</strong>
                </div>
                <p class="ppx-renewal__text">
                    @if ($renewal['days'] === 0)
                        Membership renews today.
                    @else
                        Membership renews in {{ $renewal['days'] }} {{ $renewal['days'] === 1 ? 'day' : 'days' }}.
                    @endif
                </p>
                @if ($renewal['paymentUrl'])
                    <a class="ppx-renewal__button" href="{{ $renewal['paymentUrl'] }}" target="_blank" rel="noopener noreferrer">Renew now</a>
                @endif
            </section>
        @endif

        @if ($person)
            <div class="ppx-user">
                <span class="ppx-user__avatar" aria-hidden="true">{{ $person['initials'] }}</span>
                <span class="ppx-user__text">
                    <span class="ppx-user__name">{{ $person['name'] }}</span>
                    @if ($person['caption'])
                        <span class="ppx-user__caption">{{ $person['caption'] }}</span>
                    @endif
                </span>
            </div>
        @endif
    </div>
</nav>
