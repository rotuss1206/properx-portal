{{--
    The certificate's page, shared by the PDF and the HTML preview. Data: CertificateGeneratorService::document().
    The PDF prints only what was chosen. The preview holds every part and public/portal/certificate.js shows or hides them.
--}}
<div @class(['cert', 'cert--qr' => $showQr])>
    @isset($backgroundImage)
        <img class="cert-background" src="{{ $backgroundImage }}" alt="">
    @endisset
    <div class="cert-sheet"></div>
    <div class="cert-frame"></div>
    <div class="cert-frame-gold"></div>

    <div class="cert-line cert-logo-line"><img class="cert-logo" src="{{ $logoImage }}" alt="ProPerX Alliance"></div>
    <div class="cert-line cert-title">CERTIFICATE OF MEMBERSHIP</div>
    <div class="cert-rule"></div>

    <div class="cert-line cert-lead">This certifies that</div>
    <div class="cert-line cert-name" style="font-size: {{ $nameSize }}pt;">{{ $memberName }}</div>
    <div class="cert-name-rule"></div>

    <div class="cert-text">
        is a recognized member in good standing of <span class="cert-strong">ProPerX Alliance</span>, entitled
        to all rights, benefits, and privileges of membership, having fulfilled all
        @if ($term)
            requirements for the <span class="cert-strong" data-ppx-cert="term">{{ $term }}</span> membership term.
        @else
            requirements of membership.
        @endif
    </div>

    <div class="cert-line cert-fields-line">
        <table class="cert-fields">
            <tr>
                @if ($preview || $showMemberId)
                    <td class="cert-label" @if ($preview) data-ppx-cert="member-id" @endif>MEMBER ID</td>
                @endif
                @if ($preview || $showJoinDate)
                    <td class="cert-label" @if ($preview) data-ppx-cert="join-date" @endif>MEMBER SINCE</td>
                @endif
                @if ($validThrough)
                    <td class="cert-label">VALID THROUGH</td>
                @endif
            </tr>
            <tr>
                @if ($preview || $showMemberId)
                    <td class="cert-value" @if ($preview) data-ppx-cert="member-id" @endif>{{ $memberId }}</td>
                @endif
                @if ($preview || $showJoinDate)
                    <td class="cert-value" @if ($preview) data-ppx-cert="join-date" @endif>{{ \Illuminate\Support\Carbon::parse($memberSince)->format('F Y') }}</td>
                @endif
                @if ($validThrough)
                    <td class="cert-value" data-ppx-cert="valid-through">{{ \Illuminate\Support\Carbon::parse($validThrough)->format('M j, Y') }}</td>
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

    <div class="cert-seal"><img class="cert-seal-content" src="{{ $sealImage }}" alt="Official seal"></div>

    {{-- The row stays symmetrical: the second signature, or the date of issue in its place. --}}
    <div class="cert-sign cert-sign-right">
        <div class="cert-sign-name">{{ $secondSignatoryName ?: $issuedOnLabel }}</div>
        <div class="cert-sign-rule"></div>
        <div class="cert-sign-title">{{ $secondSignatoryName ? $secondSignatoryTitle : 'Date issued' }}</div>
    </div>

    @if ($preview || $showQr)
        <div class="cert-qr" @if ($preview) data-ppx-cert="qr" @unless ($showQr) hidden @endunless @endif>
            <img class="cert-qr-image" src="{{ $qrCode }}" alt="QR code to verify this certificate">
        </div>
    @endif

    <div class="cert-hr"></div>
    <div class="cert-line cert-footer">
        <img class="cert-footer-icon" src="{{ $linkImage }}" alt="">@if ($secondSignatoryName)Issued {{ $issuedOnLabel }} · @endif Verify at {{ parse_url($verificationUrl, PHP_URL_HOST) }}/verify · <span class="cert-footer-id">Certificate ID {{ $certificateCode }}</span>
    </div>
</div>
