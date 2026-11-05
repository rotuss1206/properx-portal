{{-- The certificate's page, shared by the PDF and the HTML preview. Data: CertificateGeneratorService::document(). --}}
<div class="cert">
    <div class="cert-frame"></div>
    <div class="cert-frame-gold"></div>
    <div class="cert-corner cert-corner-left"></div>
    <div class="cert-corner cert-corner-right"></div>

    <div class="cert-line cert-logo-line"><img class="cert-logo" src="{{ $logoImage }}" alt="ProPerX Alliance"></div>
    <div class="cert-line cert-title">CERTIFICATE OF MEMBERSHIP</div>

    <div class="cert-rule cert-rule-left"></div>
    <div class="cert-line cert-diamond-line"><img class="cert-diamond" src="{{ $diamondImage }}" alt=""></div>
    <div class="cert-rule cert-rule-right"></div>

    <div class="cert-line cert-lead">This certifies that</div>
    <div class="cert-line cert-name" style="font-size: {{ $nameSize }}pt;">{{ $memberName }}</div>
    <div class="cert-name-rule"></div>

    <div class="cert-text">
        is a recognized member in good standing of <span class="cert-strong">ProPerX Alliance</span>, entitled
        to all rights, benefits, and privileges of membership, having fulfilled all
        @if ($term)
            requirements for the <span class="cert-strong">{{ $term }}</span> membership term.
        @else
            requirements of membership.
        @endif
    </div>

    <div class="cert-line cert-fields-line">
        <table class="cert-fields">
            <tr>
                <td class="cert-label">MEMBER ID</td>
                <td class="cert-label">MEMBER SINCE</td>
                @if ($validThrough)
                    <td class="cert-label">VALID THROUGH</td>
                @endif
            </tr>
            <tr>
                <td class="cert-value">{{ $memberId }}</td>
                <td class="cert-value">{{ \Illuminate\Support\Carbon::parse($memberSince)->format('F Y') }}</td>
                @if ($validThrough)
                    <td class="cert-value">{{ \Illuminate\Support\Carbon::parse($validThrough)->format('M j, Y') }}</td>
                @endif
            </tr>
        </table>
    </div>

    <div class="cert-sign cert-sign-left">
        @if ($signatureImage)
            <div class="cert-sign-name"><img class="cert-sign-image" src="{{ $signatureImage }}" alt="Signature of {{ $signatoryName }}"></div>
        @else
            <div class="cert-sign-name">{{ $signatoryName }}</div>
        @endif
        <div class="cert-sign-rule"></div>
        <div class="cert-sign-title">{{ $signatoryTitle }}</div>
    </div>

    <div class="cert-seal">
        <img class="cert-seal-icon" src="{{ $sealImage }}" alt="">
        <div class="cert-seal-text">OFFICIAL SEAL</div>
    </div>

    @if ($secondSignatoryName)
        <div class="cert-sign cert-sign-right">
            <div class="cert-sign-name">{{ $secondSignatoryName }}</div>
            <div class="cert-sign-rule"></div>
            <div class="cert-sign-title">{{ $secondSignatoryTitle }}</div>
        </div>
    @endif

    <div @class(['cert-qr', 'cert-qr-centered' => $secondSignatoryName])>
        <img class="cert-qr-image" src="{{ $qrCode }}" alt="QR code to verify this certificate">
        <div class="cert-qr-text">Scan to verify</div>
    </div>

    <div class="cert-line cert-footer">
        <img class="cert-footer-icon" src="{{ $linkImage }}" alt="">Verify at {{ preg_replace('#^https?://#', '', $verificationUrl) }}
    </div>
</div>
