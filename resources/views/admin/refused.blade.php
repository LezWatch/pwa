@extends('admin.layout')

@section('title', 'No access')

@section('content')
    <h1>No access</h1>
    <p class="alert alert-bad">{{ $reason }}</p>
    <p><a class="btn btn-quiet" href="{{ route('admin.login') }}">Back to sign in</a></p>
@endsection
