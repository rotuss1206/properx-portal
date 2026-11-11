@php($banner = \App\Support\MembershipBanner::for(auth()->user()))
@if ($banner)
    <div class="ppx-notice ppx-notice--{{ $banner['level'] }} ppx-membership-banner" role="{{ $banner['level'] === 'danger' ? 'alert' : 'status' }}">
        <span>{{ $banner['message'] }}</span>
        @if ($banner['paymentUrl'])
            <a class="ppx-btn ppx-btn--primary ppx-btn--small" href="{{ $banner['paymentUrl'] }}" target="_blank" rel="noopener noreferrer">
                {{ $banner['level'] === 'danger' ? 'Renew now' : 'Pay now' }}
            </a>
        @endif
    </div>
@endif
