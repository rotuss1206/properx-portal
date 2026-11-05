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
             These divide by it, so the page matches the HTML preview; it also sets text a little lower in its box. --}}
        @php
            $u = \App\Services\Certificates\CertificateGeneratorService::points(...);
            $l = \App\Services\Certificates\CertificateGeneratorService::layout();
        @endphp
        .cert-title { top: {{ $u($l['title'] - 1) }}; line-height: {{ $u(18 / 1.65) }}; }
        .cert-lead { line-height: {{ $u(18 / 1.498) }}; }
        .cert-name { line-height: {{ $u(41.8 / 1.408) }}; }
        .cert-text { top: {{ $u($l['text'] - 2) }}; line-height: {{ $u(22.1 / 1.498) }}; }
        .cert-fields-line { top: {{ $u($l['fields'] - 2) }}; }
        .cert-label { line-height: {{ $u(16 / 1.498) }}; }
        .cert-value { line-height: {{ $u(24 / 1.65) }}; }
        .cert-sign { top: {{ $u($l['sign'] - 2) }}; }
        .cert-sign-name { line-height: {{ $u(32 / 1.408) }}; }
        .cert-sign-title { line-height: {{ $u(14 / 1.498) }}; }
        .cert-footer { top: {{ $u($l['footer'] - 2) }}; line-height: {{ $u(19 / 1.498) }}; }
    </style>
</head>
<body>
    @include('pdf.certificate-body')
</body>
</html>
