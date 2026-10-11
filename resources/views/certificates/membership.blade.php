<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Membership Certificate</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #1f2937; }
        .frame { position: absolute; top: 28px; right: 28px; bottom: 28px; left: 28px; border: 6px double #1e3a5f; padding: 48px 64px; text-align: center; }
        .org { font-size: 16px; letter-spacing: 4px; text-transform: uppercase; color: #1e3a5f; }
        h1 { font-size: 40px; margin: 18px 0 6px; color: #1e3a5f; }
        .lead { font-size: 15px; color: #4b5563; }
        .name { font-size: 32px; font-weight: bold; margin: 22px 0 4px; }
        .firm { font-size: 15px; color: #4b5563; }
        table.details { margin: 26px auto 0; border-collapse: collapse; font-size: 13px; }
        table.details td { padding: 4px 16px; }
        table.details td.label { color: #6b7280; text-align: right; }
        table.details td.value { text-align: left; font-weight: bold; }
        .signature { position: absolute; bottom: 70px; left: 64px; width: 300px; text-align: center; font-size: 13px; }
        .signature .line { border-top: 1px solid #1f2937; margin-bottom: 6px; }
        .verify { position: absolute; bottom: 70px; right: 64px; width: 300px; text-align: center; font-size: 12px; color: #4b5563; }
        .code { font-size: 16px; font-weight: bold; color: #1e3a5f; letter-spacing: 1px; }
    </style>
</head>
<body>
<div class="frame">
    <div class="org">ProPerx Alliance</div>
    <h1>Certificate of Membership</h1>
    <div class="lead">This certifies that</div>

    <div class="name">{{ $profile->fullName() }}</div>
    @if ($profile->firm_name)
        <div class="firm">{{ $profile->firm_name }}</div>
    @endif

    <div class="lead" style="margin-top: 18px;">is a member of ProPerx Alliance.</div>

    <table class="details">
        <tr><td class="label">Member ID</td><td class="value">{{ str_pad((string) $profile->user_id, 6, '0', STR_PAD_LEFT) }}</td></tr>
        @if ($profile->npn)
            <tr><td class="label">NPN</td><td class="value">{{ $profile->npn }}</td></tr>
        @endif
        <tr><td class="label">Member since</td><td class="value">{{ $memberSince->toFormattedDateString() }}</td></tr>
        <tr><td class="label">Valid through</td><td class="value">{{ $profile->license_expires_at?->toFormattedDateString() ?? '—' }}</td></tr>
        <tr><td class="label">Issued</td><td class="value">{{ $issuedAt->toFormattedDateString() }}</td></tr>
    </table>

    <div class="signature">
        <div class="line"></div>
        {{ $signatory?->name ?? 'Executive Director' }}<br>
        Executive Director, ProPerx Alliance
    </div>

    <div class="verify">
        Verification number<br>
        <span class="code">{{ $profile->certificate_code }}</span>
    </div>
</div>
</body>
</html>
