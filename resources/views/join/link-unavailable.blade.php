{{--
    The page of a link that has expired or been used: a "continue" link (Join\ContinueEnrollmentController)
    or the one-time link from properx.org/apply (Join\StartEnrollmentController, $handoff set).
--}}
<div class="card link-unavailable">
    <h2>This link can't be used any more</h2>
    @if ($handoff ?? false)
        <p class="lead">The link from the application on properx.org works once, for {{ config('portal.registration.handoff.link_minutes') }} minutes.</p>
        <ul class="link-unavailable-list">
            <li>If you opened it before on this device, your enrollment may still be open there.</li>
            <li>Otherwise, start again on properx.org and choose "My agency". Nothing has been charged.</li>
        </ul>
        <div class="actions">
            @if (! empty($applyUrl))
                <a class="btn btn-primary" href="{{ $applyUrl }}">Start again on properx.org</a>
            @else
                <a class="btn btn-primary" href="{{ route('join.agency') }}">Go to the enrollment</a>
            @endif
        </div>
    @else
        <p class="lead">Links to continue an enrollment work once, and only while the saved answers are kept ({{ config('portal.registration.draft_days') }} days after the last change).</p>
        <ul class="link-unavailable-list">
            <li>If you used this link before on this device, your answers may still be open there.</li>
            <li>On the enrollment page, "Save and continue later" sends a new link to the principal's email.</li>
        </ul>
        <div class="actions">
            <a class="btn btn-primary" href="{{ route('join.agency') }}">Go to the enrollment</a>
        </div>
    @endif
</div>
