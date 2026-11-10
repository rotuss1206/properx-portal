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
        </form>

        @include('livewire.agency-registration.price')
    </div>
</div>
