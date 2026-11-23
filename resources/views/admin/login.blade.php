@extends('admin.layout')

@section('title', 'Sign in')

@section('content')
    <h1>Sign in</h1>
    @if (session('admin.notice'))
        <p class="alert">{{ session('admin.notice') }}</p>
    @endif
    <p>Staff only. Sign in with your {{ config('admin.domain') }} Google account.</p>
    <p><a class="btn" href="{{ route('admin.google') }}">Sign in with Google</a></p>
@endsection
