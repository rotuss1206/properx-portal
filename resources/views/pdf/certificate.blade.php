<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Membership Certificate {{ $certificateCode }}</title>
    <style>
        @page { margin: 0; }
        {{-- Fonts load from inside the dompdf chroot (resources/views/pdf). --}}
        @font-face { font-family: 'PPX Lora'; font-style: italic; font-weight: normal; src: url('{{ $fontPath('Lora-Italic.ttf') }}') format('truetype'); }
        @font-face { font-family: 'PPX Open Sans'; font-style: normal; font-weight: normal; src: url('{{ $fontPath('OpenSans-Regular.ttf') }}') format('truetype'); }
        @font-face { font-family: 'PPX Poppins'; font-style: normal; font-weight: normal; src: url('{{ $fontPath('Poppins-SemiBold.ttf') }}') format('truetype'); }
        body { margin: 0; }
        @include('pdf.certificate-styles')
        {{-- dompdf multiplies line-height by the font's height ratio (Open Sans 1.498, Poppins 1.65, Lora 1.408).
             These divide by it, so the page matches the HTML preview. --}}
        @php($u = \App\Services\Certificates\CertificateGeneratorService::points(...))
        .cert-title { line-height: {{ $u(18 / 1.65) }}; }
        .cert-lead { line-height: {{ $u(18 / 1.498) }}; }
        .cert-name { line-height: {{ $u(41.8 / 1.408) }}; }
        .cert-text { line-height: {{ $u(22.1 / 1.498) }}; }
        .cert-label { line-height: {{ $u(16 / 1.498) }}; }
        .cert-value { line-height: {{ $u(24 / 1.65) }}; }
        .cert-sign-name { line-height: {{ $u(32 / 1.408) }}; }
        .cert-sign-title { line-height: {{ $u(14 / 1.498) }}; }
        .cert-qr-text { line-height: {{ $u(12 / 1.498) }}; }
        .cert-footer { line-height: {{ $u(19 / 1.498) }}; }
        {{-- dompdf also sets text a little lower in its box. --}}
        .cert-title { top: {{ $u(148) }}; }
        .cert-text { top: {{ $u(320) }}; }
        .cert-fields-line { top: {{ $u(418) }}; }
        .cert-sign { top: {{ $u(633) }}; }
        .cert-qr-text { margin-top: {{ $u(3) }}; }
        .cert-footer { top: {{ $u(722) }}; }
    </style>
</head>
<body>
    @include('pdf.certificate-body')
</body>
</html>
