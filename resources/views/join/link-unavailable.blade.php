{{-- The page of a "continue" link that has expired or been used (Join\ContinueEnrollmentController). --}}
<div class="card link-unavailable">
    <h2>This link can't be used any more</h2>
    <p class="lead">Links to continue an enrollment work once, and only while the saved answers are kept ({{ config('portal.registration.draft_days') }} days after the last change).</p>
    <ul class="link-unavailable-list">
        <li>If you used this link before on this device, your answers may still be open there.</li>
        <li>On the enrollment page, "Save and continue later" sends a new link to the principal's email.</li>
    </ul>
    <div class="actions">
        <a class="btn btn-primary" href="{{ route('join.agency') }}">Go to the enrollment</a>
    </div>
</div>
