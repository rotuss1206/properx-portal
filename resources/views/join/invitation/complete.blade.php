{{-- The last step of an invitation (Join\InvitationController::completeForm): profile and consents. --}}
@php($field = fn (string $name) => $errors->has($name) ? 'aria-invalid=true aria-describedby='.str_replace('.', '-', $name).'-error' : '')
<div class="card narrow">
    <h2>Confirm your details</h2>
    <ol class="steps-mini">
        <li>Choose a password</li>
        <li>Turn on two-factor sign-in with an authenticator app</li>
        <li class="now">Check your details and accept the terms</li>
    </ol>

    <form method="POST" action="{{ route('join.invitation.accept') }}" novalidate>
        @csrf
        <fieldset class="form-block">
            <legend class="section-title">Contact</legend>
            <div class="grid grid-2">
                <div class="field">
                    <label for="first_name">First name</label>
                    <input type="text" id="first_name" name="first_name" value="{{ old('first_name', $profile->first_name) }}" autocomplete="given-name" required {{ $field('first_name') }}>
                    @error('first_name')<p class="error" id="first_name-error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="last_name">Last name</label>
                    <input type="text" id="last_name" name="last_name" value="{{ old('last_name', $profile->last_name) }}" autocomplete="family-name" required {{ $field('last_name') }}>
                    @error('last_name')<p class="error" id="last_name-error">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="field">
                <label for="phone">Phone <span class="optional">(optional)</span></label>
                <input type="tel" id="phone" name="phone" value="{{ old('phone', $profile->phone) }}" autocomplete="tel" {{ $field('phone') }}>
                @error('phone')<p class="error" id="phone-error">{{ $message }}</p>@enderror
            </div>
        </fieldset>

        @if ($producer)
            <fieldset class="form-block">
                <legend class="section-title">License</legend>
                <p class="hint">Your agency entered these. Please check them: ProPerX verifies your NPN and resident state.</p>
                <div class="grid grid-2 grid-spaced">
                    <div class="field">
                        <label for="npn">National Producer Number (NPN)</label>
                        <input type="text" id="npn" name="npn" value="{{ old('npn', $profile->npn) }}" inputmode="numeric" required {{ $field('npn') }}>
                        @error('npn')<p class="error" id="npn-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="license_state">Resident state</label>
                        <select id="license_state" name="license_state" required {{ $field('license_state') }}>
                            <option value="">Choose…</option>
                            @foreach ($states as $code => $name)
                                <option value="{{ $code }}" @selected(old('license_state', $profile->license_state) === $code)>{{ $name }}</option>
                            @endforeach
                        </select>
                        @error('license_state')<p class="error" id="license_state-error">{{ $message }}</p>@enderror
                    </div>
                </div>
            </fieldset>
        @endif

        <fieldset class="form-block consents">
            <legend class="section-title">Terms</legend>
            @foreach ($consents as $key => $consent)
                <label class="check" for="consent-{{ $key }}">
                    <input type="checkbox" id="consent-{{ $key }}" name="consents[{{ $key }}]" value="1" @checked(old("consents.{$key}")) {{ $field("consents.{$key}") }}>
                    <span>{{ $consent['label'] }} (<a href="{{ $consent['url'] }}" target="_blank" rel="noopener">read it</a>).</span>
                </label>
                @error("consents.{$key}")<p class="error" id="consents-{{ $key }}-error">{{ $message }}</p>@enderror
            @endforeach
        </fieldset>

        <div class="actions">
            <button type="submit" class="btn btn-primary">Finish and open the portal</button>
        </div>
    </form>
</div>
