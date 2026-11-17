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
    </style>
</head>
<body>
    @include('pdf.certificate-body')
</body>
</html>
