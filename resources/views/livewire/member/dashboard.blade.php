<div class="ppx-dash__tabs">
    <x-ppx.tabs :tabs="$tabs" :current="$tab" label="Dashboard sections" />

    @if ($tab === 'overview')
        <div class="ppx-stats">
            {{-- Membership --}}
            <section class="ppx-stat ppx-stat--hero" aria-labelledby="ppx-stat-membership">
                <span class="ppx-stat__icon"><x-member.icon name="badge-check" class="ppx-stat__svg" /></span>
                <h3 class="ppx-stat__eyebrow" id="ppx-stat-membership">Membership</h3>
                <p class="ppx-stat__value">{{ $membership['label'] }}</p>
                <p class="ppx-stat__meta">
                    @if ($membership['founding'])
                        <x-ppx.badge variant="founding" :dot="false">Founding member</x-ppx.badge>
                    @endif
                    @if ($membership['renews'])
                        <span>Renews {{ $membership['renews']->format('j M Y') }}</span>
                    @endif
                </p>
            </section>

            {{-- License verification --}}
            <section class="ppx-stat" aria-labelledby="ppx-stat-license">
                <span class="ppx-stat__icon ppx-stat__icon--green"><x-member.icon name="shield" class="ppx-stat__svg" /></span>
                <h3 class="ppx-stat__eyebrow" id="ppx-stat-license">License verification</h3>
                @if ($license['applicable'])
                    <p class="ppx-stat__value">{{ $license['npn'] ? 'NPN '.$license['npn'] : 'NPN not on file' }}</p>
                    <p class="ppx-stat__meta">
                        <x-ppx.badge :variant="$license['variant']">{{ $license['label'] }}</x-ppx.badge>
                        @if ($license['state'])
                            <span>{{ $license['state'] }}</span>
                        @endif
                    </p>
                @else
                    <p class="ppx-stat__value">Not applicable</p>
                    <p class="ppx-stat__meta"><span>Agency staff hold no license.</span></p>
                @endif
            </section>

            {{-- Certificate --}}
            <section class="ppx-stat" aria-labelledby="ppx-stat-certificate">
                <span class="ppx-stat__icon ppx-stat__icon--green"><x-member.icon name="certificate" class="ppx-stat__svg" /></span>
                <h3 class="ppx-stat__eyebrow" id="ppx-stat-certificate">Certificate</h3>
                @if ($certificate['ready'])
                    <p class="ppx-stat__value">Ready</p>
                    <p class="ppx-stat__meta">
                        @if ($certificate['validThrough'])
                            <span>Valid through {{ $certificate['validThrough']->format('j M Y') }}</span>
                        @endif
                    </p>
                    <x-ppx.button variant="secondary" size="small" icon="download" :href="$certificate['url']" class="ppx-stat__action">Download</x-ppx.button>
                @else
                    <p class="ppx-stat__value">Unavailable</p>
                    <p class="ppx-stat__meta ppx-stat__meta--wrap"><span>{{ $certificate['reason'] }}</span></p>
                @endif
            </section>
        </div>

        @if ($timeline)
            <x-ppx.card title="Membership timeline">
                <x-slot:action><span class="ppx-card__note">{{ ($timeline['renewed'] ?? $timeline['joined'])->format('Y') }} – {{ $timeline['next']->format('Y') }} cycle</span></x-slot:action>
                <ol class="ppx-timeline">
                    <li class="ppx-timeline__point is-done">
                        <span class="ppx-timeline__label">Joined</span>
                        <time class="ppx-timeline__date" datetime="{{ $timeline['joined']->toDateString() }}">{{ $timeline['joined']->format('M Y') }}</time>
                    </li>
                    @if ($timeline['renewed'])
                        <li class="ppx-timeline__point is-done">
                            <span class="ppx-timeline__label">Renewed</span>
                            <time class="ppx-timeline__date" datetime="{{ $timeline['renewed']->toDateString() }}">{{ $timeline['renewed']->format('M Y') }}</time>
                        </li>
                    @endif
                    <li class="ppx-timeline__point is-today" aria-current="date">
                        <span class="ppx-timeline__label">Today</span>
                        <time class="ppx-timeline__date" datetime="{{ $timeline['today']->toDateString() }}">{{ $timeline['today']->format('j M Y') }}</time>
                    </li>
                    <li class="ppx-timeline__point">
                        <span class="ppx-timeline__label">Next renewal</span>
                        <time class="ppx-timeline__date" datetime="{{ $timeline['next']->toDateString() }}">{{ $timeline['next']->format('j M Y') }}</time>
                    </li>
                </ol>
            </x-ppx.card>
        @endif

        <x-ppx.card title="Recent documents">
            <x-slot:action>
                <a class="ppx-link" href="?tab=documents" wire:click.prevent="show('documents')">View all</a>
            </x-slot:action>
            <x-ppx.doc-table :rows="$recent" caption="Recent documents" />
        </x-ppx.card>
    @elseif ($tab === 'documents')
        <x-ppx.card title="All documents">
            <x-slot:action>
                <x-ppx.chips :options="\App\Member\DocumentList::FILTERS" :current="$filter" :href="fn ($key) => '?tab=documents&filter='.$key" label="Document type" />
            </x-slot:action>
            <x-ppx.doc-table :rows="$documents" caption="All documents" empty-title="No documents here yet" empty-text="Your license documents, tax documents and certificate appear here." />
        </x-ppx.card>
    @elseif ($tab === 'activity')
        <x-ppx.card title="Activity">
            @if ($activity->isEmpty())
                <x-ppx.empty-state icon="dashboard" title="No activity yet" />
            @else
                <ol class="ppx-activity">
                    @foreach ($activity as $item)
                        <li class="ppx-activity__item">
                            <span class="ppx-activity__icon"><x-member.icon :name="$item['icon']" class="ppx-activity__svg" /></span>
                            <span class="ppx-activity__text">
                                <span class="ppx-activity__title">{{ $item['title'] }}</span>
                                @if ($item['detail'])
                                    <span class="ppx-activity__detail">{{ $item['detail'] }}</span>
                                @endif
                            </span>
                            <time class="ppx-activity__time" datetime="{{ $item['at']->toIso8601String() }}" title="{{ $item['at']->toDayDateTimeString() }}">{{ $item['at']->diffForHumans() }}</time>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-ppx.card>
    @elseif ($tab === 'benefits')
        <div class="ppx-benefits">
            @foreach ($benefits as $benefit)
                <section class="ppx-benefit">
                    <span class="ppx-stat__icon"><x-member.icon :name="$benefit['icon'] ?? 'badge-check'" class="ppx-stat__svg" /></span>
                    <h3 class="ppx-benefit__title">{{ $benefit['title'] }}</h3>
                    <p class="ppx-benefit__text">{{ $benefit['description'] ?? '' }}</p>
                    @if (! empty($benefit['url']))
                        <a class="ppx-link" href="{{ $benefit['url'] }}" target="_blank" rel="noopener noreferrer">Learn more</a>
                    @endif
                </section>
            @endforeach
        </div>
    @endif
</div>
