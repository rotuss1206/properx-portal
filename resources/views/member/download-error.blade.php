{{-- A download that cannot be served, for members in the member area (App\Exceptions\DocumentDownloadException). --}}
<x-member.layout :page="null" :title="$heading">
    <section class="ppx-card">
        <div class="ppx-card__header">
            <h2 class="ppx-card__title">{{ $heading }}</h2>
        </div>
        <x-ppx.empty-state icon="license" :title="$message">
            <x-ppx.button :href="route('member.dashboard')" variant="primary" class="ppx-empty__action">Back to dashboard</x-ppx.button>
        </x-ppx.empty-state>
    </section>
</x-member.layout>
