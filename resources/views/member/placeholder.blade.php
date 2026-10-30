<x-member.layout :page="$page" :title="$title">
    <section class="ppx-card">
        <div class="ppx-card__header">
            <h2 class="ppx-card__title">{{ $title }}</h2>
        </div>
        <div class="ppx-empty">
            <span class="ppx-empty__icon"><x-member.icon :name="\App\Member\MemberMenu::PAGES[$page][1]" class="ppx-empty__svg" /></span>
            <p class="ppx-empty__title">Coming soon</p>
            <p class="ppx-empty__text">This page is being built.</p>
        </div>
    </section>
</x-member.layout>
