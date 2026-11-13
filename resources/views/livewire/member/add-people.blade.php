{{--
    Add people (App\Livewire\Member\AddPeople): the list with the add form and the CSV, then the
    cost and "Pay". Field ids follow the enrollment's ("people-entry-first_name"), so the shared
    list logic (ManagesPeopleList) moves the focus the same way.
--}}
@php
    use App\Support\Money;

    $people = $this->people;
    $editing = isset($people->rows[$people->editing]);
    $isProducer = ($people->entry['type'] ?? '') === \App\Enums\PersonType::LicensedProducer->value;
    $fields = ['type' => 'Person type', 'first_name' => 'First name', 'last_name' => 'Last name', 'email' => 'Email', 'npn' => 'NPN', 'license_state' => 'State'];
    $field = fn (string $name) => $errors->first('people.entry.'.$name);
@endphp
<div class="ppx-add">
    <p class="ppx-visually-hidden" role="status">{{ $peopleStatus }}</p>

    @if ($message)
        <p class="ppx-notice ppx-notice--danger" id="add-people-message" role="alert" tabindex="-1">{{ $message }}</p>
    @endif

    @if ($stage === 'list')
        <x-ppx.card title="People to add" heading="h2">
            <x-slot:action><span class="ppx-card__note">{{ count($people->rows) }} on the list · {{ $agency->headcount }} in the agency now</span></x-slot:action>

            @if ($people->rows === [])
                <x-ppx.empty-state icon="profile" title="Nobody on the list yet" text="Add each licensed producer and agency employee below, or upload a CSV. Everyone gets an email invitation once the payment goes through." />
            @else
                <ol class="ppx-add__list">
                    @foreach ($people->rows as $index => $row)
                        @php($rowErrors = collect($fields)->map(fn ($label, $key) => $errors->first("people.rows.{$index}.{$key}"))->filter())
                        @php($name = trim($row['first_name'].' '.$row['last_name']))
                        <li @class(['ppx-add__person', 'has-error' => $rowErrors->isNotEmpty(), 'is-editing' => $people->editing === $index]) wire:key="person-{{ $index }}">
                            <span class="ppx-add__text">
                                <span class="ppx-agency__person">{{ $name }} <x-ppx.badge :variant="$row['type'] === 'agency_employee' ? 'neutral' : 'success'" :dot="false">{{ $personTypes[$row['type']] ?? $row['type'] }}</x-ppx.badge></span>
                                <span class="ppx-agency__email">{{ $row['email'] }}@if ($row['npn'] !== '') · NPN {{ $row['npn'] }}@endif @if ($row['license_state'] !== '') · {{ $states[$row['license_state']] ?? $row['license_state'] }}@endif</span>
                                @foreach ($rowErrors as $key => $error)
                                    <span class="ppx-agency__reason">{{ $fields[$key] }}: {{ $error }}</span>
                                @endforeach
                            </span>
                            <span class="ppx-add__actions">
                                <x-ppx.button variant="secondary" size="sm" id="person-{{ $index }}-edit" wire:click="editPerson({{ $index }})">Edit<span class="ppx-visually-hidden"> {{ $name }}</span></x-ppx.button>
                                <x-ppx.button variant="secondary" size="sm" wire:click="removePerson({{ $index }})">Remove<span class="ppx-visually-hidden"> {{ $name }}</span></x-ppx.button>
                            </span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-ppx.card>

        @if ($people->problems !== [])
            <x-ppx.card title="Rows to fix" heading="h2">
                <x-slot:action><button type="button" class="ppx-link" wire:click="skipAllProblems">Skip all</button></x-slot:action>
                <h3 class="ppx-visually-hidden" id="problems-title" tabindex="-1">Rows to fix</h3>
                <ol class="ppx-add__list">
                    @foreach ($people->problems as $index => $problem)
                        <li class="ppx-add__person has-error" wire:key="problem-{{ $index }}-{{ $problem['line'] }}">
                            <span class="ppx-add__text">
                                <span class="ppx-agency__person">Row {{ $problem['line'] }} · {{ trim($problem['row']['first_name'].' '.$problem['row']['last_name']) ?: 'No name' }}</span>
                                @foreach ($problem['errors'] as $key => $error)
                                    <span class="ppx-agency__reason">{{ $fields[$key] ?? $key }}: {{ $error }}</span>
                                @endforeach
                            </span>
                            <span class="ppx-add__actions">
                                <x-ppx.button variant="secondary" size="sm" id="problem-{{ $index }}-fix" wire:click="fixProblem({{ $index }})">Fix</x-ppx.button>
                                <x-ppx.button variant="secondary" size="sm" wire:click="skipProblem({{ $index }})">Skip</x-ppx.button>
                            </span>
                        </li>
                    @endforeach
                </ol>
            </x-ppx.card>
        @endif

        <x-ppx.card :title="$editing ? 'Edit person' : 'Add a person'" heading="h2">
            <h3 class="ppx-visually-hidden" id="person-form-title" tabindex="-1">{{ $editing ? 'Edit person' : 'Add a person' }}</h3>
            <div class="ppx-form">
                @error('people.entry')<p class="ppx-field__error">{{ $message }}</p>@enderror
                <div class="ppx-form__row" role="radiogroup" aria-labelledby="people-entry-type-label">
                    <span class="ppx-form__label" id="people-entry-type-label">Person type</span>
                    <div class="ppx-form__control ppx-add__types">
                        @foreach ($personTypes as $value => $label)
                            <label class="ppx-check">
                                <input type="radio" name="people-entry-type" wire:model.live="people.entry.type" value="{{ $value }}">
                                <span><strong>{{ $label }}</strong> · {{ $value === 'licensed_producer' ? 'holds a resident insurance license (NPN)' : 'works for the agency, no license' }}</span>
                            </label>
                        @endforeach
                        @if ($field('type'))<p class="ppx-field__error">{{ $field('type') }}</p>@endif
                    </div>
                </div>
                @foreach (['first_name' => 'First name', 'last_name' => 'Last name', 'email' => 'Email'] as $name => $label)
                    <div class="ppx-form__row">
                        <label class="ppx-form__label" for="people-entry-{{ $name }}">{{ $label }}</label>
                        <div class="ppx-form__control">
                            <input class="ppx-field__input" id="people-entry-{{ $name }}" type="{{ $name === 'email' ? 'email' : 'text' }}" wire:model="people.entry.{{ $name }}" wire:keydown.enter.prevent="savePerson" autocomplete="off" @if ($field($name)) aria-invalid="true" @endif>
                            @if ($field($name))<p class="ppx-field__error">{{ $field($name) }}</p>@endif
                        </div>
                    </div>
                @endforeach
                @if ($isProducer)
                    <div class="ppx-form__row">
                        <label class="ppx-form__label" for="people-entry-npn">Individual NPN</label>
                        <div class="ppx-form__control">
                            <input class="ppx-field__input" id="people-entry-npn" inputmode="numeric" maxlength="10" wire:model="people.entry.npn" wire:keydown.enter.prevent="savePerson" autocomplete="off" @if ($field('npn')) aria-invalid="true" @endif>
                            @if ($field('npn'))<p class="ppx-field__error">{{ $field('npn') }}</p>@else<p class="ppx-field__hint">7 to 10 digits.</p>@endif
                        </div>
                    </div>
                    <div class="ppx-form__row">
                        <label class="ppx-form__label" for="people-entry-license_state">Resident license state</label>
                        <div class="ppx-form__control">
                            <select class="ppx-field__input" id="people-entry-license_state" wire:model="people.entry.license_state">
                                <option value="">Choose…</option>
                                @foreach ($states as $code => $state)
                                    <option value="{{ $code }}">{{ $state }}</option>
                                @endforeach
                            </select>
                            @if ($field('license_state'))<p class="ppx-field__error">{{ $field('license_state') }}</p>@endif
                        </div>
                    </div>
                @endif
                <div class="ppx-form__actions">
                    <x-ppx.button variant="secondary" wire:click="savePerson">{{ $editing ? 'Save changes' : 'Add to the list' }}</x-ppx.button>
                    @if ($editing || ! $people->entryIsEmpty())
                        <button type="button" class="ppx-link" wire:click="clearPerson">{{ $editing ? 'Cancel' : 'Clear the form' }}</button>
                    @endif
                </div>
            </div>

            <div class="ppx-add__csv">
                <p class="ppx-field__hint" id="csv-hint">Or upload a spreadsheet: one person per row, as CSV (UTF-8, up to 1 MB). People already on the list are not added twice.</p>
                <div class="ppx-add__csv-actions">
                    <x-ppx.button variant="secondary" size="sm" :href="route('member.agency.people-template')" download>Download template</x-ppx.button>
                    <label class="ppx-btn ppx-btn--secondary ppx-btn--sm ppx-add__upload">
                        Upload CSV
                        <input class="ppx-visually-hidden" type="file" accept=".csv,text/csv" data-join-csv data-status="csv-client-status" aria-describedby="csv-hint" wire:ignore>
                    </label>
                </div>
                <p class="ppx-field__error" id="csv-client-status" role="status" hidden wire:ignore></p>
                @if ($importMessage)
                    <p @class(['ppx-notice', 'ppx-notice--success' => $importOk, 'ppx-notice--warning' => ! $importOk]) id="csv-status" role="status" tabindex="-1">{{ $importMessage }}</p>
                @endif
            </div>
        </x-ppx.card>

        <div class="ppx-add__next">
            <x-ppx.button variant="primary" wire:click="reviewCost" wire:loading.attr="disabled">Review the cost</x-ppx.button>
            <x-ppx.button variant="secondary" :href="route('member.agency')">Cancel</x-ppx.button>
        </div>
    @else
        <x-ppx.card title="Review and pay" heading="h2">
            <div class="ppx-add__review" id="add-people-review" tabindex="-1">
                <p>
                    {{ $review['added'] }} {{ $review['added'] === 1 ? 'person' : 'people' }} to add
                    ({{ $review['producers'] }} {{ $review['producers'] === 1 ? 'producer' : 'producers' }}, {{ $review['employees'] }} {{ $review['employees'] === 1 ? 'employee' : 'employees' }}):
                    your agency goes from {{ $review['before'] }} to {{ $review['after'] }} people.
                </p>
                @if ($review['tier_changes'])
                    <p class="ppx-notice ppx-notice--success">Your agency moves up to {{ $review['tier_after'] }}: everyone's rate becomes {{ Money::format($review['rate']) }} a year now, prorated below for the rest of the year.</p>
                @endif
                @if ($review['no_charge'])
                    <p class="ppx-notice ppx-notice--success">With everyone at the lower rate the change would come out below zero, so nothing is charged today and nothing is credited. The new rate applies from your next renewal.</p>
                @endif
                <dl class="ppx-docs__facts ppx-add__sum">
                    <div class="ppx-docs__fact"><dt>Rate</dt><dd>{{ Money::format($review['rate']) }} per person a year ({{ $review['tier_after'] }})</dd></div>
                    @foreach ($review['lines'] as $line)
                        <div class="ppx-docs__fact ppx-add__line"><dt>{{ $line['description'] }}</dt><dd>{{ Money::exact($line['amount']) }}</dd></div>
                    @endforeach
                    <div class="ppx-docs__fact"><dt>Prorated until your renewal on {{ $review['period_ends'] }}</dt><dd>{{ Money::exact($review['subtotal']) }}</dd></div>
                    @if ($review['surcharge'] > 0)
                        <div class="ppx-docs__fact"><dt>Credit card surcharge {{ rtrim(rtrim(number_format(config('agency.credit_card_surcharge_basis_points') / 100, 2), '0'), '.') }}%</dt><dd>{{ Money::exact($review['surcharge']) }}</dd></div>
                    @endif
                    <div class="ppx-docs__fact ppx-add__total"><dt>Total today</dt><dd>{{ Money::exact($review['total']) }}</dd></div>
                    <div class="ppx-docs__fact"><dt>Paid with</dt><dd class="ppx-add__method">{{ $review['method'] }}</dd></div>
                </dl>
                <p class="ppx-field__hint">The saved payment method is charged at once. If it does not go through, nothing changes. Everyone added gets an email invitation; you get a receipt.</p>
                <div class="ppx-add__next">
                    <x-ppx.button variant="primary" wire:click="pay" wire:loading.attr="disabled">Pay {{ Money::exact($review['total']) }} and add</x-ppx.button>
                    <x-ppx.button variant="secondary" wire:click="backToList">Back to the list</x-ppx.button>
                </div>
            </div>
        </x-ppx.card>
    @endif
</div>
