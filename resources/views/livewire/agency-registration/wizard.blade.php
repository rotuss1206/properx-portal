<div>
    <nav aria-label="Enrollment progress">
        <ol class="progress">
            @foreach ($steps as $number => $name)
                @php($state = $number === $step ? 'current' : ($number < $step ? 'done' : 'todo'))
                <li class="{{ $state }}">
                    @if ($state === 'done')
                        <button type="button" class="step" wire:click="goTo({{ $number }})">
                            <span>{{ $number }}. {{ $name }}</span><span class="sr-only"> (completed, go back to this step)</span>
                        </button>
                    @else
                        <div class="step" @if ($state === 'current') aria-current="step" @endif>
                            <span>{{ $number }}. {{ $name }}</span>
                        </div>
                    @endif
                </li>
            @endforeach
        </ol>
        <p class="progress-count">Step {{ $step }} of {{ count($steps) }}: {{ $steps[$step] }}</p>
    </nav>

    <div class="wizard">
        <form class="card" wire:submit="next" novalidate>
            @if ($errors->any())
                <p class="error" role="alert">Please check the highlighted fields.</p>
            @endif

            <div wire:key="step-{{ $step }}">
                @include('livewire.agency-registration.steps.'.$step)
            </div>

            <div class="actions">
                @if ($step > 1)
                    <button type="button" class="btn btn-secondary" wire:click="back" wire:loading.attr="disabled">Back</button>
                @endif
                @if ($step < count($steps))
                    @php($blocked = $step === 5 && ! $this->people->minimumMet())
                    {{-- A blocked button is another element (wire:key): wire:loading.attr on the earlier one would take "disabled" off again after the request. --}}
                    <button type="submit" @class(['btn', 'btn-primary', 'is-blocked' => $blocked]) wire:key="continue-{{ $blocked ? 'blocked' : 'open' }}"
                        @if ($blocked) disabled aria-describedby="people-minimum" @else wire:loading.attr="disabled" @endif>Continue</button>
                @endif
            </div>

            {{-- From screen 3 on (Agency spec 5.6): a one-time link to the principal's email. --}}
            @if ($step >= 3)
                <div class="save-later">
                    <button type="button" class="link" wire:click="openSaveLater" aria-expanded="{{ $saveLaterOpen ? 'true' : 'false' }}" aria-controls="save-later-panel">Save and continue later</button>
                    @if ($saveLaterOpen)
                        <div class="save-later-panel" id="save-later-panel">
                            <h3 class="section-title" id="save-later-title" tabindex="-1">Save and continue later</h3>
                            @if (trim($principal->email) !== '')
                                <p class="hint">We'll save your answers and email a one-time link to the principal, <strong>{{ $principal->email }}</strong>. It opens this screen again.</p>
                            @else
                                <p class="hint">We'll save your answers and email a one-time link that opens this screen again.</p>
                                <x-join.input name="saveLaterEmail" type="email" label="Principal's email" required autocomplete="email"
                                    hint="The link goes here. This also becomes the principal's login." wire:keydown.enter.prevent="sendContinueLink" />
                            @endif
                            <div class="save-later-actions">
                                <button type="button" class="btn btn-secondary" wire:click="sendContinueLink" wire:loading.attr="disabled">Email me the link</button>
                                <button type="button" class="link" wire:click="openSaveLater">Close</button>
                            </div>
                            @if ($saveLaterStatus)
                                <p @class(['save-later-status', 'notice-ok' => $saveLaterSent, 'notice-info' => ! $saveLaterSent]) id="save-later-status" role="status" tabindex="-1">{{ $saveLaterStatus }}</p>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        </form>

        @include('livewire.agency-registration.price')
    </div>
</div>
