{{-- An invitation link that cannot be used (Join\InvitationController): what happened and what to do. --}}
<div class="card narrow">
    @if ($asked === 'asked')
        <h2>We've asked for a new invitation</h2>
        <p class="lead">Your agency's principal and ProPerX have been told. A new invitation will come to your email address; it replaces this one.</p>
    @elseif ($asked === 'asked_before')
        <h2>A new invitation has been asked for</h2>
        <p class="lead">Someone has already asked for a new invitation from this link recently. It will come to your email address; please check your inbox and spam folder.</p>
    @elseif ($active)
        <h2>Your account is already set up</h2>
        <p class="lead">This invitation has been used. Sign in with your email address and password.</p>
    @elseif ($state === 'used')
        <h2>This link has already been used</h2>
        <p class="lead">A password was set with this invitation link. Sign in with your email address and that password to finish setting up your account, or ask for a new invitation.</p>
    @elseif ($state === 'revoked')
        <h2>This link has been replaced</h2>
        <p class="lead">A newer invitation was sent to your email address; please use the link in the latest email. If you can't find it, ask for a new invitation.</p>
    @elseif ($state === 'expired')
        <h2>This invitation has expired</h2>
        <p class="lead">Invitation links work for {{ config('portal.invitations.link_days') }} days. Ask for a new one and it will come to your email address.</p>
    @else
        <h2>This link can't be used</h2>
        <p class="lead">The invitation link is not valid. Please check that you opened the whole link from the email, or ask your agency's principal to contact ProPerX for a new invitation.</p>
    @endif

    <div class="actions">
        @if ($token !== null && ! $active && $asked === null)
            <form method="POST" action="{{ route('join.invitation.request', $token) }}">
                @csrf
                <button type="submit" class="btn btn-primary">Ask for a new invitation</button>
            </form>
        @endif
        @if ($active || $state === 'used')
            <a class="btn {{ $active ? 'btn-primary' : 'btn-secondary' }}" href="{{ \Laravel\Nova\Nova::url('/login') }}">Sign in</a>
        @endif
    </div>
</div>
