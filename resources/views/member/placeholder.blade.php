<x-member.layout :page="$page" :title="$title">
    <section class="ppx-card">
        <div class="ppx-card__header">
            <h2 class="ppx-card__title">{{ $title }}</h2>
        </div>
        <x-ppx.empty-state :icon="\App\Member\MemberMenu::PAGES[$page][1]" title="This section is coming soon">
            <x-ppx.button :href="route('member.dashboard')" variant="primary" class="ppx-empty__action">Back to dashboard</x-ppx.button>
        </x-ppx.empty-state>
    </section>
</x-member.layout>
