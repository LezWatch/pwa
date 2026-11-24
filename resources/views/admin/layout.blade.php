<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · {{ config('app.name') }} admin</title>
    <style>@include('admin.styles')</style>
</head>
<body>
    <header class="bar">
        <strong>{{ config('app.name') }} admin</strong>
        @if (session('admin.email'))
            <form method="post" action="{{ route('admin.logout') }}">
                @csrf
                <span class="muted">{{ session('admin.email') }}</span>
                <button type="submit" class="btn btn-quiet">Sign out</button>
            </form>
        @endif
    </header>
    <main>
        @yield('content')
    </main>
</body>
</html>
