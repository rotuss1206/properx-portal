{{--
    My agency (principal only; App\Member\AgencyPage): the summary in the dashboard's cards, the
    people in the style of Documents Management.jpg (chips by status, search in the browser with
    public/portal/documents.js, nothing sent), and the agency's details with an edit form
    (?edit=details; screen 3's fields, the FEIN read-only).
--}}
@php
    use App\Support\Money;
    use App\Support\UsStates;
@endphp
<x-member.layout :page="$page" :title="$title">
    <div class="ppx-agency">
        <div class="ppx-agency__intro">
            <p class="ppx-docs__lead">Your agency’s membership, its people and its details.</p>
            <div class="ppx-agency__add">
                <x-ppx.button variant="secondary" icon="profile" aria-disabled="true" disabled>Add people — coming soon</x-ppx.button>
                <span>
                    To add or remove people now,
                    @if ($supportUrl)
                        <a href="{{ $supportUrl }}" target="_blank" rel="noopener">contact ProPerX<span class="ppx-visually-hidden"> (opens in a new tab)</span></a>.
                    @else
                        contact ProPerX.
                    @endif
                </span>
            </div>
        </div>

        @if (session('status'))
            <p class="ppx-notice ppx-notice--success" role="status">{{ session('status') }}</p>
        @endif
        @if (session('agency_error'))
            <p class="ppx-notice ppx-notice--danger" role="alert">{{ session('agency_error') }}</p>
        @endif

        <div class="ppx-stats" aria-label="Agency summary">
            <section class="ppx-stat ppx-stat--hero" aria-labelledby="ppx-agency-name">
                <span class="ppx-stat__icon"><x-member.icon name="badge-check" class="ppx-stat__svg" /></span>
                <h2 class="ppx-stat__eyebrow">Agency</h2>
                <p class="ppx-stat__value" id="ppx-agency-name">{{ $agency->legal_name }}</p>
                <p class="ppx-stat__meta">
                    <x-ppx.badge :variant="'on-navy-'.$summary['variant']">{{ $summary['status'] }}</x-ppx.badge>
                    <span>{{ $summary['paid_through'] ? 'Paid through '.$summary['paid_through']->format('j M Y') : 'Paid through —' }}</span>
                </p>
            </section>

            <section class="ppx-stat" aria-labelledby="ppx-agency-tier">
                <span class="ppx-stat__icon ppx-stat__icon--green"><x-member.icon name="billing" class="ppx-stat__svg" /></span>
                <h2 class="ppx-stat__eyebrow" id="ppx-agency-tier">Tier and rate</h2>
                <p class="ppx-stat__value">{{ $summary['tier'] ?? '—' }}</p>
                <p class="ppx-stat__meta">
                    @if ($summary['rate'] !== null)
                        <span>{{ Money::format($summary['rate']) }} per person a year · {{ $summary['rate_kind'] }}</span>
                    @endif
                </p>
            </section>

            <section class="ppx-stat" aria-labelledby="ppx-agency-people">
                <span class="ppx-stat__icon ppx-stat__icon--green"><x-member.icon name="profile" class="ppx-stat__svg" /></span>
                <h2 class="ppx-stat__eyebrow" id="ppx-agency-people">People</h2>
                <p class="ppx-stat__value">{{ $summary['people'] }}</p>
                <p class="ppx-stat__meta">
                    <span>{{ $counts['not_accepted'] }} not set up yet</span>
                    @if ($counts['needs_attention'] > 0)
                        <span>{{ $counts['needs_attention'] }} {{ $counts['needs_attention'] === 1 ? 'license needs' : 'licenses need' }} attention</span>
                    @endif
                </p>
            </section>
        </div>

        <x-ppx.card title="People" heading="h2" id="people">
            <div class="ppx-agency__people-tools">
                <x-ppx.chips :options="collect(\App\Member\AgencyPage::FILTERS)->map(fn ($label, $key) => $label.' ('.$counts[$key].')')->all()" :current="$filter" :action="false" :href="fn ($key) => route('member.agency', $key === 'all' ? [] : ['filter' => $key]).'#people'" label="People by status" />
                <label class="ppx-docs__search" data-ppx-doc-search hidden>
                    <x-member.icon name="search" class="ppx-docs__search-icon" />
                    <span class="ppx-visually-hidden">Search people</span>
                    <input class="ppx-docs__search-input" type="search" placeholder="Search people" autocomplete="off">
                </label>
            </div>

            @if ($people->isEmpty())
                <x-ppx.empty-state icon="profile" title="Nobody with this status" />
            @else
                <div class="ppx-doc-table__wrap">
                    <table class="ppx-doc-table ppx-agency__table">
                        <caption class="ppx-visually-hidden">People in your agency</caption>
                        <thead>
                            <tr>
                                <th scope="col">Name</th>
                                <th scope="col">Type</th>
                                <th scope="col">Invitation</th>
                                <th scope="col">License</th>
                                <th scope="col">Account</th>
                                <th scope="col"><span class="ppx-visually-hidden">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($people as $person)
                                <tr data-ppx-doc-row data-search="{{ mb_strtolower($person['name'].' '.$person['email'].' '.$person['type'].' '.$person['invite'].' '.$person['license'].' '.$person['account']) }}">
                                    <td data-label="Name">
                                        <span class="ppx-agency__person">{{ $person['name'] }}@if ($person['principal']) <span class="ppx-agency__muted">· Principal (you)</span>@endif</span>
                                        <span class="ppx-agency__email">{{ $person['email'] }}</span>
                                    </td>
                                    <td data-label="Type">{{ $person['type'] }}</td>
                                    <td data-label="Invitation"><x-ppx.badge :variant="$person['invite_variant']">{{ $person['invite'] }}</x-ppx.badge></td>
                                    <td data-label="License">
                                        @if ($person['license'])
                                            <x-ppx.badge :variant="$person['license_variant']">{{ $person['license'] }}</x-ppx.badge>
                                            @if ($person['reason'])
                                                <span class="ppx-agency__reason">{{ $person['reason'] }}</span>
                                            @endif
                                        @else
                                            <span class="ppx-agency__muted">Not applicable</span>
                                        @endif
                                    </td>
                                    <td data-label="Account"><x-ppx.badge :variant="$person['account_variant']">{{ $person['account'] }}</x-ppx.badge></td>
                                    <td class="ppx-doc-table__action">
                                        @if ($person['can_resend'])
                                            @if ($person['resend_after'])
                                                <span class="ppx-agency__muted">Sent recently</span>
                                            @else
                                                <form method="POST" action="{{ route('member.agency.resend', $person['id']) }}" class="ppx-agency__resend">
                                                    @csrf
                                                    <x-ppx.button type="submit" variant="secondary" size="sm">Resend invitation<span class="ppx-visually-hidden"> to {{ $person['name'] }}</span></x-ppx.button>
                                                </form>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="ppx-docs__none" data-ppx-doc-none hidden>Nobody matches your search.</p>
            @endif
        </x-ppx.card>

        <x-ppx.card title="Agency details" heading="h2" id="details">
            @unless ($editing)
                <x-slot:action>
                    <x-ppx.button variant="secondary" size="sm" icon="pencil" :href="route('member.agency', ['edit' => 'details']).'#details'">Edit<span class="ppx-visually-hidden"> agency details</span></x-ppx.button>
                </x-slot:action>
            @endunless

            @if ($editing)
                @php
                    $legal = old('legal_address', $legalAddress ?? \App\Support\AgencyAddress::EMPTY);
                    $mailing = old('mailing_address', $mailingAddress ?? \App\Support\AgencyAddress::EMPTY);
                    $sameAsLegal = (bool) old('mailing_same_as_legal', $agency->mailing_same_as_legal);
                @endphp
                <form class="ppx-form" method="POST" action="{{ route('member.agency.update') }}" novalidate>
                    @csrf
                    @method('PATCH')
                    <x-ppx.form-row name="legal_name" label="Legal agency name" :value="old('legal_name', $agency->legal_name)" autocomplete="organization" required :autofocus="! $errors->any() || $errors->has('legal_name')" />
                    <x-ppx.form-row name="dba" label="DBA" :value="old('dba', $agency->dba)" hint="Doing business as, if different." />
                    <x-ppx.form-row name="agency_type" label="Agency type" type="select" :options="$agencyTypes" :value="old('agency_type', $agency->agency_type?->value)" required />
                    <x-ppx.form-row name="agency_npn" label="Agency NPN" :value="old('agency_npn', $agency->agency_npn)" inputmode="numeric" hint="Only if the agency holds an entity license." />
                    <x-ppx.form-static label="FEIN">
                        {{ $agency->fein ?: '—' }}
                        <p class="ppx-field__hint">{{ $feinHint }}.</p>
                    </x-ppx.form-static>

                    @include('member.agency-address', ['prefix' => 'legal_address', 'values' => $legal, 'label' => 'Business address', 'stored' => $legalAddress === null ? $agency->legal_address : null, 'hint' => 'A street address; no P.O. boxes.', 'optional' => false])

                    <div class="ppx-form__row">
                        <span class="ppx-form__label">Mailing address</span>
                        <div class="ppx-form__control">
                            <label class="ppx-check">
                                <input type="hidden" name="mailing_same_as_legal" value="0">
                                <input type="checkbox" name="mailing_same_as_legal" value="1" @checked($sameAsLegal)>
                                <span>Same as the business address</span>
                            </label>
                            <p class="ppx-field__hint">Untick to enter a different mailing address below; it is used only when unticked.</p>
                        </div>
                    </div>
                    @include('member.agency-address', ['prefix' => 'mailing_address', 'values' => $mailing, 'label' => 'Mailing address (if different)', 'stored' => $mailingAddress === null && ! $agency->mailing_same_as_legal ? $agency->mailing_address : null, 'hint' => null, 'optional' => true])

                    <x-ppx.form-row name="phone" label="Main phone" type="tel" :value="old('phone', $agency->phone)" autocomplete="tel" hint="10-digit US number, e.g. (512) 555-0142." required />
                    <x-ppx.form-row name="website" label="Website" :value="old('website', $agency->website)" autocomplete="url" hint="e.g. www.youragency.com" />
                    <x-ppx.form-row name="billing_email" label="Billing email" type="email" :value="old('billing_email', $agency->billing_email)" autocomplete="email" hint="Invoices and receipts go here; Stripe is updated too." required />

                    <div class="ppx-form__actions">
                        <x-ppx.button type="submit" variant="primary">Save details</x-ppx.button>
                        <x-ppx.button variant="secondary" :href="route('member.agency').'#details'">Cancel</x-ppx.button>
                    </div>
                </form>
            @else
                <dl class="ppx-kv">
                    <div class="ppx-kv__row"><dt>Legal agency name</dt><dd>{{ $agency->legal_name }}</dd></div>
                    <div class="ppx-kv__row"><dt>DBA</dt><dd>{{ $agency->dba ?: '—' }}</dd></div>
                    <div class="ppx-kv__row"><dt>Agency type</dt><dd>{{ $agency->agency_type?->label() ?? '—' }}</dd></div>
                    <div class="ppx-kv__row"><dt>Agency NPN</dt><dd>{{ $agency->agency_npn ?: '—' }}</dd></div>
                    <div class="ppx-kv__row"><dt>FEIN</dt><dd>{{ $agency->fein ?: '—' }}<span class="ppx-kv__note">{{ $feinHint }}.</span></dd></div>
                    <div class="ppx-kv__row"><dt>Business address</dt><dd>{{ $agency->legal_address ?: '—' }}</dd></div>
                    <div class="ppx-kv__row"><dt>Mailing address</dt><dd>{{ $agency->mailing_same_as_legal ? 'Same as the business address' : ($agency->mailing_address ?: '—') }}</dd></div>
                    <div class="ppx-kv__row"><dt>Main phone</dt><dd>{{ $agency->phone ?: '—' }}</dd></div>
                    <div class="ppx-kv__row"><dt>Website</dt><dd>{{ $agency->website ?: '—' }}</dd></div>
                    <div class="ppx-kv__row"><dt>Billing email</dt><dd>{{ $agency->billing_email ?: '—' }}</dd></div>
                </dl>
            @endif
        </x-ppx.card>

        <script src="{{ \App\Support\PortalAsset::url('documents.js') }}" defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
    </div>
</x-member.layout>
