@extends('certificates.layout')

@section('title', 'Verify a certificate')

@section('content')
    <h1>Verify a membership certificate</h1>
    <p>Enter the Certificate ID printed at the bottom of the certificate.</p>

    <form class="verify-form" method="GET" action="{{ route('certificates.verify.form') }}">
        <label class="ppx-field">
            <span class="ppx-field__label">Certificate ID</span>
            <input class="ppx-field__input" type="text" name="code" value="{{ $code }}" maxlength="40" autocomplete="off" autocapitalize="characters" spellcheck="false" required autofocus @if ($error) aria-invalid="true" aria-describedby="verify-error" @endif>
        </label>
        @if ($error)
            <p class="ppx-field__error" id="verify-error">{{ $error }}</p>
        @endif
        <x-ppx.button type="submit" variant="primary">Verify</x-ppx.button>
    </form>
@endsection
