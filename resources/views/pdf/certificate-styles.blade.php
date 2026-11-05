{{--
    The certificate's look, shared by the PDF (dompdf) and the HTML preview (member area), so both show the same
    page: US Letter portrait. Values are Figma's, in pixels of its 636.48 px wide frame (the mockup at 0.78);
    $u turns them into points on the 612 pt page. The vertical positions come from docs/design/Certificate.jpg
    at 0.78. The font families are declared by each side: from the dompdf chroot, or from public/portal/fonts.
    Shadows and the gradient: real CSS in the preview, an image in the PDF. dompdf also places text differently:
    pdf/certificate.blade.php corrects that, and only that.
--}}
@php($u = \App\Services\Certificates\CertificateGeneratorService::points(...))
.cert * { box-sizing: content-box; margin: 0; padding: 0; }
.cert { position: absolute; top: 0; left: 0; width: 612pt; height: 791pt; overflow: hidden; background: #fff; color: #2a3340; font-family: 'PPX Open Sans', sans-serif; font-weight: normal; font-style: normal; text-align: left; }
.cert-background { position: absolute; top: 0; left: 0; width: 612pt; height: 791pt; }
.cert-sheet { position: absolute; top: 0; left: 0; width: {{ $u(634.48) }}; height: {{ $u(820.64) }}; border: {{ $u(1) }} solid #e6ddc4; }
.cert-frame { position: absolute; top: {{ $u(15) }}; left: {{ $u(15) }}; width: {{ $u(601.8) }}; height: {{ $u(787.96) }}; border: {{ $u(2.34) }} solid #0f2f4f; }
.cert-frame-gold { position: absolute; top: {{ $u(18.9) }}; left: {{ $u(18.9) }}; width: {{ $u(596.68) }}; height: {{ $u(782.84) }}; border: {{ $u(1) }} solid #f0c846; }
.cert-corner { position: absolute; top: {{ $u(26.14) }}; width: {{ $u(24.16) }}; height: {{ $u(24.16) }}; border-top: {{ $u(2.34) }} solid #f0c846; }
.cert-corner-left { left: {{ $u(26.14) }}; border-left: {{ $u(2.34) }} solid #f0c846; }
.cert-corner-right { left: {{ $u(583.84) }}; border-right: {{ $u(2.34) }} solid #f0c846; }
.cert-line { position: absolute; left: 0; width: 612pt; text-align: center; }
.cert-logo-line { top: {{ $u(84) }}; }
.cert-logo { width: {{ $u(136) }}; height: {{ $u(29) }}; }
.cert-title { top: {{ $u(149) }}; color: #14508c; font-family: 'PPX Poppins', sans-serif; font-size: {{ $u(12) }}; letter-spacing: {{ $u(2.64) }}; line-height: {{ $u(18) }}; }
.cert-rule { position: absolute; top: {{ $u(184) }}; left: {{ $u(292.24) }}; width: {{ $u(52) }}; height: 0; border-top: {{ $u(2) }} solid #f0c846; }
.cert-lead { top: {{ $u(220) }}; color: #5f7185; font-size: {{ $u(13) }}; line-height: {{ $u(18) }}; }
.cert-name { top: {{ $u(254) }}; color: #0a2d4b; font-family: 'PPX Lora', serif; font-style: italic; line-height: {{ $u(41.8) }}; white-space: nowrap; }
.cert-name-rule { position: absolute; top: {{ $u(304) }}; left: {{ $u(185.74) }}; width: {{ $u(265) }}; height: 0; border-top: {{ $u(1) }} solid #e2e8f0; }
.cert-text { position: absolute; top: {{ $u(322) }}; left: {{ $u(96.24) }}; width: {{ $u(444) }}; color: #2a3340; font-size: {{ $u(13) }}; line-height: {{ $u(22.1) }}; text-align: center; }
.cert-strong { color: #0a2d4b; }
.cert-fields-line { top: {{ $u(420) }}; }
.cert .cert-fields { margin: 0 auto; border-collapse: collapse; }
.cert .cert-fields td { padding: 0 {{ $u(24) }}; text-align: center; white-space: nowrap; }
.cert-label { color: #9aa7b4; font-size: {{ $u(10.5) }}; letter-spacing: {{ $u(0.525) }}; line-height: {{ $u(16) }}; }
.cert-value { color: #1c2b3a; font-family: 'PPX Poppins', sans-serif; font-size: {{ $u(14) }}; line-height: {{ $u(24) }}; }
.cert-sign { position: absolute; top: {{ $u(635) }}; width: {{ $u(176) }}; text-align: center; }
.cert-sign-left { left: {{ $u(85) }}; }
.cert-sign-right { left: {{ $u(375) }}; }
.cert-sign-name { height: {{ $u(32) }}; color: #0a2d4b; font-family: 'PPX Lora', serif; font-size: {{ $u(22) }}; font-style: italic; line-height: {{ $u(32) }}; white-space: nowrap; }
.cert-sign-image { height: {{ $u(32) }}; }
.cert .cert-sign-rule { height: 0; margin: {{ $u(2) }} {{ $u(16) }} {{ $u(4) }}; border-top: {{ $u(1) }} solid #e6ddc4; }
.cert-sign-title { color: #9aa7b4; font-size: {{ $u(10.5) }}; line-height: {{ $u(14) }}; }
.cert-seal { position: absolute; top: {{ $u(628) }}; left: {{ $u(289.24) }}; width: {{ $u(58) }}; height: {{ $u(58) }}; }
.cert-qr { position: absolute; top: {{ $u(620) }}; left: {{ $u(420) }}; width: {{ $u(86) }}; text-align: center; }
.cert-qr-centered { top: {{ $u(510) }}; left: {{ $u(275.24) }}; }
.cert-qr-image { width: {{ $u(66) }}; height: {{ $u(66) }}; }
.cert-qr-text { color: #9aa7b4; font-size: {{ $u(9) }}; line-height: {{ $u(12) }}; }
.cert-footer { top: {{ $u(724) }}; color: #9aa7b4; font-size: {{ $u(10.5) }}; line-height: {{ $u(19) }}; }
.cert .cert-footer-icon { width: {{ $u(19) }}; height: {{ $u(19) }}; margin-right: {{ $u(5) }}; vertical-align: {{ $u(-5) }}; }
