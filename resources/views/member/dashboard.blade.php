<x-member.layout :page="$page" :title="$title">
    <h2 class="ppx-page-heading">{{ $greeting }}, {{ $firstName }}</h2>

    <section class="ppx-card">
        <div class="ppx-card__header">
            <h3 class="ppx-card__title">Your dashboard</h3>
        </div>
        <div class="ppx-empty">
            <span class="ppx-empty__icon"><x-member.icon name="dashboard" class="ppx-empty__svg" /></span>
            <p class="ppx-empty__title">Coming soon</p>
            <p class="ppx-empty__text">Your membership, license, documents and activity will appear here.</p>
        </div>
    </section>
</x-member.layout>
