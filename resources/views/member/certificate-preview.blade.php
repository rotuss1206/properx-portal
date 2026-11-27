{{--
    The live preview on /member/certificate (docs/design/Certificate Generate.jpg): an almost square sheet, a 52 × 2 rule
    under the title, a rounded-square seal, lines and titles under the signatures, a thin rule above "Verify at …".
    The downloaded PDF is the other design (pdf.certificate-body). Every optional part is here; public/portal/
    certificate.js shows or hides it as the switches change, and draws this sheet into the PNG.
    Data: CertificateGeneratorService::document(); sizes in its styles (member.certificate-preview-styles).
--}}
<div @class(['cert', 'cert--qr' => $showQr]) data-ppx-cert-sheet>
    <div class="cert-frame"></div>
    <div class="cert-frame-gold"></div>

    <div class="cert-line cert-logo-line"><img class="cert-logo" src="{{ $previewLogoImage }}" alt="ProPerX Alliance"></div>
    <div class="cert-line cert-title">CERTIFICATE OF MEMBERSHIP</div>
    <div class="cert-rule"></div>

    <div class="cert-line cert-lead">This certifies that</div>
    {{-- One line: the design's 38 px for most names, smaller for long ones. --}}
    @php($nameLength = mb_strlen($memberName))
    <div class="cert-line cert-name" style="font-size: {{ $nameLength <= 30 ? 38 : ($nameLength <= 42 ? 28 : ($nameLength <= 56 ? 21 : 16)) }}px;">{{ $memberName }}</div>

    <div class="cert-text">
        is a recognized member in good standing of ProPerX Alliance, entitled
        to all rights, benefits, and privileges of membership{!! $term ? ' for the <span data-ppx-cert="term">'.e($term).'</span> term' : '' !!}.
    </div>

    <div class="cert-line cert-fields-line">
        <table class="cert-fields">
            <tr>
                <td class="cert-label" data-ppx-cert="member-id" @unless ($showMemberId) hidden @endunless>MEMBER ID</td>
                <td class="cert-label" data-ppx-cert="join-date" @unless ($showJoinDate) hidden @endunless>MEMBER SINCE</td>
                @if ($validThrough)
                    <td class="cert-label">VALID THROUGH</td>
                @endif
            </tr>
            <tr>
                <td class="cert-value" data-ppx-cert="member-id" @unless ($showMemberId) hidden @endunless>{{ $memberId }}</td>
                <td class="cert-value" data-ppx-cert="join-date" @unless ($showJoinDate) hidden @endunless>{{ \Illuminate\Support\Carbon::parse($memberSince)->format('F Y') }}</td>
                @if ($validThrough)
                    <td class="cert-value" data-ppx-cert="valid-through">{{ \Illuminate\Support\Carbon::parse($validThrough)->format('M j, Y') }}</td>
                @endif
            </tr>
        </table>
    </div>

    {{-- Each signatory in their place, the date issued in an empty one, symmetrical around the seal. --}}
    @if ($signLeft)
        @include('pdf.certificate-sign', ['side' => 'left', 'sign' => $signLeft])
    @endif

    <div class="cert-seal"><img class="cert-seal-content" src="{{ $sealImage }}" alt="Official seal"></div>

    @include('pdf.certificate-sign', ['side' => 'right', 'sign' => $signRight])

    <div class="cert-hr"></div>
    <div class="cert-line cert-footer">
        <img class="cert-footer-icon" src="{{ $linkImage }}" alt="">@if ($issuedInFooter)Issued <span data-ppx-cert="issued">{{ $issuedOnLabel }}</span> ·@endif Verify at {{ parse_url($verificationUrl, PHP_URL_HOST) }}/verify · <span class="cert-footer-id">Certificate ID {{ $certificateCode }}</span>
    </div>

    <div class="cert-qr" data-ppx-cert="qr" @unless ($showQr) hidden @endunless>
        <img class="cert-qr-image" src="{{ $qrCode }}" alt="QR code to verify this certificate">
    </div>
</div>
