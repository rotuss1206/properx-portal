@props(['icon' => 'dashboard', 'title', 'text' => null])
{{-- Empty state (public/portal/components.css). The default slot can hold an action. --}}
<div {{ $attributes->class('ppx-empty') }}>
    <span class="ppx-empty__icon"><x-member.icon :name="$icon" class="ppx-empty__svg" /></span>
    <p class="ppx-empty__title">{{ $title }}</p>
    @if ($text)
        <p class="ppx-empty__text">{{ $text }}</p>
    @endif
    {{ $slot }}
</div>
