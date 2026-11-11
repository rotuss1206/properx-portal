{{--
    The agency is enrolled (Agency spec 5.5): the agency, everyone on it with their invitation status, the
    receipt (the surcharge on its own line) and what happens next. The invitations themselves come next.
--}}
<div>
    <div class="card status-card">
        <span class="badge status-badge status-badge-ok">Enrolled</span>
        <h2>Your agency is enrolled</h2>
        @if ($enrolled)
            <p class="lead"><strong>{{ $enrolled->legal_name }}</strong> is now a member of ProPerX Alliance: {{ $enrolled->members->count() }} people, {{ $enrolled->tier->label() }}.</p>

            <h3 class="section-title">Your people</h3>
            <ol class="people">
                @foreach ($enrolled->members as $member)
                    <li class="person">
                        <div class="person-text">
                            <p class="person-name">
                                <strong>{{ trim($member->first_name.' '.$member->last_name) }}</strong>
                                @if ($member->agency_role === \App\Enums\AgencyRole::Principal)<span class="tag tag-principal">Principal</span>@endif
                                <span class="tag">{{ $member->person_type?->label() }}</span>
                            </p>
                            <p class="person-meta"><span>{{ $member->user?->email }}</span></p>
                        </div>
                        <p class="person-fixed">{{ $member->invite_status?->label() }}</p>
                    </li>
                @endforeach
            </ol>
        @endif

        @if ($receipt !== [])
            <h3 class="section-title">Receipt</h3>
            <dl class="payment-summary">
                <dt>Paid with</dt><dd>{{ $receipt['label'] ?? '—' }}</dd>
                <dt>Membership dues for the year</dt><dd>{{ \App\Support\Money::format((int) ($receipt['subtotal'] ?? 0)) }}</dd>
                @if (($receipt['surcharge'] ?? 0) > 0)
                    <dt>Credit card surcharge</dt><dd>{{ \App\Support\Money::format((int) $receipt['surcharge']) }}</dd>
                @endif
                <dt class="payment-total">Total paid</dt><dd class="payment-total">{{ \App\Support\Money::format((int) ($receipt['total'] ?? 0)) }}</dd>
            </dl>
            <p class="hint">A receipt will be emailed to {{ $enrolled?->billing_email ?? 'your billing email' }}.</p>
        @endif

        <h3 class="section-title">What happens next</h3>
        <ul class="next-steps">
            <li>Everyone on the list gets an email invitation to set a password, turn on two-factor sign-in and complete their profile.</li>
            <li>ProPerX checks each licensed producer's NPN and state.</li>
            <li>Your membership renews in a year at the same rate type; we'll remind you 30 days before.</li>
        </ul>
    </div>
</div>
