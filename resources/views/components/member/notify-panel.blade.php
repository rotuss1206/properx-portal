{{--
    The notifications panel (right column of the mockups), opened by the header bell on every
    member page: public/portal/notify.css + notify.js (the same panel runs in Nova, drawn by
    resources/js/nova/header.js). Opening a notification marks it read (member.notifications.open);
    "Mark all read" posts member.notifications.read-all. Nothing is marked read just by showing it.
    The "extra" slot holds the dashboard's upload form.
--}}
@props(['extra' => null])
@php
    $user = auth()->user();
    $items = \App\Member\NotificationFeed::latest($user);
    $unread = \App\Member\NotificationFeed::unreadCount($user);
@endphp
<div class="ppx-notify-backdrop" data-ppx-notify-close></div>
{{-- Outside the panel, so changes are announced while it is closed too. --}}
<p class="ppx-visually-hidden ppx-notify-live" aria-live="polite" data-ppx-notify-live>{{ $unread ? $unread.' unread notifications' : 'No unread notifications' }}</p>

<aside id="ppx-notify" class="ppx-notify" aria-labelledby="ppx-notify-title">
    <div class="ppx-notify__header">
        <h2 class="ppx-notify__title" id="ppx-notify-title" tabindex="-1" data-ppx-notify-title>Notifications</h2>
        <span class="ppx-notify__count" data-ppx-notify-count @if (! $unread) hidden @endif aria-hidden="true">{{ $unread > 99 ? '99+' : $unread }}</span>
        <button type="button" class="ppx-notify__close" data-ppx-notify-close aria-label="Close notifications">
            <x-member.icon name="close" class="ppx-notify__close-svg" />
        </button>
    </div>

    <div class="ppx-notify__body">
        <section class="ppx-notify__section" aria-label="Recent notifications">
            @if ($items->isEmpty())
                <div class="ppx-notify__empty">
                    <span class="ppx-notify__empty-icon"><x-member.icon name="bell" class="ppx-notify__empty-svg" /></span>
                    <p class="ppx-notify__empty-title">You are all caught up</p>
                    <p class="ppx-notify__empty-text">Updates about your documents and membership appear here.</p>
                </div>
            @else
                <ul class="ppx-notify__list">
                    @foreach ($items as $item)
                        <li @class(['ppx-notify__item', 'is-unread' => $item['unread']])>
                            <a class="ppx-notify__link" href="{{ $item['url'] }}">
                                <span class="ppx-notify__item-title">{{ $item['title'] }}</span>
                                <time class="ppx-notify__time" datetime="{{ $item['at']->toIso8601String() }}">{{ $item['at']->diffForHumans() }}</time>
                            </a>
                            @if ($item['unread'])
                                <span class="ppx-notify__dot"><span class="ppx-visually-hidden">Unread</span></span>
                            @endif
                        </li>
                    @endforeach
                </ul>

                <form class="ppx-notify__actions" method="POST" action="{{ route('member.notifications.read-all') }}" data-ppx-notify-readall @if (! $unread) hidden @endif>
                    @csrf
                    <button type="submit" class="ppx-notify__read-all">Mark all read</button>
                </form>
            @endif
        </section>

        {{ $extra }}
    </div>
</aside>
