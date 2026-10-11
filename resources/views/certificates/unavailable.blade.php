@extends('certificates.layout')

@section('title', 'Certificate unavailable')

@section('content')
    <h1>Certificate unavailable</h1>
    <div class="status expired"><span class="dot"></span>{{ $message }}</div>
    <p>Please update your profile or contact the membership team.</p>
@endsection
