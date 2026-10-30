@props(['variant' => 'neutral', 'dot' => true])
{{-- Status pill: success, warning, danger, neutral, founding (public/portal/components.css). --}}
<span {{ $attributes->class(['ppx-badge', 'ppx-badge--'.$variant]) }}>@if ($dot)<span class="ppx-badge__dot" aria-hidden="true"></span>@endif{{ $slot }}</span>
