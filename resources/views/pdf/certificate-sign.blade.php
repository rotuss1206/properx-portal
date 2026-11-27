{{--
    One place of the certificate's bottom row (CertificateGeneratorService::signatureRow), the same markup in the PDF
    and the member area's preview, each styled by its own design: the signature (the signatory's image, or the name
    set in Lora) or the date issued, a line under it, then the printed name under an image and the title.
--}}
<div class="cert-sign cert-sign-{{ $side }}">
    @if ($sign['date'])
        <div class="cert-sign-name" data-ppx-cert="issued">{{ $issuedOnLabel }}</div>
        <div class="cert-sign-rule"></div>
        <div class="cert-sign-title">Date issued</div>
    @else
        <div class="cert-sign-name">@if ($sign['image'])<img class="cert-sign-image" src="{{ $sign['image'] }}" alt="Signature of {{ $sign['name'] }}">@else{{ $sign['name'] }}@endif</div>
        <div class="cert-sign-rule"></div>
        @if ($sign['image'])
            <div class="cert-sign-person">{{ $sign['name'] }}</div>
        @endif
        @if ($sign['title'])
            <div class="cert-sign-title">{{ $sign['title'] }}</div>
        @endif
    @endif
</div>
