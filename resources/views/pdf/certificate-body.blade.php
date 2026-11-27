{{--
    The downloaded certificate, Letter portrait (docs/design/Certificate.jpg): gold corners, a diamond under the title,
    a round seal. Data: CertificateGeneratorService::viewData(); only the chosen parts are printed. The bottom row,
    symmetrical around the seal (pdf.certificate-sign): each signatory in their place with a line, the printed name
    under a signature image and the title, as in the preview; the date issued in an empty place, or in the line below
    with two signatures. The verification number is always printed, the QR at the right of that line when chosen.
    The member area's preview is a different design: member/certificate-preview.
--}}
<div @class(['cert', 'cert--qr' => $showQr])>
    <div class="cert-frame"></div>
    <div class="cert-frame-gold"></div>
    <div class="cert-corner cert-corner-left"></div>
    <div class="cert-corner cert-corner-right"></div>

    <div class="cert-line cert-logo-line"><img class="cert-logo" src="{{ $logoImage }}" alt="ProPerX Alliance"></div>
    <div class="cert-line cert-title">CERTIFICATE OF MEMBERSHIP</div>
    <div class="cert-ornament cert-ornament-left"></div>
    <img class="cert-diamond" src="{{ $diamondImage }}" alt="">
    <div class="cert-ornament cert-ornament-right"></div>

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

    @if ($showMemberId || $showJoinDate || $validThrough)
        <div class="cert-line cert-fields-line">
            <table class="cert-fields">
                <tr>
                    @if ($showMemberId)
                        <td class="cert-label">MEMBER ID</td>
                    @endif
                    @if ($showJoinDate)
                        <td class="cert-label">MEMBER SINCE</td>
                    @endif
                    @if ($validThrough)
                        <td class="cert-label">VALID THROUGH</td>
                    @endif
                </tr>
                <tr>
                    @if ($showMemberId)
                        <td class="cert-value">{{ $memberId }}</td>
                    @endif
                    @if ($showJoinDate)
                        <td class="cert-value">{{ \Illuminate\Support\Carbon::parse($memberSince)->format('F Y') }}</td>
                    @endif
                    @if ($validThrough)
                        <td class="cert-value">{{ \Illuminate\Support\Carbon::parse($validThrough)->format('M j, Y') }}</td>
                    @endif
                </tr>
            </table>
        </div>
    @endif

    @if ($signLeft)
        @include('pdf.certificate-sign', ['side' => 'left', 'sign' => $signLeft])
    @endif

    <div class="cert-seal"><img class="cert-seal-content" src="{{ $sealImage }}" alt="Official seal"></div>

    @include('pdf.certificate-sign', ['side' => 'right', 'sign' => $signRight])

    <div class="cert-line cert-footer">
        <img class="cert-footer-icon" src="{{ $linkImage }}" alt="">@if ($issuedInFooter)Issued {{ $issuedOnLabel }} ·@endif Verify authenticity at {{ parse_url($verificationUrl, PHP_URL_HOST) }}/verify · <span class="cert-footer-id">Certificate ID {{ $certificateCode }}</span>
    </div>

    @if ($showQr)
        <div class="cert-qr"><img class="cert-qr-image" src="{{ $qrCode }}" alt="QR code to verify this certificate"></div>
    @endif
</div>
