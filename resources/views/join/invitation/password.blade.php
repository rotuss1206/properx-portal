{{-- An open invitation link (Join\InvitationController::show): the person chooses their password. --}}
<div class="card narrow">
    <h2>Set up your account</h2>
    <p class="lead">Your agency has enrolled you in ProPerX Alliance. Setting up your member account takes three short steps:</p>
    <ol class="steps-mini">
        <li class="now">Choose a password</li>
        <li>Turn on two-factor sign-in with an authenticator app</li>
        <li>Check your details and accept the terms</li>
    </ol>

    <form method="POST" action="{{ route('join.invitation.password', $token) }}" novalidate>
        @csrf
        <div class="field">
            <span class="label" id="email-label">Email (you sign in with it)</span>
            <p class="readonly-value" aria-labelledby="email-label">{{ $email }}</p>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="new-password" required autofocus
                   aria-describedby="password-hint{{ $errors->has('password') ? ' password-error' : '' }}"
                   @if ($errors->has('password')) aria-invalid="true" @endif>
            <p class="hint" id="password-hint">At least 8 characters.</p>
            @error('password')<p class="error" id="password-error">{{ $message }}</p>@enderror
        </div>

        <div class="field">
            <label for="password_confirmation">Repeat the password</label>
            <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
        </div>

        <p class="hint">This link works once and expires on {{ $expiresAt->timezone(config('portal.schedule_timezone'))->format('F j, Y') }}.</p>

        <div class="actions">
            <button type="submit" class="btn btn-primary">Continue</button>
        </div>
    </form>
</div>
