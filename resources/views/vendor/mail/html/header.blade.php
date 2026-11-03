@props(['url'])
{{-- The logo is embedded in the message (App\Listeners\EmbedMailLogo): no remote image, which Outlook and many clients block by default. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<img src="cid:{{ \App\Listeners\EmbedMailLogo::CONTENT_ID }}" alt="{{ config('portal.brand.name') }}" width="220" height="47" style="display: block; width: 220px; max-width: 100%; height: auto; margin: 0 auto 6px; border: 0;">
<span class="brand-tagline" style="color: {{ config('portal.brand.button') }}; font-size: 12px; letter-spacing: 3px; text-transform: uppercase;">{{ config('portal.brand.tagline') }}</span>
</a>
</td>
</tr>
