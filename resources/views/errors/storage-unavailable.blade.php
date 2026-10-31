@extends('certificates.layout')

{{-- Document storage did not answer (App\Exceptions\StorageUnavailableException): no technical detail here. --}}
@section('title', 'Temporarily unavailable')

@section('content')
    <h1>Temporarily unavailable</h1>
    <div class="status expired"><span class="dot"></span>{{ $message }}</div>
    <p><a href="{{ url()->previous() !== url()->current() ? url()->previous() : url('/') }}">Go back</a></p>
@endsection
