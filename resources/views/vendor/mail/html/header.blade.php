@props(['url'])
{{-- Text logo: no remote image, which Outlook and many clients block by default. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<span class="brand-name" style="color: {{ config('portal.brand.navy') }}; font-size: 22px; font-weight: bold; letter-spacing: 0.5px;">{{ config('portal.brand.name') }}</span><br>
<span class="brand-tagline" style="color: {{ config('portal.brand.button') }}; font-size: 12px; letter-spacing: 3px; text-transform: uppercase;">{{ config('portal.brand.tagline') }}</span>
</a>
</td>
</tr>
