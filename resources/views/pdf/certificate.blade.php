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
        {{-- dompdf multiplies line-height by the font's height ratio (Open Sans 1.498, Poppins 1.65, Lora 1.408),
             and draws text lower in its box. These undo that, so the page matches the HTML preview. --}}
        .cert-title { line-height: 9.1pt; }
        .cert-lead { line-height: 10.68pt; }
        .cert-name { line-height: 42.6pt; }
        .cert-text { line-height: 15.02pt; }
        .cert-label { line-height: 8pt; }
        .cert-value { line-height: 12.12pt; }
        .cert-sign-name { line-height: 21.3pt; }
        .cert-sign-title { line-height: 7.34pt; }
        .cert-seal-text { line-height: 4.85pt; }
        .cert-qr-text { line-height: 6.67pt; }
        .cert-footer { line-height: 8.68pt; }
    </style>
</head>
<body>
    @include('pdf.certificate-body')
</body>
</html>
