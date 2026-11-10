{{--
    Screen 5 · People. The principal is person 1, read-only here (screen 4). Everyone else is added
    one at a time through the form below the list; Enter in its text fields adds the person, it does not
    continue. Row errors from "Continue" show under each row. App\Livewire\AgencyRegistration\PeopleForm.
--}}
@php
    $people = $this->people;
    $headcount = $people->headcount();
    $editing = isset($people->rows[$people->editing]);
    $isProducer = ($people->entry['type'] ?? '') === \App\Enums\PersonType::LicensedProducer->value;
    $fields = ['type' => 'Person type', 'first_name' => 'First name', 'last_name' => 'Last name', 'email' => 'Email', 'npn' => 'NPN', 'license_state' => 'State'];
@endphp
<h2>Add your people</h2>
<p class="lead">You are person 1. Add each licensed producer and agency employee who joins with you. After payment, everyone gets an email invitation to set up their account.</p>

<p class="sr-only" role="status">{{ $peopleStatus }}</p>

@if ($tierNotice)
    <p @class(['notice-ok' => $tierMovedUp, 'notice-info' => ! $tierMovedUp, 'tier-notice']) role="status">{{ $tierNotice }}</p>
@endif

<h3 class="section-title people-title" id="people-list-title">Your people <span class="count">{{ $headcount }} {{ $headcount === 1 ? 'person' : 'people' }}</span></h3>
<ol class="people" aria-labelledby="people-list-title">
    <li class="person">
        <div class="person-text">
            <p class="person-name">
                <strong>{{ trim($principal->first_name.' '.$principal->last_name) ?: 'Principal' }}</strong>
                <span class="tag tag-principal">Principal</span>
                <span class="tag">{{ $personTypes[\App\Enums\PersonType::LicensedProducer->value] }}</span>
            </p>
            <p class="person-meta">
                <span>{{ $principal->email }}</span>
                @if ($principal->npn !== '')<span>NPN {{ $principal->npn }}</span>@endif
                @if ($principal->license_state !== '')<span>{{ $states[$principal->license_state] ?? $principal->license_state }}</span>@endif
            </p>
        </div>
        <p class="person-fixed">Person 1 · <button type="button" class="link" wire:click="goTo(4)">Change on step 4</button></p>
    </li>
    @foreach ($people->rows as $index => $row)
        @php($rowErrors = collect($fields)->map(fn ($label, $field) => $errors->first("people.rows.{$index}.{$field}"))->filter())
        @php($name = trim($row['first_name'].' '.$row['last_name']))
        <li @class(['person', 'has-error' => $rowErrors->isNotEmpty(), 'is-editing' => $people->editing === $index]) wire:key="person-{{ $index }}">
            <div class="person-text">
                <p class="person-name">
                    <strong>{{ $name !== '' ? $name : 'Person '.($index + 2) }}</strong>
                    @if (isset($personTypes[$row['type']]))<span class="tag">{{ $personTypes[$row['type']] }}</span>@endif
                    @if ($people->editing === $index)<span class="tag tag-editing">Editing</span>@endif
                </p>
                <p class="person-meta">
                    <span>{{ $row['email'] }}</span>
                    @if ($row['npn'] !== '')<span>NPN {{ $row['npn'] }}</span>@endif
                    @if ($row['license_state'] !== '')<span>{{ $states[$row['license_state']] ?? $row['license_state'] }}</span>@endif
                </p>
                @if ($rowErrors->isNotEmpty())
                    <ul class="person-errors" id="person-{{ $index }}-errors">
                        @foreach ($rowErrors as $field => $message)
                            <li class="error">{{ $fields[$field] }}: {{ $message }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div class="person-actions">
                <button type="button" class="btn-small" id="person-{{ $index }}-edit" wire:click="editPerson({{ $index }})"
                    @if ($rowErrors->isNotEmpty()) aria-describedby="person-{{ $index }}-errors" @endif>Edit<span class="sr-only"> {{ $name }}</span></button>
                <button type="button" class="btn-small btn-remove" wire:click="removePerson({{ $index }})">Remove<span class="sr-only"> {{ $name }}</span></button>
            </div>
        </li>
    @endforeach
</ol>

@if (! $people->minimumMet())
    <p class="notice-info minimum" id="people-minimum">
        Agency membership needs at least {{ $minimumHeadcount }} people. Add someone else
        @if ($individualUrl)
            or <a href="{{ $individualUrl }}">switch to individual membership</a>.
        @else
            or switch to individual membership on properx.org.
        @endif
    </p>
@endif
<x-join.error name="people.rows" />

<div class="person-form">
    <h3 class="section-title" id="person-form-title" tabindex="-1">{{ $editing ? 'Edit person '.($people->editing + 2) : 'Add a person' }}</h3>
    <x-join.error name="people.entry" />

    <fieldset @if ($errors->has('people.entry.type')) aria-describedby="people-entry-type-error" @endif>
        <legend class="label">Person type</legend>
        <div class="choices choices-compact">
            @foreach ($personTypes as $value => $label)
                <label class="choice">
                    <input type="radio" name="people-entry-type" wire:model.live="people.entry.type" value="{{ $value }}">
                    <strong>{{ $label }}</strong>
                    <span>{{ $value === \App\Enums\PersonType::LicensedProducer->value ? 'Holds a resident insurance license (NPN).' : 'Works for the agency, no license needed.' }}</span>
                </label>
            @endforeach
        </div>
        <x-join.error name="people.entry.type" />
    </fieldset>

    <div class="grid grid-2">
        <x-join.input name="people.entry.first_name" wire:keydown.enter.prevent="savePerson" label="First name" required autocomplete="off" maxlength="100" />
        <x-join.input name="people.entry.last_name" wire:keydown.enter.prevent="savePerson" label="Last name" required autocomplete="off" maxlength="100" />
    </div>
    <x-join.input name="people.entry.email" wire:keydown.enter.prevent="savePerson" type="email" label="Email" required autocomplete="off"
        hint="Their invitation goes here. Everyone needs their own email." />

    @if ($isProducer)
        <div class="grid grid-2">
            <x-join.input name="people.entry.npn" wire:keydown.enter.prevent="savePerson" label="Individual NPN" required inputmode="numeric" maxlength="10" autocomplete="off"
                hint="Up to 10 digits." />
            <x-join.select name="people.entry.license_state" label="Resident license state" :options="$states" required />
        </div>
    @endif

    <div class="person-form-actions">
        <button type="button" class="btn btn-secondary" wire:click="savePerson" wire:loading.attr="disabled">{{ $editing ? 'Save changes' : 'Add person' }}</button>
        @if ($editing)
            <button type="button" class="link" wire:click="clearPerson">Cancel</button>
        @elseif (! $people->entryIsEmpty())
            <button type="button" class="link" wire:click="clearPerson">Clear the form</button>
        @endif
    </div>
</div>
