@extends('certificates.layout')

{{-- A download that cannot be served (App\Exceptions\DocumentDownloadException), outside the member area: no technical detail here. --}}
@section('title', $heading)

@section('content')
    <h1>{{ $heading }}</h1>
    <div class="status expired"><span class="dot"></span>{{ $message }}</div>
    <p><a href="{{ url()->previous() !== url()->current() ? url()->previous() : url('/') }}">Go back</a></p>
@endsection
