{{--
    The certificate's look, shared by the PDF (dompdf) and the HTML preview (member area), so both show the same
    page. Values are Figma's, in pixels of its frame (the mockup at 0.78); $u turns them into points on the page.
    Positions that depend on the orientation: CertificateGeneratorService::layout(). The font families are
    declared by each side: from the dompdf chroot, or from public/portal/fonts. Shadows and the gradient: real CSS
    in the preview, an image in the PDF. dompdf also places text differently: pdf/certificate.blade.php corrects that.
--}}
@php
    $u = \App\Services\Certificates\CertificateGeneratorService::points(...);
    $l = \App\Services\Certificates\CertificateGeneratorService::layout();
    $w = $l['w'];
    $h = $l['h'];
    $center = fn (float $size) => $u(($w - $size) / 2);
@endphp
.cert * { box-sizing: content-box; margin: 0; padding: 0; }
.cert [hidden] { display: none; }
.cert { position: absolute; top: 0; left: 0; width: {{ $u($w) }}; height: {{ $u($h) }}; overflow: hidden; background: #fff; color: #2a3340; font-family: 'PPX Open Sans', sans-serif; font-weight: normal; font-style: normal; text-align: left; }
.cert-background { position: absolute; top: 0; left: 0; width: {{ $u($w) }}; height: {{ $u($h) }}; }
.cert-sheet { position: absolute; top: 0; left: 0; width: {{ $u($w - 2) }}; height: {{ $u($h - 2) }}; border: {{ $u(1) }} solid #e6ddc4; }
.cert-frame { position: absolute; top: {{ $u(15) }}; left: {{ $u(15) }}; width: {{ $u($w - 34.68) }}; height: {{ $u($h - 34.68) }}; border: {{ $u(2.34) }} solid #0f2f4f; }
.cert-frame-gold { position: absolute; top: {{ $u(18.9) }}; left: {{ $u(18.9) }}; width: {{ $u($w - 39.8) }}; height: {{ $u($h - 39.8) }}; border: {{ $u(1) }} solid #f0c846; }
.cert-line { position: absolute; left: 0; width: {{ $u($w) }}; text-align: center; }
.cert-logo-line { top: {{ $u($l['logo']) }}; }
.cert-logo { width: {{ $u(136) }}; height: {{ $u(29) }}; }
.cert-title { top: {{ $u($l['title']) }}; color: #14508c; font-family: 'PPX Poppins', sans-serif; font-size: {{ $u(12) }}; letter-spacing: {{ $u(2.64) }}; line-height: {{ $u(18) }}; }
.cert-rule { position: absolute; top: {{ $u($l['rule']) }}; left: {{ $center(52) }}; width: {{ $u(52) }}; height: 0; border-top: {{ $u(2) }} solid #f0c846; }
.cert-lead { top: {{ $u($l['lead']) }}; color: #5f7185; font-size: {{ $u(13) }}; line-height: {{ $u(18) }}; }
.cert-name { top: {{ $u($l['name']) }}; color: #0a2d4b; font-family: 'PPX Lora', serif; font-style: italic; line-height: {{ $u(41.8) }}; white-space: nowrap; }
.cert-name-rule { position: absolute; top: {{ $u($l['nameRule']) }}; left: {{ $center(265) }}; width: {{ $u(265) }}; height: 0; border-top: {{ $u(1) }} solid #e2e8f0; }
.cert-text { position: absolute; top: {{ $u($l['text']) }}; left: {{ $center($l['textWidth']) }}; width: {{ $u($l['textWidth']) }}; color: #2a3340; font-size: {{ $u(13) }}; line-height: {{ $u(22.1) }}; text-align: center; }
.cert-strong { color: #0a2d4b; }
.cert-fields-line { top: {{ $u($l['fields']) }}; }
.cert .cert-fields { margin: 0 auto; border-collapse: collapse; }
.cert .cert-fields td { padding: 0 {{ $u(24) }}; text-align: center; white-space: nowrap; }
.cert-label { color: #9aa7b4; font-size: {{ $u(10.5) }}; letter-spacing: {{ $u(0.525) }}; line-height: {{ $u(16) }}; }
.cert-value { color: #1c2b3a; font-family: 'PPX Poppins', sans-serif; font-size: {{ $u(14) }}; line-height: {{ $u(24) }}; }
.cert-sign { position: absolute; top: {{ $u($l['sign']) }}; width: {{ $u(176) }}; text-align: center; }
{{-- The seal exactly in the middle, a block of the same width at the same distance on either side. --}}
.cert-sign-left { left: {{ $u(($w - 74) / 2 - 28 - 176) }}; }
.cert-sign-right { left: {{ $u(($w + 74) / 2 + 28) }}; }
.cert-sign-name { height: {{ $u(32) }}; color: #0a2d4b; font-family: 'PPX Lora', serif; font-size: {{ $u(22) }}; font-style: italic; line-height: {{ $u(32) }}; white-space: nowrap; }
.cert-sign-image { height: {{ $u(32) }}; }
.cert .cert-sign-rule { height: 0; margin: {{ $u(2) }} {{ $u(16) }} {{ $u(4) }}; border-top: {{ $u(1) }} solid #e6ddc4; }
.cert-sign-title { color: #9aa7b4; font-size: {{ $u(10.5) }}; line-height: {{ $u(14) }}; }
.cert-seal { position: absolute; top: {{ $u($l['seal']) }}; left: {{ $center(74) }}; box-sizing: border-box; width: {{ $u(74) }}; height: {{ $u(74) }}; border: {{ $u(2) }} solid #f0c846; border-radius: {{ $u(28.86) }}; background: #fffdf6; }
.cert-seal-content { position: absolute; top: {{ $u(20.5) }}; left: {{ $u(12) }}; width: {{ $u(46) }}; height: {{ $u(29) }}; }
.cert-qr { position: absolute; top: {{ $u($l['qr']) }}; left: {{ $u($w - 67.9 - 44) }}; width: {{ $u(44) }}; height: {{ $u(44) }}; }
.cert-qr-image { display: block; width: {{ $u(44) }}; height: {{ $u(44) }}; }
.cert-hr { position: absolute; top: {{ $u($l['hr']) }}; left: {{ $u(67.9) }}; width: {{ $u($w - 135.8) }}; height: 0; border-top: {{ $u(1) }} solid #e2e8f0; }
.cert-footer { top: {{ $u($l['footer']) }}; color: #9aa7b4; font-size: {{ $u(10.5) }}; line-height: {{ $u(19) }}; }
.cert-footer-id { white-space: nowrap; }
{{-- With the QR in the row, the text centres in the space left of it. --}}
.cert--qr .cert-footer { left: {{ $u(67.9) }}; width: {{ $u($w - 135.8 - 52) }}; }
.cert .cert-footer-icon { width: {{ $u(19) }}; height: {{ $u(19) }}; margin-right: {{ $u(5) }}; vertical-align: {{ $u(-5) }}; }
