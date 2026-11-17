{{--
    The PDF's look (docs/design/Certificate.jpg). Sizes are the design's pixels on its 816 px wide page; $u turns them
    into points (CertificateGeneratorService::points). dompdf multiplies line-height by the font's own height ratio
    (Open Sans 1.498, Poppins 1.65, Lora 1.408) and sets text a little low, so line heights are divided by it ($lh) and
    a few lines move up by $nudge.
--}}
@php
    $u = \App\Services\Certificates\CertificateGeneratorService::points(...);
    $ratio = ['open' => 1.498, 'poppins' => 1.65, 'lora' => 1.408];
    $lh = fn (float $px, string $font) => $u($px / $ratio[$font]);
    $nudge = 3;
    $gold = '#c9a43c';
@endphp
.cert * { margin: 0; padding: 0; }
.cert { position: absolute; top: 0; left: 0; width: {{ $u(816) }}; height: {{ $u(1056) }}; overflow: hidden; background: #fff; color: #2a3340; font-family: 'PPX Open Sans', sans-serif; font-weight: normal; font-style: normal; text-align: left; }
.cert-frame { position: absolute; top: {{ $u(34) }}; left: {{ $u(34) }}; width: {{ $u(742) }}; height: {{ $u(982) }}; border: {{ $u(3) }} solid #0f2f4f; }
.cert-frame-gold { position: absolute; top: {{ $u(44) }}; left: {{ $u(44) }}; width: {{ $u(726) }}; height: {{ $u(965) }}; border: {{ $u(1) }} solid #d9bf6e; }
.cert-corner { position: absolute; top: {{ $u(52) }}; width: {{ $u(31) }}; height: {{ $u(31) }}; border-top: {{ $u(3) }} solid {{ $gold }}; }
.cert-corner-left { left: {{ $u(52) }}; border-left: {{ $u(3) }} solid {{ $gold }}; }
.cert-corner-right { left: {{ $u(730) }}; border-right: {{ $u(3) }} solid {{ $gold }}; }
.cert-line { position: absolute; left: 0; width: {{ $u(816) }}; text-align: center; }
.cert-logo-line { top: {{ $u(110) }}; }
.cert-logo { width: {{ $u(172) }}; height: {{ $u(36.5) }}; }
.cert-title { top: {{ $u(191) }}; color: #14508c; font-family: 'PPX Poppins', sans-serif; font-size: {{ $u(15) }}; letter-spacing: {{ $u(4.6) }}; line-height: {{ $lh(20, 'poppins') }}; }
.cert-ornament { position: absolute; top: {{ $u(236) }}; width: {{ $u(60) }}; height: 0; border-top: {{ $u(1) }} solid {{ $gold }}; }
.cert-ornament-left { left: {{ $u(330) }}; }
.cert-ornament-right { left: {{ $u(426) }}; }
.cert-diamond { position: absolute; top: {{ $u(230.5) }}; left: {{ $u(402) }}; width: {{ $u(12) }}; height: {{ $u(12) }}; }
.cert-lead { top: {{ $u(281 - $nudge) }}; color: #5f7185; font-size: {{ $u(16) }}; line-height: {{ $lh(22, 'open') }}; }
.cert-name { top: {{ $u(318 - $nudge) }}; color: #0a2d4b; font-family: 'PPX Lora', serif; font-style: italic; line-height: {{ $lh(72, 'lora') }}; white-space: nowrap; }
.cert-name-rule { position: absolute; top: {{ $u(398) }}; left: {{ $u(238) }}; width: {{ $u(340) }}; height: 0; border-top: {{ $u(1) }} solid #e2e8f0; }
.cert-text { position: absolute; top: {{ $u(425 - $nudge) }}; left: {{ $u(140) }}; width: {{ $u(536) }}; color: #2a3340; font-size: {{ $u(16) }}; line-height: {{ $lh(30.5, 'open') }}; text-align: center; }
.cert-strong { color: #14508c; }
.cert-fields-line { top: {{ $u(561 - $nudge) }}; }
.cert .cert-fields { margin: 0 auto; border-collapse: collapse; }
.cert .cert-fields td { padding: 0 {{ $u(34) }}; text-align: center; white-space: nowrap; }
.cert-label { color: #9aa7b4; font-size: {{ $u(11) }}; letter-spacing: {{ $u(1.2) }}; line-height: {{ $lh(18, 'open') }}; }
.cert-value { color: #0a2d4b; font-family: 'PPX Poppins', sans-serif; font-size: {{ $u(17) }}; line-height: {{ $lh(30, 'poppins') }}; }
.cert-sign { position: absolute; top: {{ $u(829 - $nudge) }}; width: {{ $u(260) }}; color: #0a2d4b; font-family: 'PPX Lora', serif; font-size: {{ $u(32) }}; font-style: italic; line-height: {{ $lh(40, 'lora') }}; text-align: center; white-space: nowrap; }
.cert-sign-left { left: {{ $u(93) }}; }
.cert-sign-right { left: {{ $u(463) }}; }
.cert-sign-image { height: {{ $u(40) }}; }
{{-- Round, 104 px with its 3 px border. --}}
.cert-seal { position: absolute; top: {{ $u(787) }}; left: {{ $u(356) }}; width: {{ $u(98) }}; height: {{ $u(98) }}; border: {{ $u(3) }} solid {{ $gold }}; border-radius: {{ $u(52) }}; background: #fff9ec; }
.cert-seal-content { position: absolute; top: {{ $u(27) }}; left: {{ $u(14) }}; width: {{ $u(70) }}; height: {{ $u(44) }}; }
.cert-footer { top: {{ $u(929 - $nudge) }}; color: #9aa7b4; font-size: {{ $u(13) }}; line-height: {{ $lh(20, 'open') }}; }
.cert-footer-id { white-space: nowrap; }
.cert .cert-footer-icon { width: {{ $u(20) }}; height: {{ $u(20) }}; margin-right: {{ $u(6) }}; vertical-align: {{ $u(-5) }}; }
{{-- With the QR at the right of the bottom line, the text centres in the space left of it. --}}
.cert--qr .cert-footer { left: {{ $u(60) }}; width: {{ $u(630) }}; }
.cert-qr { position: absolute; top: {{ $u(909) }}; left: {{ $u(700) }}; width: {{ $u(60) }}; height: {{ $u(60) }}; }
.cert-qr-image { display: block; width: {{ $u(60) }}; height: {{ $u(60) }}; }
