<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Membership Certificate {{ $certificateCode }}</title>
    {{-- US Letter landscape: 792 × 612 pt. Fixed pt coordinates keep dompdf output on one page. --}}
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #1f2937; }
        .frame { position: absolute; top: 22pt; left: 22pt; width: 736pt; height: 556pt; border: 5pt double #1e3a5f; }
        .inner { position: absolute; top: 6pt; left: 6pt; width: 722pt; height: 542pt; border: 0.75pt solid #c8a24a; text-align: center; }
        .org { margin-top: 34pt; font-size: 11pt; letter-spacing: 4pt; text-transform: uppercase; color: #1e3a5f; }
        h1 { font-size: 30pt; margin: 10pt 0 4pt; color: #1e3a5f; font-weight: normal; }
        .lead { font-size: 11pt; color: #4b5563; }
        .name { font-size: 26pt; font-weight: bold; margin: 16pt 0 2pt; color: #111827; }
        .firm { font-size: 11pt; color: #4b5563; }
        table.details { margin: 16pt auto 0; border-collapse: collapse; font-size: 10pt; }
        table.details td { padding: 2pt 10pt; }
        table.details td.label { color: #6b7280; text-align: right; }
        table.details td.value { text-align: left; font-weight: bold; }
        .signature { position: absolute; top: 405pt; left: 56pt; width: 230pt; text-align: center; font-size: 9pt; }
        .signature img { height: 50pt; }
        .signature .blank { height: 50pt; }
        .signature .line { border-top: 0.75pt solid #1f2937; margin: 2pt 0 4pt; }
        .verify { position: absolute; top: 382pt; left: 436pt; width: 230pt; text-align: center; font-size: 8pt; color: #4b5563; }
        .verify img { width: 78pt; height: 78pt; }
        .code { font-size: 12pt; font-weight: bold; color: #1e3a5f; letter-spacing: 1pt; }
    </style>
</head>
<body>
<div class="frame">
    <div class="inner">
        <div class="org">ProPerx Alliance</div>
        <h1>Certificate of Membership</h1>
        <div class="lead">This certifies that</div>

        <div class="name">{{ $memberName }}</div>
        @if ($firmName)
            <div class="firm">{{ $firmName }}</div>
        @endif

        <div class="lead" style="margin-top: 12pt;">is a member in good standing of ProPerx Alliance.</div>

        <table class="details">
            <tr><td class="label">Member ID</td><td class="value">{{ $memberId }}</td></tr>
            <tr><td class="label">Valid from</td><td class="value">{{ \Illuminate\Support\Carbon::parse($validFrom)->toFormattedDateString() }}</td></tr>
            <tr><td class="label">Valid through</td><td class="value">{{ \Illuminate\Support\Carbon::parse($validThrough)->toFormattedDateString() }}</td></tr>
            <tr><td class="label">Issued</td><td class="value">{{ $issuedAt->toFormattedDateString() }}</td></tr>
        </table>

        <div class="signature">
            @if ($signatureImage)
                <img src="{{ $signatureImage }}" alt="Signature">
            @else
                <div class="blank"></div>
            @endif
            <div class="line"></div>
            <strong>{{ $signatoryName }}</strong><br>
            Executive Director, ProPerx Alliance
        </div>

        <div class="verify">
            <img src="{{ $qrCode }}" alt="Verification QR code"><br>
            Verification number<br>
            <span class="code">{{ $certificateCode }}</span><br>
            Scan the code or visit<br>{{ parse_url($verificationUrl, PHP_URL_HOST) }}/verify-certificate
        </div>
    </div>
</div>
</body>
</html>
