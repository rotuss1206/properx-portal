@extends('certificates.layout')

@section('title', 'Certificate verification')

@section('content')
    <h1>Membership certificate verification</h1>

    @if ($status === 'valid')
        <div class="status valid"><span class="dot"></span>Valid certificate</div>
    @elseif ($status === 'expired')
        <div class="status expired"><span class="dot"></span>This certificate has expired</div>
    @elseif ($status === 'inactive')
        <div class="status expired"><span class="dot"></span>Membership is not active</div>
    @else
        <div class="status invalid"><span class="dot"></span>This certificate could not be verified</div>
    @endif

    @if ($certificate)
        <dl>
            <dt>Member</dt><dd>{{ $certificate['name'] }}</dd>
            <dt>Valid from</dt><dd>{{ $certificate['valid_from'] }}</dd>
            <dt>Valid through</dt><dd>{{ $certificate['valid_through'] }}</dd>
            <dt>Certificate no.</dt><dd>{{ $certificate['code'] }}</dd>
        </dl>
    @else
        <p>Check that the verification number <code>{{ $code }}</code> was entered exactly as printed on the certificate.
            If the problem persists, contact ProPerx Alliance.</p>
    @endif

    <div class="footer">Checked {{ now()->toDayDateTimeString() }} UTC. This page confirms membership status only.</div>
@endsection
